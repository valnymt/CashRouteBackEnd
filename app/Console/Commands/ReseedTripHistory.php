<?php

namespace App\Console\Commands;

use App\Models\CashRequest;
use App\Models\Trip;
use Illuminate\Console\Command;

/**
 * Wipes the demo trip-history rows (REQ-1049 → REQ-1070) and re-runs
 * TripHistorySeeder so Reports / Trip History show the latest weekday-only
 * seed data. Safe to run repeatedly.
 */
class ReseedTripHistory extends Command
{
    protected $signature = 'trips:reseed-demo';
    protected $description = 'Wipe the demo trip-history rows and re-run TripHistorySeeder (weekday-only).';

    public function handle(): int
    {
        $refs = [];
        for ($i = 1049; $i <= 1070; $i++) {
            $refs[] = "REQ-$i";
        }

        $crs = CashRequest::whereIn('reference', $refs)->pluck('id');
        $tripCount = Trip::whereIn('cash_request_id', $crs)->count();
        Trip::whereIn('cash_request_id', $crs)->delete();
        $crCount = CashRequest::whereIn('reference', $refs)->delete();

        $this->info("Removed $tripCount trip(s) and $crCount cash request(s).");
        $this->call('db:seed', ['--class' => 'TripHistorySeeder']);

        return self::SUCCESS;
    }
}
