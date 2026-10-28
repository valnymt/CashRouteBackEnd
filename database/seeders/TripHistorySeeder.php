<?php

namespace Database\Seeders;

use App\Models\CashRequest;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds completed trips spread across the past 2 business weeks so Reports
 * and Trip History have presentable, varied data. Only weekday dates are
 * used — the bank is closed on Saturday and Sunday, so no trips exist for
 * those days.
 *
 * Idempotent on reference — re-running skips rows that already exist.
 * Run with: php artisan db:seed --class=TripHistorySeeder
 */
class TripHistorySeeder extends Seeder
{
    public function run(): void
    {
        // [daysAgo, hour, type, branch_id, amount, team_id, durationMin, onTime]
        // All daysAgo values resolve to Mon–Fri (never Sat/Sun). Several rows
        // land in the current week so the "On-time vs late" per-day chart has
        // real data to render for today and the past few business days.
        $rows = [
            // ---- previous week ----
            [13, 9,  'deposit',    1, 180000, 1, 55,  true],   // Thu
            [12, 14, 'withdrawal', 3, 240000, 2, 70,  true],   // Fri
            [9,  10, 'deposit',    2, 320000, 1, 62,  true],   // Mon
            [8,  15, 'withdrawal', 5, 150000, 3, 95,  false],  // Tue
            [7,  11, 'deposit',    4, 410000, 2, 48,  true],   // Wed
            [6,  9,  'withdrawal', 1, 280000, 3, 73,  true],   // Thu
            [5,  13, 'deposit',    3, 350000, 1, 110, false],  // Fri
            // ---- current week ----
            [2,  10, 'withdrawal', 2, 190000, 2, 58,  true],   // Mon
            [2,  14, 'deposit',    5, 460000, 3, 82,  true],   // Mon
            [1,  11, 'withdrawal', 4, 220000, 1, 44,  true],   // Tue
            [1,  13, 'deposit',    1, 300000, 2, 128, false],  // Tue
            [1,  15, 'withdrawal', 3, 175000, 3, 51,  true],   // Tue
            [0,  10, 'deposit',    2, 390000, 1, 68,  true],   // Wed (today)
            [0,  14, 'withdrawal', 5, 260000, 2, 90,  true],   // Wed (today)
        ];

        $startRef = 1049;
        foreach ($rows as $i => [$daysAgo, $hour, $type, $branchId, $amount, $teamId, $dur, $onTime]) {
            $ref = 'REQ-' . ($startRef + $i);
            if (CashRequest::where('reference', $ref)->exists()) {
                $this->command?->line("skip $ref (already exists)");
                continue;
            }

            $start = Carbon::now()->setTimezone('Asia/Yangon')->subDays($daysAgo)->setTime($hour, 0, 0);
            // Belt-and-braces guard: if the clock has drifted into a weekend
            // date, nudge forward to the next Monday so the invariant holds.
            while (in_array($start->dayOfWeekIso, [6, 7], true)) {
                $start->addDay();
            }
            $end        = (clone $start)->addMinutes($dur);
            $createdAt  = (clone $start)->subMinutes(90);
            $approvedAt = (clone $start)->subMinutes(70);
            $assignedAt = (clone $start)->subMinutes(60);

            $cr = CashRequest::create([
                'reference'       => $ref,
                'type'            => $type,
                'branch_id'       => $branchId,
                'amount'          => $amount,
                'currency'        => 'MMK',
                'requested_date'  => $start->toDateString(),
                'complete_within' => '3h',
                'due_at'          => (clone $start)->addHours(4),
                'status'          => 'completed',
                'created_by'      => 2,
                'approved_by'     => 1,
                'approved_at'     => $approvedAt,
                'completed_at'    => $end,
                'created_at'      => $createdAt,
                'updated_at'      => $end,
            ]);

            Trip::create([
                'cash_request_id' => $cr->id,
                'team_id'         => $teamId,
                'assigned_by'     => 1,
                'status'          => 'completed',
                'assigned_at'     => $assignedAt,
                'accepted_at'     => (clone $assignedAt)->addMinutes(2),
                'started_at'      => $start,
                'completed_at'    => $end,
                'is_on_time'      => $onTime,
                'created_at'      => $createdAt,
                'updated_at'      => $end,
            ]);

            $this->command?->line("added $ref ({$start->format('D d M')}, $type, branch $branchId, team $teamId, {$dur}min)");
        }
    }
}
