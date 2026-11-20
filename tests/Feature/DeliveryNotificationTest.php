<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\DeliveryNotifications;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeliveryNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'delivery_notification_test',
            'database.connections.delivery_notification_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('cash_request_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->string('body');
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('platform');
            $table->string('token');
            $table->timestamps();
        });
    }

    public function test_delivery_push_waits_for_commit_and_targets_the_recipient(): void
    {
        DeviceToken::create(['user_id' => 1, 'platform' => 'android', 'token' => 'test-recipient']);
        DeviceToken::create(['user_id' => 2, 'platform' => 'android', 'token' => 'test-other-team']);
        $calls = 0;
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturnUsing(function ($message, $tokens) use (&$calls) {
            $this->assertSame(['test-recipient'], $tokens);
            $this->assertMatchesRegularExpression('/^N-\d+$/', $message['data']['notificationId']);
            $this->assertSame('CashRoute update', $message['notification']['title']);
            $calls++;

            return MulticastSendReport::withItems([]);
        });
        $this->app->instance(Messaging::class, $messaging);

        DB::transaction(function () use (&$calls) {
            DeliveryNotifications::send([1, 1], $this->payload());
            $this->assertSame(0, $calls);
        });

        $this->assertSame(1, $calls);
        $this->assertSame(1, UserNotification::count());
    }

    public function test_rollback_never_sends_a_push_and_other_users_cannot_open_an_inbox_item(): void
    {
        DeviceToken::create(['user_id' => 1, 'platform' => 'android', 'token' => 'test-recipient']);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');
        $this->app->instance(Messaging::class, $messaging);

        try {
            DB::transaction(function () {
                DeliveryNotifications::send([1], $this->payload());
                throw new RuntimeException('Rollback fixture');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback fixture', $error->getMessage());
        }
        $this->assertSame(0, UserNotification::count());

        $notice = UserNotification::create(['user_id' => 1] + $this->payload());
        $controller = $this->app->make(NotificationController::class);
        $owner = new User;
        $owner->id = 1;
        $ownerRequest = Request::create('/api/notifications/N-'.$notice->id);
        $ownerRequest->setUserResolver(fn () => $owner);
        $this->assertSame('N-'.$notice->id, $controller->show($ownerRequest, 'N-'.$notice->id)->resolve($ownerRequest)['id']);
        $controller->markRead($ownerRequest, 'N-'.$notice->id);
        $this->assertNotNull($notice->fresh()->read_at);

        $other = new User;
        $other->id = 2;
        $ownerRequest->setUserResolver(fn () => $other);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $controller->show($ownerRequest, 'N-'.$notice->id);
    }

    public function test_push_failure_keeps_the_notification_in_the_inbox(): void
    {
        DeviceToken::create(['user_id' => 1, 'platform' => 'android', 'token' => 'test-recipient']);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andThrow(new RuntimeException('Offline test device'));
        $this->app->instance(Messaging::class, $messaging);

        DB::transaction(fn () => DeliveryNotifications::send([1], $this->payload()));

        $this->assertSame(1, UserNotification::count());
    }

    private function payload(): array
    {
        return ['type' => 'team.updated', 'title' => 'Team updated', 'body' => 'Review your team.'];
    }
}
