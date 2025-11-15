<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\JournalNextOfKin;
use App\Models\Journals;
use App\Models\TriggerType;
use App\Mail\Nextofkininvitemail;
use App\Models\JournalEntry;
use Tymon\JWTAuth\Facades\JWTAuth;

class JournalNextOfKinController extends Controller
{
    public function createnexofkin(Request $r)
    {
        $user = JWTAuth::parseToken()->authenticate();


          $validator = Validator::make($r->all(), [
        'name'                 => 'required|string|max:120',
        'email'                => 'required|email',
        'relationship_type_id' => 'required|integer|exists:relationship_type,id', // <-- plural
        'phone'                => 'nullable|string|max:40',
        'trigger_type_id'      => 'required|integer|exists:trigger_type,id',     // <-- plural
        'personal_message'     => 'nullable|string',
        'journal_ids'          => 'required|array|min:1',
        'journal_ids.*'        => 'required|integer|distinct|exists:journals,id', // distinct helps
        'passkey'              => 'required|string|min:6|max:32',
    ]);

        
           if ($validator->fails()) {
        return response()->json([
            'status' => 422,
            'errors' => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();
        // // Ensure journals belong to owner
        $owned = Journals::whereIn('id', $data['journal_ids'])
            ->where('user_id', $user->id)
            ->count();
        if ($owned !== count($data['journal_ids'])) {
            return response()->json(['status'=>403,'message'=>'journal_ownership_mismatch'], 403);
        }

        // Create NOK + sync journals
        $nok = DB::transaction(function () use ($user, $data) {
            $nok = JournalNextOfKin::create([
                'user_id'             => $user->id,
                'name'                => $data['name'],
                'email'               => $data['email'],
                'phone'               => $data['phone'] ?? null,
                'relationship_type_id'=> $data['relationship_type_id'],
                'trigger_type_id'     => $data['trigger_type_id'],
                'personal_message'    => $data['personal_message'] ?? null,
                'passkey_hash'        => Hash::make($data['passkey']),
                'status'              => 'PENDING',
                // 'invite_token' is created lazily when emailing or on demand
            ]);
            $nok->journals()->sync($data['journal_ids']);
            return $nok;
        });

        // Send immediately for instant triggers
        $trigger = TriggerType::find($data['trigger_type_id']);
        if ($trigger && strtolower((string)$trigger->kind) === 'instant') {
            // Generate invite now and send the email
            $this->sendInviteEmail($nok, $data['passkey'], false);
        }

        return response()->json([
            'status' => 201,
            'nok_id' => $nok->id,
            'message'=> 'created' . ($trigger && strtolower($trigger->kind)==='instant' ? ' (instant email queued)' : '')
        ], 201);
    }

    public function updatenexofkin(Request $r, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $nok = JournalNextOfKin::where('id', $id)->where('user_id', $user->id)->first();
        if (!$nok) return response()->json(['status'=>404,'message'=>'not_found'], 404);

         $validator = Validator::make($r->all(), [
        'name'                 => 'required|string|max:120',
        'email'                => 'required|email',
        'relationship_type_id' => 'required|integer|exists:relationship_type,id', // <-- plural
        'phone'                => 'nullable|string|max:40',
        'trigger_type_id'      => 'required|integer|exists:trigger_type,id',     // <-- plural
        'personal_message'     => 'nullable|string',
        'journal_ids'          => 'required|array|min:1',
        'journal_ids.*'        => 'required|integer|distinct|exists:journals,id', // distinct helps
        'passkey'              => 'required|string|min:6|max:32',
    ]);

        
           if ($validator->fails()) {
        return response()->json([
            'status' => 422,
            'errors' => $validator->errors(),
        ], 422);
    }
     
      $data = $validator->validated();
        if (isset($data['journal_ids'])) {
            $owned = Journals::whereIn('id', $data['journal_ids'])
                ->where('user_id', $user->id)->count();
            if ($owned !== count($data['journal_ids'])) {
                return response()->json(['status'=>403,'message'=>'journal_ownership_mismatch'], 403);
            }
            $nok->journals()->sync($data['journal_ids']);
            unset($data['journal_ids']);
        }

        if (isset($data['passkey'])) {
            $data['passkey_hash'] = Hash::make($data['passkey']);
            unset($data['passkey']);
        }

        $nok->update($data);

         // Send immediately for instant triggers
        $trigger = TriggerType::find($data['trigger_type_id']);
        if ($trigger && strtolower((string)$trigger->kind) === 'instant') {
            // Generate invite now and send the email
            $this->sendInviteEmail($nok, $data['passkey'], false);
        }
        return response()->json(['status'=>200,'message'=>'updated']);
    }

    // List for owner
   public function getnexofkin(Request $r)
{
    $user = JWTAuth::parseToken()->authenticate();

    // Eager-load journals with id + name (encrypted), plus relationship/trigger
    $rows = JournalNextOfKin::with([
            'relationship',
            'trigger',
            'journals:id,name' // we'll decrypt "name" below
        ])
        ->where('user_id', $user->id)
        ->orderByDesc('id')
        ->get();

    // Transform to a clean payload: journals => [{id, title:<decrypted>}]
    $payload = $rows->map(function ($nok) {
        $journals = $nok->journals->map(function ($j) {
            // Decrypt each journal name safely
            try {
                $title = $j->name ? Crypt::decryptString($j->name) : '(untitled)';
            } catch (\Throwable $e) {
                $title = '[unable to decrypt]';
            }
            return [
                'id'    => $j->id,
                'title' => $title,   // only decrypted title is returned
            ];
        })->values();

        return [
            'id'               => $nok->id,
            'name'             => $nok->name,
            'email'            => $nok->email,
            'phone'            => $nok->phone,
            'relationship'     => $nok->relationship?->name,
            'trigger'          => $nok->trigger?->name,
            'trigger_type_id'  => $nok->trigger_type_id,
            'relationship_type_id' => $nok->relationship_type_id,
            'status'           => $nok->status,
            'delivered_at'     => $nok->delivered_at,
            'journals'         => $journals,
        ];
    })->values();

    return response()->json(['status' => 200, 'nok' => $payload]);
}

    // Ensure/create a permanent invite token on the NOK row
    private function ensureInviteToken(JournalNextOfKin $nok): string
    {
        if (!$nok->invite_token) {
            $nok->invite_token = Str::uuid()->toString(); // <-- fixed (no "new")
            $nok->save();
        }
        return $nok->invite_token;
    }

    // Compose & send email (uses your landing-page deep link)
  private function sendInviteEmail(JournalNextOfKin $nok, ?string $passkey = null, bool $previewOnly = false)
{
    $owner = $nok->user_id ? User::find($nok->user_id) : null;
    $invite = $this->ensureInviteToken($nok);

    $base = rtrim(env('NOK_LINK_BASE', 'https://mylegacyjournals.app/backend/links/nok/access'), '/');
    $deepLink = $base . '?invite=' . urlencode($invite);

    // Get all journals + decrypted entries
    $journalMeta = $nok->journals()
        ->select('id', 'name')
        ->get()
        ->map(function ($j) {
            // Get & decrypt entries
            $entries = JournalEntry::where('journal_id', $j->id)->get();
            $decodedEntries = [];

               try {
            $decodedName = $j->name ? Crypt::decryptString($j->name) : '(untitled)';
        } catch (\Throwable $e) {
            $decodedName = '[unable to decrypt]';
        }


            foreach ($entries as $entry) {
                try {
                    $decodedTitle = $entry->title ? Crypt::decryptString($entry->title) : '(untitled)';
                } catch (\Throwable $e) {
                    $decodedTitle = '[unable to decrypt]';
                }
                $decodedEntries[] = $decodedTitle;
            }

            return [
                'id'       => $j->id,
                'title'    => $decodedName,
                'entries'  => $decodedEntries, // now an array of decoded titles
            ];
        })
        ->values()
        ->all();

    $payload = [
        'owner_name'   => $owner?->name ?? 'A loved one',
        'relationship' => $nok->relationship?->name ?? 'Family',
        'journal_meta' => $journalMeta,
        'deep_link'    => $deepLink,
        'passkey'      => $passkey,
        'message'      => $nok->personal_message,
    ];

    if (!$previewOnly) {
        Mail::to($nok->email)->send(new Nextofkininvitemail($payload));
        $nok->update(['delivered_at' => now(), 'status' => 'SENT']);
    }
}

    public function destroy(Request $r, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $nok = JournalNextOfKin::where('id', $id)->where('user_id', $user->id)->first();

        if (!$nok) return response()->json(['status'=>404,'message'=>'not_found'], 404);

        if (method_exists($nok, 'journals')) {
            $nok->journals()->detach();
        }

        $nok->delete();
        return response()->json(['status'=>204,'message'=>'deleted'], 204);
    }

    // App access: permanent invite + passkey (no short-lived tokens)
public function access(Request $r)
{    
    $validator = Validator::make($r->all(), [
        'invite'  => 'required|string',
        'passkey' => 'required|string',
    ]);


    
           if ($validator->fails()) {
        return response()->json([
            'status' => 422,
            'errors' => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

    $nok = JournalNextOfKin::where('invite_token', $data['invite'])->first();
    if (!$nok || !Hash::check($data['passkey'], $nok->passkey_hash)) {
        return response()->json(['status'=>401,'message'=>'invalid_credentials'], 401);
    }

    if ($nok->status !== 'ACCESSED') {
        $nok->update(['status' => 'ACCESSED']);
    }

    // Fetch and DECRYPT each journal name
    $journals = $nok->journals()
        ->select('journals.id','journals.name')
        ->get()
        ->map(function ($j) {
            try {
                $decodedName = $j->name ? Crypt::decryptString($j->name) : '(untitled)';
            } catch (\Throwable $e) {
                $decodedName = '[unable to decrypt]';
            }
            return [
                'id'    => $j->id,
                'title' => $decodedName, // return as "title" for the app
            ];
        })
        ->values();

    return response()->json([
        'status'   => 200,
        'nok_id'   => $nok->id,
        'invite'   => $nok->invite_token,
        'journals' => $journals,
    ]);
}
}
