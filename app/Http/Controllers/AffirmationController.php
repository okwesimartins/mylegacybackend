<?php

namespace App\Http\Controllers;

use App\Models\AffirmationCategory;
use App\Models\UserAffirmationPref;
use App\Models\AffirmationInstance;
use App\Models\UserDeviceToken;
use App\Models\User;
use App\Models\StaticAffirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FirebasePushService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use JWTAuth;
use Config;
use Auth;
use Tymon\JWTAuth\Exceptions\JWTException;

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
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

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
        $user = JWTAuth::parseToken()->authenticate();
        $userId = $user->id;

        UserDeviceToken::updateOrCreate(
            ['user_id'=>$userId, 'fcm_token'=>$request->fcm_token],
            ['platform'=>$request->platform ?? 'unknown']
        );
        return response()->json(['message'=>'Token saved']);
    }


// public function cronGenerateToday()
// {
//     $tz   = config('app.timezone') ?: 'Africa/Lagos';
//     $date = Carbon::today($tz);
//     $todayStr = $date->toDateString();

//     try {
//         $userIds = UserAffirmationPref::where('active', 1)
//             ->distinct()
//             ->pluck('user_id');

//         \Log::info('[AFFIRM CRON] start', [
//             'tz'         => $tz,
//             'date'       => $todayStr,
//             'user_count' => $userIds->count()
//         ]);

//         $createdTotals = 0;

//         foreach ($userIds as $uid) {
//             try {
//                 // ✅ KEY FIX: only check "do we already have *any* instances for today?"
//                 $has = AffirmationInstance::where('user_id', $uid)
//                     ->whereDate('scheduled_at', $todayStr)
//                     ->exists();

//                 \Log::info('[AFFIRM CRON] user check', [
//                     'user_id'   => $uid,
//                     'has_today' => $has,
//                 ]);

//                 if ($has) {
//                     // already generated today's schedule for this user
//                     continue;
//                 }

//                 $prefs = UserAffirmationPref::where('user_id', $uid)
//                     ->where('active', 1)
//                     ->get();

//                 if ($prefs->isEmpty()) {
//                     \Log::info('[AFFIRM CRON] user has no active prefs', ['user_id' => $uid]);
//                     continue;
//                 }

//                 $catIds = $prefs->pluck('category_id')->all();
//                 $cats   = AffirmationCategory::whereIn('id', $catIds)
//                     ->get(['id', 'name', 'slug']);

//                 // max times_per_day across this user's prefs
//                 $countPerCategory = max(1, (int) $prefs->max('times_per_day'));

//                 \Log::info('[AFFIRM CRON] pref summary', [
//                     'user_id'          => $uid,
//                     'pref_count'       => $prefs->count(),
//                     'cat_ids'          => $catIds,
//                     'countPerCategory' => $countPerCategory,
//                 ]);

//                 $aiResp = $this->callAiService($cats->toArray(), $countPerCategory);

//                 if (!is_array($aiResp)) {
//                     \Log::warning('[AFFIRM CRON] AI bad shape', [
//                         'user_id'     => $uid,
//                         'aiResp_type' => gettype($aiResp),
//                     ]);
//                     continue;
//                 }

//                 if ($aiResp['error'] ?? false) {
//                     \Log::warning('[AFFIRM CRON] AI error flag', [
//                         'user_id'  => $uid,
//                         'ai_error' => $aiResp['error'],
//                     ]);
//                     continue;
//                 }

//                 \Log::info('[AFFIRM CRON] AI raw', [
//                     'user_id' => $uid,
//                     'aiResp'  => $aiResp,
//                 ]);

//                 // Normalize AI response -> map of catId => [items...]
//                 $normMap = collect($aiResp)->mapWithKeys(function ($row) {
//                     $cidRaw = $row['category_id'] ?? null;
//                     if ($cidRaw === null) return [];

//                     $cid   = (int) $cidRaw;
//                     $items = $row['items'] ?? [];

//                     if (is_string($items)) {
//                         $items = preg_split("/\r?\n/", $items);
//                         $items = array_values(
//                             array_filter(
//                                 array_map('trim', $items),
//                                 fn ($s) => $s !== ''
//                             )
//                         );
//                     } elseif (!is_array($items)) {
//                         $items = $items ? [(string) $items] : [];
//                     }

//                     return [$cid => $items];
//                 });

