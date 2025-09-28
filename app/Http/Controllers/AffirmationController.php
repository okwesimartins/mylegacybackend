<?php

namespace App\Http\Controllers;

use App\Models\AffirmationCategory;
use App\Models\UserAffirmationPref;
use App\Models\AffirmationInstance;
use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FirebasePushService;
use Carbon\Carbon;

class AffirmationController extends Controller
{
    /**
     * GET /api/affirmations/categories
     */
    public function listCategories()
    {
        $cats = AffirmationCategory::select('id','name','slug','description','image_link')->orderBy('name')->get();
        return response()->json(['data' => $cats]);
    }

    /**
     * POST /api/affirmations/prefs
     * Body: { "categories": [ { "category_id": 1, "times_per_day": 3, "day_start":"08:00", "day_end":"20:00" }, ... ] }
     */
    public function saveUserPrefs(Request $request)
    {
        $v = Validator::make($request->all(), [
            'categories'               => 'required|array|min:1',
            'categories.*.category_id' => 'required|integer|exists:affirmations,id',
            'categories.*.times_per_day' => 'required|integer|min:1|max:9',
        ]);
        if ($v->fails()) return response()->json(['errors'=>$v->errors()],422);

        $userId = auth()->id();

        DB::transaction(function () use ($request, $userId) {
            foreach ($request->categories as $pref) {
                UserAffirmationPref::updateOrCreate(
                    ['user_id'=>$userId, 'category_id'=>$pref['category_id']],
                    [
                        'times_per_day' => $pref['times_per_day'],
                        'active'        => 1
                    ]
                );
            }
        });

        return response()->json(['message'=>'Preferences saved']);
    }

    /**
     * POST /api/devices/token
     * Body: { "fcm_token": "...", "platform": "android|ios|web" }
     */
    public function saveDeviceToken(Request $request)
    {
        $v = Validator::make($request->all(), [
            'fcm_token' => 'required|string',
            'platform'  => 'nullable|string|max:20'
        ]);
        if ($v->fails()) return response()->json(['errors'=>$v->errors()],422);

        UserDeviceToken::updateOrCreate(
            ['user_id'=>auth()->id(), 'fcm_token'=>$request->fcm_token],
            ['platform'=>$request->platform ?? 'unknown']
        );
        return response()->json(['message'=>'Token saved']);
    }

    /**
     * POST /api/affirmations/generate-and-schedule
     * Body: { "date": "2025-09-26" (optional, default today) }
     *
     * Calls AI (Cloud Run) to generate texts per active category,
     * schedules N texts per category across the user’s window.
     */
    // public function generateAndScheduleForUser(Request $request)
    // {
    //     $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today(config('app.timezone') ?: 'Africa/Lagos');
    //     $userId = auth()->id();

    //     // prefs
    //     $prefs = UserAffirmationPref::where('user_id',$userId)->where('active',1)->get();
    //     if ($prefs->isEmpty()) return response()->json(['message'=>'No active categories set'], 400);

    //     // avoid duplicates for this date
    //     $already = AffirmationInstance::where('user_id',$userId)
    //         ->whereDate('scheduled_at', $date->toDateString())
    //         ->count();
    //     if ($already > 0) {
    //         return response()->json(['message'=>'Schedule for this date already exists'], 409);
    //     }

    //     // build categories for AI
    //     $catIds = $prefs->pluck('category_id')->all();
    //     $cats   = AffirmationCategory::whereIn('id',$catIds)->get(['id','name','slug']);
    //     $countPerCategory = max(1, $prefs->max('times_per_day'));

    //     // call AI service
    //     $aiResp = $this->callAiService($cats->toArray(), $countPerCategory);
    //     if ($aiResp['error'] ?? false) {
    //         return response()->json(['message'=>'AI service error', 'detail'=>$aiResp['error']], 502);
    //     }
    //     // aiResp: [ {category_id, items: [ "text1", "text2", ... ] }, ... ]

    //     // Insert schedules
    //     $created = 0;
    //     DB::transaction(function () use ($prefs, $cats, $aiResp, $date, $userId, &$created) {
    //         $byCat = collect($aiResp)->keyBy('category_id');

    //         foreach ($prefs as $pref) {
    //             $catId = $pref->category_id;

