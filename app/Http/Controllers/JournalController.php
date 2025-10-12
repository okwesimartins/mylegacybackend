<?php

namespace App\Http\Controllers;

use App\Models\Journals;
use App\Models\JournalEntry;
use App\Models\JournalAttachment;
use App\Models\Journaltemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use JWTAuth;

class JournalController extends Controller
{
    /**
     * Create or Update a journal CONTAINER (name/template).
     * Entries are created via saveJournalEntry().
     */
    private function flushAllLaravelCaches(): void
    {
        try {
            Cache::flush();
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            Artisan::call('cache:clear');
            Artisan::call('optimize:clear');

            @File::deleteDirectory(storage_path('framework/cache/data'));
            @File::deleteDirectory(storage_path('framework/views'));
            @File::ensureDirectoryExists(storage_path('framework/cache/data'));
            @File::ensureDirectoryExists(storage_path('framework/views'));
        } catch (\Throwable $e) {
            \Log::warning('flushAllLaravelCaches failed', ['error' => $e->getMessage()]);
        }
    }

    // get journal template
    public function getJournaltemplate(Request $request)
    {
        $templates = Journaltemplate::get();
        return response()->json($templates);
    }

    /**
     * Create/Update a Journal (container).
     * (Old saveJournal repurposed to only manage the journal shell.)
     */
    public function saveJournal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $validator = Validator::make($request->all(), [
            'id'          => 'nullable|integer|exists:journals,id',
            'name'        => 'required|string|max:255',
            'template_id' => 'required|integer|exists:journal_template,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>422,'errors'=>$validator->errors()], 422);
        }

        $journal = Journals::updateOrCreate(
            ['id' => $request->id ?? 0, 'user_id' => $userId],
            [
                'template_id'=> $request->template_id,
                'name'       => Crypt::encryptString($request->name),
            ]
        );

        return response()->json([
            'status'     => 200,
            'message'    => 'Journal saved successfully',
            'journal_id' => $journal->id,
        ]);
    }

    /**
     * Create or Update a JOURNAL ENTRY (with multiple attachments of any type).
     * Accepts: journal_id, entry_id (optional for update), title, text, date, attachments[] (any files).
     * All files are encrypted-at-rest and must be streamed via streamAttachment().
     */
    public function saveJournalEntry(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $validator = Validator::make($request->all(), [
            'journal_id'      => 'required|integer|exists:journals,id',
            'entry_id'        => 'nullable|integer|exists:journal_entries,id',
            'title'           => 'nullable|string|max:255',
            'text'            => 'nullable|string',
            'date'            => 'nullable|date',
            // multiple attachments allowed; expand types as needed
            'attachments'     => 'nullable|array',
            'attachments.*'   => 'file|max:20480', // 20MB each; tighten types with mimes if you want
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>422,'errors'=>$validator->errors()], 422);
        }

        // Ensure journal belongs to user
        $journal = Journals::where('id', $request->journal_id)
            ->where('user_id', $userId)
            ->first();

        if (!$journal) {
            return response()->json(['status'=>403,'message'=>'forbidden'], 403);
        }

