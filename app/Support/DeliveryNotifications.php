<?php

namespace App\Support;

use App\Models\DeviceToken;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Throwable;

class DeliveryNotifications
{
    public static function send(iterable $userIds, array $attributes): void
    {
        foreach (collect($userIds)->unique() as $userId) {
            $notice = UserNotification::create(['user_id' => $userId] + $attributes);

            DB::afterCommit(static function () use ($notice): void {
                try {
                    $tokens = DeviceToken::where('user_id', $notice->user_id)
                        ->whereIn('platform', ['android', 'ios'])
                        ->pluck('token')
                        ->all();

                    foreach (array_chunk($tokens, 500) as $batch) {
                        $report = app(Messaging::class)->sendMulticast([
                            'notification' => [
                                'title' => 'CashRoute update',
                                'body' => 'Open CashRoute to view your delivery team update.',
                            ],
                            'data' => ['notificationId' => 'N-'.$notice->id],
                        ], $batch);
                        if ($invalid = $report->invalidTokens()) {
                            DeviceToken::where('user_id', $notice->user_id)->whereIn('token', $invalid)->delete();
                        }
                        if ($report->hasFailures()) {
                            Log::warning('CashRoute delivery push failed for one or more devices.', [
                                'notification_id' => $notice->id,
                                'failed_devices' => $report->failures()->count(),
                            ]);
                        }
                    }
                } catch (Throwable $error) {
                    Log::warning('CashRoute delivery push could not be sent.', [
                        'notification_id' => $notice->id,
                        'error_type' => $error::class,
                    ]);
                }
            });
        }
    }
}
