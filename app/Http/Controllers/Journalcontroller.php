<?php

namespace App\Http\Controllers;

use App\Models\Journals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JWTAuth;

class JournalController extends Controller
{
    /**
     * Create or Update a journal
     */
    private function flushAllLaravelCaches(): void
{
    try {
        // App/cache stores
        Cache::flush();

        // Laravel compiled caches
        Artisan::call('config:clear');   // clears config cache
        Artisan::call('route:clear');    // clears route cache
        Artisan::call('view:clear');     // clears compiled views
        Artisan::call('cache:clear');    // clears app cache
        Artisan::call('optimize:clear'); // clears events, packages, services, etc.

        // (belt & suspenders) wipe cache directories if anything lingers
        @File::deleteDirectory(storage_path('framework/cache/data'));
        @File::deleteDirectory(storage_path('framework/views'));
        // Don’t delete the directories themselves—Laravel expects them to exist.
        @File::ensureDirectoryExists(storage_path('framework/cache/data'));
        @File::ensureDirectoryExists(storage_path('framework/views'));
    } catch (\Throwable $e) {
        \Log::warning('flushAllLaravelCaches failed', ['error' => $e->getMessage()]);
    }
}   

//get journal template
    public function getJournaltemplate(Request $request)
    {
       $templates = Journals::get();

         return response()->json($templates);
    }



    
    public function saveJournal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $validator = Validator::make($request->all(), [
            'id'    => 'nullable|integer|exists:journals,id',
            'name'  => 'required|string|max:255',
            'template_id'=>'required',
            'text'  => 'nullable|string',
            'audio' => 'nullable|file|mimes:mp3,wav,ogg|max:10240', // 10MB
            'date'  => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>422,'errors'=>$validator->errors()], 422);
        }

        $encryptedText = $request->text ? Crypt::encryptString($request->text) : null;

        $journal = Journals::updateOrCreate(
            ['id' => $request->id ?? 0, 'user_id' => $userId],
            [   'template_id'=> $request->template_id,
                'name' => Crypt::encryptString($request->name),
                'text' => $encryptedText,
                'date' => $request->date ?? now(),
            ]
        );

        // Handle audio upload (encrypt at rest)
        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            $ext = strtolower($file->getClientOriginalExtension());
            $filename = uniqid('audio_') . '.' . $ext;

            // Move to /public/audio (encrypted-at-rest)
            $destinationPath = public_path('audio');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file->move($destinationPath, $filename);

            // Encrypt file contents and overwrite
            $filePath = $destinationPath . DIRECTORY_SEPARATOR . $filename;
            $content = file_get_contents($filePath);
            $encryptedContent = Crypt::encrypt($content);
            file_put_contents($filePath, $encryptedContent);

            $journal->audio = $filename;
            $journal->save();
        }

        return response()->json([
            'status'      => 200,
            'message'     => 'Journal saved successfully',
            'journal_id'  => $journal->id
        ]);
    }

    /**
     * Return list of user's journals with a secure audio_url
     * (audio is streamed decrypted via /api/journals/audio/{id})
     */
    public function getJournals()
    {   
        $this->flushAllLaravelCaches();
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        $journals = Journals::where('user_id', $userId)
            ->orderByDesc('date')
            ->get();

        $result = $journals->map(function ($j) use ($userId) {
            // Build a signed URL to stream decrypted audio (if present)
            $audioUrl = null;
            if (!empty($j->audio)) {
                $sig = $this->makeAudioSig($j->id, $userId);
                // This is an API route we add below; it decrypts & streams
                $audioUrl = url('/api/journals/audio/' . $j->id . '?sig=' . $sig);
            }

            return [
                'id'        => $j->id,
                'template_id'=> $j->template_id,
                'name'      => $j->name ? Crypt::decryptString($j->name) : null,
                'text'      => $j->text ? Crypt::decryptString($j->text) : null,
                'date'      => $j->date,
                'audio_url' => $audioUrl,
            ];
        });

        return response()->json(['status' => 200, 'data' => $result])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
    ->header('Pragma', 'no-cache');;
    }

    /**
     * Securely stream a journal's audio (decrypt on-the-fly).
     * Route: GET /api/journals/audio/{id}?sig=...
     */
    public function streamAudio(Request $request, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        // Validate signature (prevents trivial guessing of URLs)
        $sig = $request->query('sig');
        if (!$sig || !hash_equals($this->makeAudioSig($id, $userId), $sig)) {
            return response()->json(['status' => 403, 'message' => 'invalid_signature'], 403);
        }

        // Ensure the journal belongs to the requesting user
        $journal = Journals::where('id', $id)->where('user_id', $userId)->first();
        if (!$journal) {
            return response()->json(['status' => 404, 'message' => 'not_found'], 404);
        }
        if (!$journal->audio) {
            return response()->json(['status' => 404, 'message' => 'no_audio'], 404);
        }

        $filePath = public_path('audio/' . $journal->audio);
        if (!file_exists($filePath)) {
            return response()->json(['status' => 404, 'message' => 'file_missing'], 404);
        }

        // Decrypt into memory
        try {
            $encrypted = file_get_contents($filePath);
            $decrypted = Crypt::decrypt($encrypted);
        } catch (\Throwable $e) {
            return response()->json(['status' => 500, 'message' => 'decrypt_failed'], 500);
        }

        // Infer content type from extension
        $ext = strtolower(pathinfo($journal->audio, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => 'application/octet-stream',
        };

        // Stream as inline audio
        return Response::make($decrypted, 200, [
            'Content-Type'              => $mime,
            'Content-Length'            => strlen($decrypted),
            'Content-Disposition'       => 'inline; filename="' . basename($journal->audio) . '"',
            'Cache-Control'             => 'private, max-age=0, no-cache, no-store, must-revalidate',
            'Pragma'                    => 'no-cache',
            'Expires'                   => '0',
            // (Optional) CORS if you need cross-origin media playback:
            // 'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * HMAC signature to lock the audio stream URL to this user & journal.
     * If you rotate APP_KEY, old links will naturally stop working.
     */
    private function makeAudioSig($journalId, $userId): string
    {
        $secret = config('app.key'); // uses APP_KEY
        return hash_hmac('sha256', $journalId.'|'.$userId, $secret);
    }
}