//                 \Log::info('[AFFIRM CRON] normalized map', [
//                     'user_id' => $uid,
//                     'keys'    => $normMap->keys()->all(),
//                 ]);

//                 DB::transaction(function () use ($prefs, $cats, $date, $uid, $normMap, &$createdTotals) {
//                     $todayStrInner = $date->toDateString();

//                     foreach ($prefs as $pref) {
//                         $catId = (int) $pref->category_id;
//                         $tpd   = (int) $pref->times_per_day ?: 1;

//                         // extra defensive check per category per day (prevents weird duplicates)
//                         $existingCount = AffirmationInstance::where('user_id', $uid)
//                             ->where('category_id', $catId)
//                             ->whereDate('scheduled_at', $todayStrInner)
//                             ->count();

//                         if ($existingCount >= $tpd) {
//                             \Log::info('[AFFIRM CRON] skip category; already have enough for today', [
//                                 'user_id'      => $uid,
//                                 'category_id'  => $catId,
//                                 'tpd'          => $tpd,
//                                 'existing_cnt' => $existingCount,
//                             ]);
//                             continue;
//                         }

//                         $missing = $tpd - $existingCount;
//                         if ($missing <= 0) {
//                             continue;
//                         }

//                         $items = $normMap->get($catId, []);

//                         // pad/trim to at least $missing items
//                         if (empty($items)) {
//                             $items = ['You matter. Keep going.'];
//                         }

//                         // ensure we have at least $missing texts
//                         if (count($items) < $missing) {
//                             $i = 0;
//                             while (count($items) < $missing) {
//                                 $items[] = $items[$i % max(1, count($items))];
//                                 $i++;
//                             }
//                         } elseif (count($items) > $missing) {
//                             $items = array_slice($items, 0, $missing);
//                         }

//                         // compute schedule ONLY for missing slots
//                         $slots = $this->computeSchedule(
//                             $date,
//                             $pref->day_start,
//                             $pref->day_end,
//                             $missing,
//                             $catId
//                         );

//                         \Log::info('[AFFIRM CRON] schedule', [
//                             'user_id'     => $uid,
//                             'category_id' => $catId,
//                             'tpd'         => $tpd,
//                             'missing'     => $missing,
//                             'slots'       => $slots,
//                             'items'       => $items,
//                         ]);

//                         for ($i = 0; $i < count($slots); $i++) {
//                             $row = [
//                                 'user_id'         => $uid,
//                                 'category_id'     => $catId,
//                                 'text'            => $items[$i] ?? 'You are loved and guided.',
//                                 'scheduled_at'    => $slots[$i],
//                                 'sent_at'         => null,
//                                 'dispatch_status' => 'pending',
//                                 'meta'            => json_encode([
//                                     'source'        => 'ai',
//                                     'category_name' => optional($cats->firstWhere('id', $catId))->name,
//                                 ]),
//                             ];

//                             try {
//                                 AffirmationInstance::create($row);
//                                 $createdTotals++;
//                             } catch (\Throwable $ex) {
//                                 \Log::error('[AFFIRM CRON] insert failed', [
//                                     'user_id'     => $uid,
//                                     'category_id' => $catId,
//                                     'error'       => $ex->getMessage(),
//                                     'row'         => $row,
//                                 ]);
//                                 throw $ex;
//                             }
//                         }
//                     }
//                 });

//             } catch (\Throwable $inner) {
//                 \Log::error('[AFFIRM CRON] user loop error', [
//                     'user_id' => $uid,
//                     'error'   => $inner->getMessage(),
//                 ]);
//             }
//         }

//         \Log::info('[AFFIRM CRON] done', ['created' => $createdTotals]);
//         return response()->json(['message' => 'ok', 'created' => $createdTotals]);

//     } catch (\Throwable $e) {
//         \Log::error('[AFFIRM CRON] fatal', ['error' => $e->getMessage()]);
//         return response()->json([
//             'message' => 'error',
//             'error'   => $e->getMessage(),
//         ], 500);
//     }
// }

