<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use App\Models\User;
use App\Models\JournalNextOfKin;
use App\Models\Journals;
use App\Models\TriggerType;
use App\Mail\NextOfKinInviteMail;
use Tymon\JWTAuth\Facades\JWTAuth;

class JournalNextOfKinController extends Controller
{
    public function create(Request $r)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $data = $r->validate([
            'name'                 => 'required|string|max:120',
            'email'                => 'required|email',
            'relationship_type_id' => 'required|exists:relationship_types,id',
            'phone'                => 'nullable|string|max:40',
            'trigger_type_id'      => 'required|exists:trigger_types,id',
            'personal_message'     => 'nullable|string',
            'journal_ids'          => 'required|array|min:1',
            'journal_ids.*'        => 'integer|exists:journals,id',
            'passkey'              => 'required|string|min:6|max:32',
        ]);

        // Ensure journals belong to owner
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
            $this->sendInviteEmail($nok, null, false);
        }

        return response()->json([
            'status' => 201,
            'nok_id' => $nok->id,
            'message'=> 'created' . ($trigger && strtolower($trigger->kind)==='instant' ? ' (instant email queued)' : '')
        ], 201);
    }

    public function update(Request $r, $id)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $nok = JournalNextOfKin::where('id', $id)->where('user_id', $user->id)->first();
        if (!$nok) return response()->json(['status'=>404,'message'=>'not_found'], 404);

        $data = $r->validate([
            'name'                 => 'sometimes|string|max:120',
            'email'                => 'sometimes|email',
            'phone'                => 'nullable|string|max:40',
            'relationship_type_id' => 'sometimes|exists:relationship_types,id',
            'trigger_type_id'      => 'sometimes|exists:trigger_types,id',
            'personal_message'     => 'nullable|string',
            'journal_ids'          => 'sometimes|array|min:1',
            'journal_ids.*'        => 'integer|exists:journals,id',
            'passkey'              => 'sometimes|string|min:6|max:32',
        ]);

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
        return response()->json(['status'=>200,'message'=>'updated']);
    }

    // List for owner
    public function index(Request $r)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $list = JournalNextOfKin::with(['relationship','trigger','journals:id,title'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['status'=>200,'nok'=>$list]);
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

        // Build the full URL your email button should use
        $base = rtrim(env('NOK_LINK_BASE', 'https://mylegacyjournals.app/backend/links/nok/access'), '/');
        $deepLink = $base . '?invite=' . urlencode($invite);

        $journalMeta = $nok->journals()->select('id','title')->get()
            ->map(fn($j)=>['id'=>$j->id,'title'=>$j->title,'entries'=>$j->entries_count ?? null])
            ->values()->all();

        $payload = [
            'owner_name'   => $owner?->name ?? 'A loved one',
            'relationship' => $nok->relationship?->name ?? 'Family',
            'journal_meta' => $journalMeta,
            'deep_link'    => $deepLink,  // <-- email template should render this in the button
            // Only include passkey if you explicitly want to (usually NO)
            'passkey'      => $passkey,
            'message'      => $nok->personal_message,
        ];

        if (!$previewOnly) {
            Mail::to($nok->email)->queue(new NextOfKinInviteMail($payload));
            $nok->update(['delivered_at'=>now(),'status'=>'SENT']);
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
        $data = $r->validate([
            'invite'  => 'required|string',
            'passkey' => 'required|string',
        ]);

        $nok = JournalNextOfKin::where('invite_token', $data['invite'])->first();
        if (!$nok || !Hash::check($data['passkey'], $nok->passkey_hash)) {
            return response()->json(['status'=>401,'message'=>'invalid_credentials'], 401);
        }

        if ($nok->status !== 'ACCESSED') {
            $nok->update(['status' => 'ACCESSED']);
        }

        $journals = $nok->journals()->select('journals.id','journals.title')->get();

        return response()->json([
            'status'   => 200,
            'nok_id'   => $nok->id,
            'invite'   => $nok->invite_token,
            'journals' => $journals,
        ]);
    }
}