    //             $items = $byCat[$catId]['items'] ?? [];
    //             // Ensure we have at least times_per_day texts
    //             if (count($items) < $pref->times_per_day) {
    //                 // pad by repeating if AI returned fewer
    //                 while (count($items) < $pref->times_per_day) $items[] = $items[array_rand($items)] ?? 'You are loved and guided.';
    //             }

    //             $slots = $this->computeSchedule($date, $pref->day_start, $pref->day_end, $pref->times_per_day, $catId);
    //             for ($i=0; $i < count($slots); $i++) {
    //                 AffirmationInstance::create([
    //                     'user_id'        => $userId,
    //                     'category_id'    => $catId,
    //                     'text'           => $items[$i],
    //                     'scheduled_at'   => $slots[$i],
    //                     'sent_at'        => null,
    //                     'dispatch_status'=> 'pending',
    //                     'meta'           => json_encode(['source'=>'ai','category_name'=>$cats->firstWhere('id',$catId)->name ?? null])
    //                 ]);
    //                 $created++;
    //             }
    //         }
    //     });

    //     return response()->json(['message'=>'Scheduled', 'created'=>$created]);
    // }

    /**
     * GET /cron/affirmations/generate-today
     * For ALL users with active prefs: if today’s schedule missing, generate.
     * IONOS cron can hit this daily at 00:05.
     */
    public function cronGenerateToday()
    {
        $tz   = config('app.timezone') ?: 'Africa/Lagos';
        $date = Carbon::today($tz);

        // Users with active prefs
        $userIds = UserAffirmationPref::where('active',1)->distinct()->pluck('user_id');

        $createdTotals = 0;

        foreach ($userIds as $uid) {
            $has = AffirmationInstance::where('user_id',$uid)
                ->whereDate('scheduled_at',$date->toDateString())
                ->exists();
            if ($has) continue;

            $prefs = UserAffirmationPref::where('user_id',$uid)->where('active',1)->get();
            if ($prefs->isEmpty()) continue;

            $catIds = $prefs->pluck('category_id')->all();
            $cats   = AffirmationCategory::whereIn('id',$catIds)->get(['id','name','slug']);
            $countPerCategory = max(1, $prefs->max('times_per_day'));

            $aiResp = $this->callAiService($cats->toArray(), $countPerCategory);
            if ($aiResp['error'] ?? false) continue;

            DB::transaction(function () use ($prefs, $cats, $aiResp, $date, $uid, &$createdTotals) {
                $byCat = collect($aiResp)->keyBy('category_id');
                foreach ($prefs as $pref) {
                    $catId = $pref->category_id;
                    $items = $byCat[$catId]['items'] ?? [];
                    if (count($items) < $pref->times_per_day) {
                        while (count($items) < $pref->times_per_day) $items[] = $items[array_rand($items)] ?? 'You matter. Keep going.';
                    }
                    $slots = $this->computeSchedule($date, $pref->day_start, $pref->day_end, $pref->times_per_day, $catId);
                    for ($i=0; $i < count($slots); $i++) {
                        AffirmationInstance::create([
                            'user_id'        => $uid,
                            'category_id'    => $catId,
                            'text'           => $items[$i],
                            'scheduled_at'   => $slots[$i],
                            'sent_at'        => null,
                            'dispatch_status'=> 'pending',
                            'meta'           => json_encode(['source'=>'ai','category_name'=>$cats->firstWhere('id',$catId)->name ?? null])
                        ]);
                        $createdTotals++;
                    }
                }
            });
        }

        return response()->json(['message'=>'ok','created'=>$createdTotals]);
    }

    /**
     * GET /cron/affirmations/dispatch-due
     * IONOS cron: run every 5-15 minutes.
     */
   public function cronDispatchDue()
{
    $now = Carbon::now(config('app.timezone') ?: 'Africa/Lagos');
    $due = AffirmationInstance::where('dispatch_status','pending')
        ->where('scheduled_at','<=',$now)
        ->limit(500)
        ->get();

    $push = new FirebasePushService(); // simple instantiation

    $sent = 0;
    foreach ($due as $row) {
        $token = UserDeviceToken::where('user_id',$row->user_id)
            ->orderByDesc('id')
            ->value('fcm_token');

        if (!$token) {
            $row->dispatch_status = 'no_token';
            $row->sent_at = $now;
            $row->save();
            continue;
        }

        try {
            $ok = $push->sendToToken(
                $token,
                'Daily Affirmation',
                $row->text,
                ['instance_id' => (string)$row->id]
            );
        } catch (\Throwable $e) {
            $ok = false;
            \Log::error('FCM send error', ['e' => $e->getMessage(), 'instance' => $row->id]);
        }

        $row->dispatch_status = $ok ? 'sent' : 'error';
        $row->sent_at = $now;
        $row->save();
        if ($ok) $sent++;
    }

    return response()->json(['message' => 'ok', 'sent' => $sent, 'checked' => $due->count()]);
}