public function cronGenerateToday()
{
    $tz   = config('app.timezone') ?: 'Africa/Lagos';
    $date = Carbon::today($tz);
    $todayStr = $date->toDateString();

    try {
        $userIds = UserAffirmationPref::where('active', 1)
            ->distinct()
            ->pluck('user_id');

        \Log::info('[AFFIRM CRON] start', [
            'tz'         => $tz,
            'date'       => $todayStr,
            'user_count' => $userIds->count(),
        ]);

        $createdTotals = 0;

        foreach ($userIds as $uid) {
            try {
                // Silent skip if user already has any affirmation for today
                $hasToday = AffirmationInstance::where('user_id', $uid)
                    ->whereDate('scheduled_at', $todayStr)
                    ->exists();

                if ($hasToday) {
                    continue;
                }

                $prefs = UserAffirmationPref::where('user_id', $uid)
                    ->where('active', 1)
                    ->get();

                if ($prefs->isEmpty()) {
                    \Log::info('[AFFIRM CRON] user has no active prefs', ['user_id' => $uid]);
                    continue;
                }

                // Pick only ONE category for today
                $uniqueCatIds = $prefs->pluck('category_id')->unique()->values();

                if ($uniqueCatIds->isEmpty()) {
                    \Log::info('[AFFIRM CRON] user has no category ids', ['user_id' => $uid]);
                    continue;
                }

                $dayIndex = (int) $date->format('z');
                $selectedCatId = (int) $uniqueCatIds->get($dayIndex % $uniqueCatIds->count());

                $selectedPref = $prefs->firstWhere('category_id', $selectedCatId) ?: $prefs->first();

                $selectedCat = AffirmationCategory::where('id', $selectedCatId)
                    ->first(['id', 'name', 'slug']);

                if (!$selectedCat) {
                    \Log::warning('[AFFIRM CRON] selected category not found', [
                        'user_id'     => $uid,
                        'category_id' => $selectedCatId,
                    ]);
                    continue;
                }

                \Log::info('[AFFIRM CRON] selected daily category', [
                    'user_id'     => $uid,
                    'category_id' => $selectedCatId,
                    'category'    => $selectedCat->name,
                ]);

                // Generate only ONE affirmation for the selected category
                $aiResp = $this->callAiService([$selectedCat->toArray()], 1);

                if (!is_array($aiResp)) {
                    \Log::warning('[AFFIRM CRON] AI bad shape', [
                        'user_id'     => $uid,
                        'aiResp_type' => gettype($aiResp),
                    ]);
                    continue;
                }

                if ($aiResp['error'] ?? false) {
                    \Log::warning('[AFFIRM CRON] AI error flag', [
                        'user_id'  => $uid,
                        'ai_error' => $aiResp['error'],
                    ]);
                    continue;
                }

                \Log::info('[AFFIRM CRON] AI raw', [
                    'user_id' => $uid,
                    'aiResp'  => $aiResp,
                ]);

                // Normalize AI response -> map of catId => [items...]
                $normMap = collect($aiResp)->mapWithKeys(function ($row) {
                    $cidRaw = $row['category_id'] ?? null;
                    if ($cidRaw === null) {
                        return [];
                    }

                    $cid   = (int) $cidRaw;
                    $items = $row['items'] ?? [];

                    if (is_string($items)) {
                        $items = preg_split("/\r?\n/", $items);
                        $items = array_values(
                            array_filter(
                                array_map('trim', $items),
                                fn ($s) => $s !== ''
                            )
                        );
                    } elseif (!is_array($items)) {
                        $items = $items ? [(string) $items] : [];
                    }

                    return [$cid => $items];
                });

                $items = $normMap->get($selectedCatId, []);
                $text  = trim((string) ($items[0] ?? ''));

                if ($text === '') {
                    $text = 'You matter. Keep going.';
                }

                $slots = $this->computeSchedule(
                    $date,
                    $selectedPref->day_start,
                    $selectedPref->day_end,
                    1,
                    $selectedCatId
                );

                $scheduledAt = $slots[0] ?? $date->copy()->setTime(9, 0, 0)->toDateTimeString();

                DB::transaction(function () use (
                    $uid,
                    $todayStr,
                    $selectedCatId,
                    $selectedCat,
                    $text,
                    $scheduledAt,
                    &$createdTotals
                ) {
                    // Silent skip inside transaction too
                    $alreadyExists = AffirmationInstance::where('user_id', $uid)
                        ->whereDate('scheduled_at', $todayStr)
                        ->exists();

                    if ($alreadyExists) {
                        return;
                    }

                    $row = [
                        'user_id'         => $uid,
                        'category_id'     => $selectedCatId,
                        'text'            => $text,
                        'scheduled_at'    => $scheduledAt,
                        'sent_at'         => null,
                        'dispatch_status' => 'pending',
                        'meta'            => json_encode([
                            'source'        => 'ai',
                            'category_name' => $selectedCat->name,
                            'mode'          => 'one_per_day',
                        ]),
                    ];

                    AffirmationInstance::create($row);
                    $createdTotals++;

                    \Log::info('[AFFIRM CRON] created single daily affirmation', [
                        'user_id'      => $uid,
                        'category_id'  => $selectedCatId,
                        'scheduled_at' => $scheduledAt,
                    ]);
                });

            } catch (\Throwable $inner) {
                \Log::error('[AFFIRM CRON] user loop error', [
                    'user_id' => $uid,
                    'error'   => $inner->getMessage(),
                ]);
            }
        }

        \Log::info('[AFFIRM CRON] done', ['created' => $createdTotals]);

        return response()->json([
            'message' => 'ok',
            'created' => $createdTotals,
        ]);

    } catch (\Throwable $e) {
        \Log::error('[AFFIRM CRON] fatal', ['error' => $e->getMessage()]);

        return response()->json([
            'message' => 'error',
            'error'   => $e->getMessage(),
        ], 500);
    }
}





    /**
     * GET /cron/affirmations/dispatch-due
     * IONOS cron: run every 5-15 minutes.
     */