        $encryptedText = $request->text ? Crypt::encryptString($request->text) : null;
        $encryptedtitle = $request->title ? Crypt::encryptString($request->title) : null;
        DB::beginTransaction();
        try {
            // create or update entry
            $entry = JournalEntry::updateOrCreate(
                ['id' => $request->entry_id ?? 0, 'journal_id' => $journal->id],
                [
                    'title' => $encryptedtitle,
                    'text'  => $encryptedText,
                    'date'  => $request->date ?? now(),
                ]
            );

            // attachments (any file type)
            if ($request->hasFile('attachments')) {
                $destinationPath = storage_path('app/journal_attachments'); // NOT public
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                foreach ($request->file('attachments') as $file) {
                    if (!$file->isValid()) continue;

                    $ext          = strtolower($file->getClientOriginalExtension() ?: 'bin');
                    $storedName   = uniqid('att_') . '.' . $ext;
                    $filePath     = $destinationPath . DIRECTORY_SEPARATOR . $storedName;
                    $originalName = $file->getClientOriginalName();
                    $mime         = $file->getClientMimeType();
                    $size         = $file->getSize();

                    // Encrypt and write
                    $content = file_get_contents($file->getRealPath());
                    $encryptedContent = Crypt::encrypt($content);
                    file_put_contents($filePath, $encryptedContent);

                    JournalAttachment::create([
                        'entry_id'      => $entry->id,
                        'stored_name'   => $storedName,
                        'original_name' => $originalName,
                        'mime'          => $mime,
                        'size'          => $size,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status'   => 200,
                'message'  => $request->entry_id ? 'Entry updated' : 'Entry created',
                'entry_id' => $entry->id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('saveJournalEntry failed', ['error' => $e->getMessage()]);
            return response()->json(['status'=>500,'message'=>'save_failed','error' => $e->getMessage()], 500);
        }
    }

    /**
     * List user's journals, plus counts & last entry date.
     */
    public function getJournals()
    {
        $this->flushAllLaravelCaches();
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $journals = Journals::where('user_id', $userId)
            ->withCount('entries')
            ->with(['entries' => function($q){
                $q->orderByDesc('date')->limit(1);
            }])
            ->orderByDesc('id')
            ->get();

        $result = $journals->map(function ($j) {
            $gettemplate = Journaltemplate::where("id",$j->template_id)->first();
            $last = $j->entries->first();

            return [
                'id'              => $j->id,
                'template'        => $gettemplate,
                'name'            => $j->name ? Crypt::decryptString($j->name) : null,
                'entries_count'   => $j->entries_count ?? 0,
                'last_entry_date' => $last?->date,
            ];
        });

        return response()->json(['status' => 200, 'data' => $result])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Get entries for a given journal, including signed URLs for attachments.
     */
    public function getJournalEntries(Request $request, $journalId)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $journal = Journals::where('id', $journalId)->where('user_id', $userId)->first();
        if (!$journal) {
            return response()->json(['status'=>404,'message'=>'not_found'], 404);
        }

        $entries = JournalEntry::where('journal_id', $journal->id)
            ->with('attachments')
            ->orderByDesc('date')
            ->get();

        $now     = time();
        $expires = $now + 60 * 15; // 15-minute signed link

        $result = $entries->map(function($e) use ($userId, $expires) {
            $attachments = $e->attachments->map(function($a) use ($e, $userId, $expires) {
                $sig = $this->makeAttachmentSig($e->id, $a->id, $userId, $expires);
                return [
                    'id'            => $a->id,
                    'original_name' => $a->original_name,
                    'mime'          => $a->mime,
                    'size'          => $a->size,
                    'url'           => url('/api/journals/attachment/' . $a->id . '?sig=' . $sig . '&exp=' . $expires),
                ];
            });

            return [
                'id'    => $e->id,
                'title' => $e->title ? Crypt::decryptString($e->title) : null,
                'text'  => $e->text ? Crypt::decryptString($e->text) : null,
                'date'  => $e->date,
                'attachments' => $attachments,
            ];
        });

        return response()->json(['status'=>200,'data'=>$result]);
    }

    /**
     * Securely stream ANY attachment (decrypt on-the-fly).
     * Route: GET /api/journals/attachment/{attachmentId}?sig=...&exp=...
     */
    public function streamAttachment(Request $request, $attachmentId)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $sig = $request->query('sig');
        $exp = (int) $request->query('exp', 0);

        if (!$sig || !$exp || $exp < time()) {
            return response()->json(['status' => 403, 'message' => 'invalid_or_expired_signature'], 403);
        }

        $attachment = JournalAttachment::with('entry.journal')->find($attachmentId);
        if (!$attachment) {
            return response()->json(['status' => 404, 'message' => 'not_found'], 404);
        }

        // Ensure ownership
        if ($attachment->entry->journal->user_id !== $userId) {
            return response()->json(['status' => 403, 'message' => 'forbidden'], 403);
        }

        // Validate signature
        if (!hash_equals($this->makeAttachmentSig($attachment->entry->id, $attachment->id, $userId, $exp), $sig)) {
            return response()->json(['status' => 403, 'message' => 'invalid_signature'], 403);
        }

        $filePath = storage_path('app/journal_attachments/' . $attachment->stored_name);
        if (!file_exists($filePath)) {
            return response()->json(['status' => 404, 'message' => 'file_missing'], 404);
        }

        try {
            $encrypted = file_get_contents($filePath);
            $decrypted = Crypt::decrypt($encrypted);
        } catch (\Throwable $e) {
            return response()->json(['status' => 500, 'message' => 'decrypt_failed'], 500);
        }

        $mime = $attachment->mime ?: 'application/octet-stream';

        return Response::make($decrypted, 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => strlen($decrypted),
            'Content-Disposition' => 'inline; filename="' . basename($attachment->original_name) . '"',
            'Cache-Control'       => 'private, max-age=0, no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    /**
     * HMAC signature to lock an attachment URL to this user/entry/attachment, with expiry.
     */
    private function makeAttachmentSig($entryId, $attachmentId, $userId, $expires): string
    {
        $secret = config('app.key'); // uses APP_KEY
        $payload = $entryId . '|' . $attachmentId . '|' . $userId . '|' . $expires;
        return hash_hmac('sha256', $payload, $secret);
    }
}
