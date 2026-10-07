<?php

namespace Tests\Feature;

use App\Http\Controllers\PushController;
use App\Jobs\DeliverPush;
use App\Support\PushEndpoints;
use App\Support\WebhookDestinations;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class PushDeliveryTest extends TestCase
{
    public function test_push_delivery_encodes_the_service_worker_payload_contract(): void
    {
        $encodedPayload = null;
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSubscriptionExpired')->once()->andReturnFalse();
        $report->shouldReceive('isSuccess')->once()->andReturnTrue();
        $report->shouldReceive('getResponse')->once()->andReturnNull();
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')
            ->once()
            ->with(Mockery::type(Subscription::class), Mockery::on(function (string $payload) use (&$encodedPayload): bool {
                $encodedPayload = $payload;

                return true;
            }))
            ->andReturn($report);
        $this->bindPushClient($client);

        (new DeliverPush($this->subscription(), DeliverPush::payload('Campfire', 'Notifications are working', '/')))->handle();

        $payload = json_decode($encodedPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['title', 'options'], array_keys($payload));
        $this->assertSame([
            'title' => 'Campfire',
            'options' => [
                'body' => 'Notifications are working',
                'data' => [
                    'path' => '/',
                    'badge' => 0,
                ],
            ],
        ], $payload);
    }

    public function test_push_delivery_logs_safe_attempt_and_failure_outcome_without_credentials_or_content(): void
    {
        $sentinels = [
            'endpoint-secret',
            'p256-secret',
            'auth-secret',
            'vapid-public-secret',
            'vapid-private-secret',
            'title-secret',
            'body-secret',
            'path-secret',
            'reason-secret',
        ];
        $subscription = $this->subscription([
            'endpoint' => 'https://fcm.googleapis.com/endpoint-secret',
            'p256dh_key' => 'p256-secret',
            'auth_key' => 'auth-secret',
        ]);
        $payload = DeliverPush::payload('title-secret', 'body-secret', '/path-secret');
        $response = new Response(500, [], null, '1.1', 'reason-secret https://fcm.googleapis.com/endpoint-secret');
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSubscriptionExpired')->once()->andReturnFalse();
        $report->shouldReceive('isSuccess')->once()->andReturnFalse();
        $report->shouldReceive('getResponse')->once()->andReturn($response);
        $report->shouldNotReceive('getReason');
        $client = Mockery::mock(WebPush::class);
        $encodedPayload = null;
        $client->shouldReceive('sendOneNotification')->once()->with(
            Mockery::type(Subscription::class),
            Mockery::on(function (string $encoded) use (&$encodedPayload): bool {
                $encodedPayload = $encoded;

                return true;
            })
        )->andReturn($report);
        $this->bindPushClient($client, 'vapid-public-secret', 'vapid-private-secret');
        $records = [];
        Log::shouldReceive('info')->twice()->andReturnUsing(function (string $message, array $context) use (&$records): void {
            $records[] = compact('message', 'context');
        });

        try {
            (new DeliverPush($subscription, $payload))->handle();
            $this->fail('Unsuccessful push report did not fail the job.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Push delivery failed (nonstandard_http_reason).', $error->getMessage());
        }

        $this->assertCount(2, $records);
        $this->assertSame('Push delivery attempt', $records[0]['message']);
        $this->assertTrue(Str::isUuid($records[0]['context']['correlation_id']));
        $this->assertSame(gethostname() ?: 'unknown', $records[0]['context']['worker_host']);
        $this->assertSame('fcm.googleapis.com', $records[0]['context']['push_service_host']);
        $this->assertSame(17, $records[0]['context']['subscription_id']);
        $this->assertSame(strlen($encodedPayload), $records[0]['context']['payload_bytes']);
        $this->assertSame(['title', 'options'], $records[0]['context']['payload_keys']);
        $this->assertSame('Push delivery outcome', $records[1]['message']);
        $this->assertSame($records[0]['context']['correlation_id'], $records[1]['context']['correlation_id']);
        $this->assertSame([
            'correlation_id' => $records[0]['context']['correlation_id'],
            'subscription_id' => 17,
            'success' => false,
            'expired' => false,
            'http_status' => 500,
            'http_reason' => 'nonstandard_http_reason',
        ], $records[1]['context']);

        $serializedRecords = json_encode($records, JSON_THROW_ON_ERROR);
        foreach ($sentinels as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serializedRecords);
        }
    }

    public function test_push_delivery_logs_a_safe_early_outcome_for_an_invalid_endpoint(): void
    {
        $records = [];
        Log::shouldReceive('info')->once()->andReturnUsing(function (string $message, array $context) use (&$records): void {
            $records[] = compact('message', 'context');
        });

        (new DeliverPush($this->subscription(['endpoint' => 'https://invalid.example/endpoint-secret']), DeliverPush::payload('title-secret', 'body-secret', '/path-secret')))->handle();

        $this->assertSame('Push delivery outcome', $records[0]['message']);
        $this->assertSame(17, $records[0]['context']['subscription_id']);
        $this->assertFalse($records[0]['context']['success']);
        $this->assertFalse($records[0]['context']['expired']);
        $this->assertNull($records[0]['context']['http_status']);
        $this->assertSame('invalid_endpoint', $records[0]['context']['http_reason']);
        $this->assertStringNotContainsString('endpoint-secret', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_push_delivery_sanitizes_transport_failures_in_logs_and_the_rethrown_error(): void
    {
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->andThrow(new \RuntimeException('transport-secret https://fcm.googleapis.com/endpoint-secret'));
        $this->bindPushClient($client);
        $records = [];
        Log::shouldReceive('info')->twice()->andReturnUsing(function (string $message, array $context) use (&$records): void {
            $records[] = compact('message', 'context');
        });

        try {
            (new DeliverPush($this->subscription(['endpoint' => 'https://fcm.googleapis.com/endpoint-secret']), DeliverPush::payload('title-secret', 'body-secret', '/path-secret')))->handle();
            $this->fail('Transport failure did not fail the job.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Push delivery failed (transport_error).', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }

        $this->assertSame('Push delivery outcome', $records[1]['message']);
        $this->assertSame('transport_error', $records[1]['context']['http_reason']);
        $serializedRecords = json_encode($records, JSON_THROW_ON_ERROR);
        foreach (['transport-secret', 'endpoint-secret', 'title-secret', 'body-secret', 'path-secret'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serializedRecords);
        }
    }

    public function test_expired_push_subscription_is_deleted_and_logged(): void
    {
        [$user] = $this->fixture();
        $subscription = $this->subscription(['user_id' => $user->id]);
        DB::table('push_subscriptions')->insert($subscription + ['created_at' => now(), 'updated_at' => now()]);
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSubscriptionExpired')->once()->andReturnTrue();
        $report->shouldReceive('isSuccess')->once()->andReturnFalse();
        $report->shouldReceive('getResponse')->once()->andReturn(new Response(410));
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->andReturn($report);
        $this->bindPushClient($client);

        (new DeliverPush($subscription, DeliverPush::payload('Campfire', 'Expired', '/')))->handle();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => 17]);
    }

    public function test_controller_test_notification_queues_the_service_worker_payload_contract(): void
    {
        [$user] = $this->fixture();
        $subscription = $this->subscription(['user_id' => $user->id]);
        DB::table('push_subscriptions')->insert($subscription + ['created_at' => now(), 'updated_at' => now()]);
        $request = Request::create('/users/me/push_subscriptions/17/test_notifications', 'POST');
        $request->setUserResolver(fn () => $user);
        Queue::fake();

        app(PushController::class)->test($request, 'me', 17);

        Queue::assertPushed(DeliverPush::class, fn (DeliverPush $job): bool => $job->payload === DeliverPush::payload('Campfire', 'Notifications are working', '/'));
    }

    private function bindPushClient(MockInterface $client, string $publicKey = 'test-public-key', string $privateKey = 'test-private-key'): void
    {
        $destinations = new WebhookDestinations(fn (string $host): array => ['93.184.216.34']);
        $this->app->instance(PushEndpoints::class, new PushEndpoints($destinations));
        config([
            'campfire.vapid.public_key' => $publicKey,
            'campfire.vapid.private_key' => $privateKey,
        ]);
        $this->app->bind(WebPush::class, fn () => $client);
    }

    /** @return array{id: int, user_id: int, endpoint: string, p256dh_key: string, auth_key: string} */
    private function subscription(array $overrides = []): array
    {
        return $overrides + [
            'id' => 17,
            'user_id' => 29,
            'endpoint' => 'https://fcm.googleapis.com/push-token',
            'p256dh_key' => 'test-public-key',
            'auth_key' => 'test-auth-key',
        ];
    }
}