public function cronDispatchDue()
{
    $tz  = config('app.timezone') ?: 'Africa/Lagos';
    $now = Carbon::now($tz);

    // Only send items scheduled in a small window around "now"
    $windowStart = $now->copy()->subMinutes(10); // past 10 minutes
    $windowEnd   = $now->copy()->addMinute();    // tiny look-ahead

    $due = AffirmationInstance::where('dispatch_status', 'pending')
        ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
        ->orderBy('scheduled_at')
        ->limit(500)
        ->get();

    $push = new FirebasePushService();
    $sent = 0;

    try {
        foreach ($due as $row) {
            $token = UserDeviceToken::where('user_id', $row->user_id)
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
                    ['instance_id' => (string) $row->id]
                );
            } catch (\Throwable $e) {
                $ok = false;
                \Log::error('FCM send error', [
                    'e'        => $e->getMessage(),
                    'instance' => $row->id
                ]);
            }

            $row->dispatch_status = $ok ? 'sent' : 'error';
            $row->sent_at = $now;
            $row->save();

            if ($ok) {
                $sent++;
            }
        }

        // Optional: mark very old pending instances as expired so they never blast later
        $tooOldCutoff = $now->copy()->subHours(12);
        AffirmationInstance::where('dispatch_status', 'pending')
            ->where('scheduled_at', '<', $tooOldCutoff)
            ->update([
                'dispatch_status' => 'expired',
                'sent_at'         => $now,
            ]);

    } catch (\Throwable $e) {
        \Log::error('[AFFIRM DISPATCH] fatal', ['error' => $e->getMessage()]);
        return response()->json([
            'message' => 'Dispatch failed',
            'error'   => $e->getMessage()
        ], 500);
    }

    return response()->json([
        'message' => 'ok',
        'sent'    => $sent,
        'checked' => $due->count()
    ]);
}


