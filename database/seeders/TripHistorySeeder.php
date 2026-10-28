<?php

namespace Database\Seeders;

use App\Models\CashRequest;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds 14 completed trips spread across the past ~2 weeks so Reports and
 * Trip History have presentable, varied data. Idempotent — re-runs skip
 * references that already exist.
 *
 * Run with: php artisan db:seed --class=TripHistorySeeder
 */
class TripHistorySeeder extends Seeder
{
    public function run(): void
    {
        // [daysAgo, hour, type, branch_id, amount, team_id, durationMin, onTime]
        $rows = [
            [13, 9,  'deposit',    1, 180000, 1, 55,  true],
            [13, 14, 'withdrawal', 3, 240000, 2, 70,  true],
            [12, 10, 'deposit',    2, 320000, 1, 62,  true],
            [12, 15, 'withdrawal', 5, 150000, 3, 95,  false],
            [11, 11, 'deposit',    4, 410000, 2, 48,  true],
            [10, 9,  'withdrawal', 1, 280000, 3, 73,  true],
            [9,  13, 'deposit',    3, 350000, 1, 110, false],
            [8,  10, 'withdrawal', 2, 190000, 2, 58,  true],
            [7,  14, 'deposit',    5, 460000, 3, 82,  true],
            [6,  11, 'withdrawal', 4, 220000, 1, 44,  true],
            [5,  9,  'deposit',    1, 300000, 2, 128, false],
            [4,  15, 'withdrawal', 3, 175000, 3, 51,  true],
            [3,  10, 'deposit',    2, 390000, 1, 68,  true],
            [2,  13, 'withdrawal', 5, 260000, 2, 90,  true],
        ];

        $startRef = 1049;
        foreach ($rows as $i => [$daysAgo, $hour, $type, $branchId, $amount, $teamId, $dur, $onTime]) {
            $ref = 'REQ-' . ($startRef + $i);
            if (CashRequest::where('reference', $ref)->exists()) {
                $this->command?->line("skip $ref (already exists)");
                continue;
            }

            $start      = Carbon::now()->setTimezone('Asia/Yangon')->subDays($daysAgo)->setTime($hour, 0, 0);
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

            $this->command?->line("added $ref ($type, branch $branchId, team $teamId, {$dur}min)");
        }
    }
}
