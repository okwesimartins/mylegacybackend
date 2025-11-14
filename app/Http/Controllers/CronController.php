<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\JournalNextOfKin;
use Carbon\Carbon;

class CronController extends Controller
{
    // GET /backend/api/cron/nok-dispatch?key=SECRET
    public function dispatchNoKInvites(Request $r)
    {
       

        $lastActiveColumn ='last_active_at';
        $sent = 0; $skipped = 0;

        JournalNextOfKin::with(['trigger','journals','relationship'])
            ->whereNull('delivered_at')                 // only those not yet emailed
            ->whereIn('status', ['PENDING','CREATED'])  // adjust to your statuses
            ->chunkById(200, function($rows) use (&$sent, &$skipped, $lastActiveColumn) {

                foreach ($rows as $nok) {
                    $trigger = $nok->trigger;
                    if (!$trigger) { $skipped++; continue; }

                    $kind = strtolower((string)$trigger->kind);

                    // Instant: send now if never delivered
                    if ($kind === 'instant') {
                        app(JournalNextOfKinController::class)->sendInviteEmail($nok, null, false);
                        $sent++; continue;
                    }

                    // Inactivity: wait until user's inactivity_days is reached
                    if ($kind === 'inactivity') {
                        $days = (int)($trigger->inactivity_days ?? 0);
                        if ($days <= 0) { $skipped++; continue; }

                        $user = User::find($nok->user_id);
                        if (!$user) { $skipped++; continue; }

                        $lastActive = $user->{$lastActiveColumn}
                                     ?? $user->last_login_at
                                     ?? $user->updated_at
                                     ?? $user->created_at;

                        if (!$lastActive) { $skipped++; continue; }

                        $due = Carbon::parse($lastActive)->lte(now()->subDays($days));
                        if ($due) {
                            app(JournalNextOfKinController::class)->sendInviteEmail($nok, null, false);
                            $sent++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    // Unknown kind -> skip
                    $skipped++;
                }
            });

        return response()->json(['status'=>200,'sent'=>$sent,'skipped'=>$skipped]);
    }
}