public function cronGenerateAndDispatchStaticAffirmations()
{
    $tz  = config('app.timezone') ?: 'Africa/Lagos';
    $now = Carbon::now($tz);

    $sent = 0;
    $created = 0;
    $instanceIds = [];

    try {
        DB::beginTransaction();

        $current = StaticAffirmation::where('due', true)
            ->orderBy('prompt_order')
            ->lockForUpdate()
            ->first();

        if (!$current) {
            $current = StaticAffirmation::orderBy('prompt_order')
                ->lockForUpdate()
                ->first();

            if (!$current) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'message' => 'No static affirmations found'
                ], 404);
            }

            StaticAffirmation::query()->update(['due' => false]);
            $current->due = true;
            $current->save();
        }

        $lastGenerated = AffirmationInstance::where('meta->source', 'static_affirmation')
            ->orderByDesc('scheduled_at')
            ->lockForUpdate()
            ->first();

        if ($lastGenerated) {
            $lastAt = Carbon::parse($lastGenerated->scheduled_at, $tz);

            if ($lastAt->diffInHours($now) < 72) {
                DB::commit();

                return response()->json([
                    'message'              => 'Not due yet',
                    'next_run_after'       => $lastAt->copy()->addHours(72)->toDateTimeString(),
                    'current_prompt_order' => $current->prompt_order,
                ]);
            }
        }

        // Change this to match your actual free-plan field
        $users = User::where('account_status', 1)
            ->select('id')
            ->get();

        foreach ($users as $user) {
            $instance = AffirmationInstance::create([
                'user_id'         => $user->id,
                'category_id'     => null,
                'text'            => $current->affirmations_values,
                'scheduled_at'    => $now,
                'sent_at'         => null,
                'dispatch_status' => 'pending',
                'meta'            => [
                    'source'                => 'static_affirmation',
                    'static_affirmation_id' => $current->id,
                    'prompt_order'          => $current->prompt_order,
                    'theme'                 => $current->theme,
                ],
            ]);

            $instanceIds[] = $instance->id;
            $created++;
        }

        $next = StaticAffirmation::where('prompt_order', '>', $current->prompt_order)
            ->orderBy('prompt_order')
            ->lockForUpdate()
            ->first();

        if (!$next) {
            $next = StaticAffirmation::orderBy('prompt_order')
                ->lockForUpdate()
                ->first();
        }

        StaticAffirmation::query()->update(['due' => false]);

        if ($next) {
            $next->due = true;
            $next->save();
        }

        DB::commit();

        $push = new FirebasePushService();

        $due = AffirmationInstance::whereIn('id', $instanceIds)
            ->where('dispatch_status', 'pending')
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $row) {
            $token = UserDeviceToken::where('user_id', $row->user_id)
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
                    'Your journal is waiting',
                    $row->text,
                    [
                        'instance_id' => (string) $row->id,
                        'source'      => 'static_affirmation',
                        'prompt_order'=> (string) data_get($row->meta, 'prompt_order'),
                    ]
                );
            } catch (\Throwable $e) {
                $ok = false;
                Log::error('FCM send error for static affirmation', [
                    'error'    => $e->getMessage(),
                    'instance' => $row->id,
                    'user_id'  => $row->user_id,
                ]);
            }

            $row->dispatch_status = $ok ? 'sent' : 'error';
            $row->sent_at = $now;
            $row->save();

            if ($ok) {
                $sent++;
            }
        }

        return response()->json([
            'message'               => 'ok',
            'static_affirmation_id' => $current->id,
            'prompt_order'          => $current->prompt_order,
            'generated_instances'   => $created,
            'sent'                  => $sent,
            'next_prompt_order'     => $next?->prompt_order,
        ]);
    } catch (\Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        Log::error('[STATIC AFFIRMATION CRON] fatal', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'message' => 'Static affirmation dispatch failed',
            'error'   => $e->getMessage()
        ], 500);
    }
}

public function getNextStaticAffirmationForDispatch()
{
    $tz  = config('app.timezone') ?: 'Africa/Lagos';
    $now = Carbon::now($tz);

    try {
        $current = StaticAffirmation::where('due', true)
            ->orderBy('prompt_order')
            ->first();

        if (!$current) {
            $current = StaticAffirmation::orderBy('prompt_order')->first();

            if (!$current) {
                return response()->json([
                    'message' => 'No static affirmations found'
                ], 404);
            }

            // Do not update DB here.
            // We only want to infer what the next dispatch would be.
        }

        $lastGenerated = AffirmationInstance::where('meta->source', 'static_affirmation')
            ->orderByDesc('scheduled_at')
            ->first();

        // Default assumption: if nothing has ever been generated,
        // this current due item is dispatchable now.
        $nextDispatchAt = $now->copy();
        $status = 'due_now';
        $hoursRemaining = 0;

        if ($lastGenerated) {
            $lastAt = Carbon::parse($lastGenerated->scheduled_at, $tz);
            $candidateAt = $lastAt->copy()->addHours(72);

            if ($now->lt($candidateAt)) {
                $nextDispatchAt = $candidateAt;
                $status = 'scheduled';
                $hoursRemaining = (int) ceil($now->diffInMinutes($candidateAt) / 60);
            }
        }

        return response()->json([
            'message' => 'ok',
            'status'  => $status,
            'next_dispatch_at' => $nextDispatchAt->toDateTimeString(),
            'hours_remaining'  => $hoursRemaining,
            'affirmation' => [
                'id'                 => $current->id,
                'prompt_order'       => $current->prompt_order,
                'theme'              => $current->theme,
                'text'               => $current->affirmations_values,
                'due'                => (bool) $current->due,
            ],
            'last_generated_at' => $lastGenerated
                ? Carbon::parse($lastGenerated->scheduled_at, $tz)->toDateTimeString()
                : null,
        ]);
    } catch (\Throwable $e) {
        Log::error('[STATIC AFFIRMATION NEXT PREVIEW] fatal', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'message' => 'Failed to fetch next static affirmation',
            'error'   => $e->getMessage()
        ], 500);
    }
}
    /**
     * Spacing algorithm:
     * - Evenly divides [day_start, day_end] into N slots.
     * - Adds a small per-category minute offset (stagger).
     * - Adds +/- jitter up to 5 minutes to avoid thundering herd.
     */