    /**
     * Spacing algorithm:
     * - Evenly divides [day_start, day_end] into N slots.
     * - Adds a small per-category minute offset (stagger).
     * - Adds +/- jitter up to 5 minutes to avoid thundering herd.
     */
    private function computeSchedule(Carbon $date, string $dayStart, string $dayEnd, int $timesPerDay, int $categoryId): array
    {
        $tz = config('app.timezone') ?: 'Africa/Lagos';

        $start = Carbon::parse($date->toDateString().' '.$dayStart, $tz);
        $end   = Carbon::parse($date->toDateString().' '.$dayEnd, $tz);
        if ($end->lte($start)) $end = $end->copy()->addDay(); // handle crossing midnight

        $totalSeconds = $end->diffInSeconds($start);
        $interval = (int) floor($totalSeconds / $timesPerDay);
        if ($interval < 300) $interval = 300; // min 5 minutes

        $slots = [];
        for ($i=0; $i<$timesPerDay; $i++) {
            $t = $start->copy()->addSeconds($i * $interval);

            // Stagger by category (mod 10 minutes), plus small jitter (+/- 2 minutes)
            $staggerMin = $categoryId % 10;
            $jitter     = rand(-120, 120); // seconds
            $t->addMinutes($staggerMin)->addSeconds($jitter);

            if ($t->gt($end)) $t = $end->copy()->subSeconds(30);
            $slots[] = $t->toDateTimeString();
        }
        sort($slots);
        return $slots;
    }

    /**
     * Call Cloud Run AI microservice
     * @param array $categories like [ ['id'=>1,'name'=>'Health','slug'=>'health'], ... ]
     * @return array e.g. [ ['category_id'=>1,'items'=>['...','...']], ... ]
     */
    private function callAiService(array $categories, int $countPerCategory): array
    {
        try {
            $url = 'https://us-central1-august-theme-472817-g3.cloudfunctions.net/mylegacyjournalsai/generate';
            $resp = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key'    => env('AI_SERVICE_KEY')
            ])->timeout(20)->post($url, [
                'categories'        => $categories,
                'countPerCategory'  => $countPerCategory,
                'tone'              => 'gentle, faith-infused, concise',
                'maxChars'          => 140
            ]);

            if (!$resp->successful()) {
                Log::error('AI service error', ['status'=>$resp->status(), 'body'=>$resp->body()]);
                return ['error'=>'upstream_error'];
            }
            $data = $resp->json();
            if (!is_array($data)) return ['error'=>'bad_json'];
            return $data;
        } catch (\Throwable $e) {
            Log::error('AI service exception', ['e'=>$e->getMessage()]);
            return ['error'=>'exception'];
        }
    }

    /**
     * Legacy FCM HTTP send (simple & works fine)
     */
    // private function sendPush(string $toToken, array $notification): bool
    // {
    //     $serverKey = env('FCM_SERVER_KEY');
    //     if (!$serverKey) return false;

    //     $payload = [
    //         'to' => $toToken,
    //         'notification' => [
    //             'title' => $notification['title'] ?? 'Notification',
    //             'body'  => $notification['body'] ?? '',
    //         ],
    //         'data' => [
    //             'type' => 'affirmation'
    //         ]
    //     ];

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
    //     curl_setopt($ch, CURLOPT_POST, true);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //         'Authorization: key='.$serverKey,
    //         'Content-Type: application/json'
    //     ]);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    //     $result = curl_exec($ch);
    //     $http   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     curl_close($ch);

    //     if ($http === 200) {
    //         $json = json_decode($result, true);
    //         return isset($json['success']) ? ($json['success'] >= 1) : true;
    //     }
    //     return false;
    // }
}