private function computeSchedule(
    Carbon $date,
    string $dayStart,
    string $dayEnd,
    int $timesPerDay,
    int $categoryId
): array {
    $tz  = config('app.timezone') ?: 'Africa/Lagos';
    $now = Carbon::now($tz);

    // Base window for this "day"
    $start = Carbon::parse($date->toDateString() . ' ' . $dayStart, $tz);
    $end   = Carbon::parse($date->toDateString() . ' ' . $dayEnd, $tz);

    // Handle crossing midnight
    if ($end->lte($start)) {
        $end->addDay();
    }

    // If the whole window is already past, move schedule to tomorrow
    if ($end->lte($now)) {
        $start->addDay();
        $end->addDay();
    }

    // If we are currently inside the window, start from just after "now"
    if ($now->between($start, $end)) {
        $start = $now->copy()->addMinute(); // start 1 minute in the future
    }

    $totalSeconds = max(1, $end->diffInSeconds($start));
    $timesPerDay  = max(1, (int) $timesPerDay);

    $interval = (int) floor($totalSeconds / $timesPerDay);
    if ($interval < 300) {
        $interval = 300; // min 5 minutes between sends
    }

    $slots = [];
    for ($i = 0; $i < $timesPerDay; $i++) {
        $t = $start->copy()->addSeconds($i * $interval);

        // Stagger by category (0–9 mins) + jitter (~2 mins)
        $staggerMin = $categoryId % 10;
        $jitter     = random_int(-120, 120); // seconds

        $t->addMinutes($staggerMin)->addSeconds($jitter);

        // Don’t go beyond window end
        if ($t->gt($end)) {
            $t = $end->copy()->subSeconds(30);
        }

        // Final safety: never schedule in the past
        if ($t->lte($now)) {
            $t = $now->copy()->addMinutes(1 + $i);
        }

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
    $requestId = (string) Str::uuid();
    $url = 'https://us-central1-august-theme-472817-g3.cloudfunctions.net/mylegacyjournalsai/generate';

    $start = microtime(true);

    // Build the request in a way that works across Laravel versions
    $req = Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-api-key'    => 'hrennxbbbbzhyruuio4883jdnm-fhhfnnsmn485hnnmwnfh-ehhssBNDHejjn3',
            'x-request-id' => $requestId,
        ])
        ->timeout(90)                // total time for the request (secs)
        ->retry(2, 1000);            // 2 retries, 1000 ms backoff

    // Set TCP connect timeout in a backward-compatible way
    $req = method_exists($req, 'connectTimeout')
        ? $req->connectTimeout(10)   // newer Laravel versions
        : $req->withOptions(['connect_timeout' => 10]); // older versions

    try {
        $resp = $req->post($url, [
            'categories'        => array_values($categories),
            'countPerCategory'  => $countPerCategory,
            'tone'              => 'gentle, faith-infused, concise',
            'maxChars'          => 140,
        ]);

        $ms = (int) round((microtime(true) - $start) * 1000);
        Log::info('AI service timing', ['ms' => $ms, 'status' => $resp->status(), 'reqId' => $requestId]);

        if (!$resp->successful()) {
            Log::error('AI service error', [
                'status' => $resp->status(),
                'body'   => $resp->body(),
                'reqId'  => $requestId,
            ]);
            return ['error' => 'upstream_error'];
        }

        $data = $resp->json();
        return is_array($data) ? $data : ['error' => 'bad_json'];

    } catch (\Throwable $e) {
        Log::error('AI service exception', ['e' => $e->getMessage(), 'reqId' => $requestId]);
        return ['error' => 'exception'];
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
