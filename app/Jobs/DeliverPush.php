<?php

namespace App\Jobs;

use App\Support\PushEndpoints;
use App\Support\Vapid;
use App\Support\WebhookDestinations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class DeliverPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $subscription, public array $payload) {}

    /** @return array{title: string, options: array{body: string, data: array{path: string}}} */
    public static function payload(string $title, string $body, string $path): array
    {
        return [
            'title' => $title,
            'options' => [
                'body' => $body,
                'data' => ['path' => $path],
            ],
        ];
    }

    public function handle(): void
    {
        $s = $this->subscription;
        $correlationId = (string) Str::uuid();
        $subscriptionId = (int) $s['id'];
        $destination = app(PushEndpoints::class)->resolve($s['endpoint']);
        if (! $destination) {
            $this->logOutcome($correlationId, $subscriptionId, false, false, null, 'invalid_endpoint');

            return;
        }
        $keys = app(Vapid::class)->keys();
        if (! $keys) {
            $this->logOutcome($correlationId, $subscriptionId, false, false, null, 'vapid_unavailable');

            return;
        }
        $client = app(WebPush::class, [
            'auth' => ['VAPID' => ['subject' => config('campfire.vapid.subject'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]],
            'defaultOptions' => [],
            'timeout' => 10,
            'clientOptions' => app(WebhookDestinations::class)->connectionOptions($destination),
        ]);
        $this->payload['options']['data']['badge'] = DB::table('memberships')->where('user_id', $s['user_id'])->whereNotNull('unread_at')->count();
        try {
            $encodedPayload = json_encode($this->payload, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->logOutcome($correlationId, $subscriptionId, false, false, null, 'payload_encoding_failed');

            throw new RuntimeException('Push delivery failed (payload_encoding_failed).');
        }

        Log::info('Push delivery attempt', [
            'correlation_id' => $correlationId,
            'worker_host' => gethostname() ?: 'unknown',
            'push_service_host' => $destination['host'],
            'subscription_id' => $subscriptionId,
            'payload_bytes' => strlen($encodedPayload),
            'payload_keys' => array_keys($this->payload),
        ]);

        try {
            $report = $client->sendOneNotification(Subscription::create(['endpoint' => $s['endpoint'], 'publicKey' => $s['p256dh_key'], 'authToken' => $s['auth_key']]), $encodedPayload);
        } catch (Throwable) {
            $this->logOutcome($correlationId, $subscriptionId, false, false, null, 'transport_error');

            throw new RuntimeException('Push delivery failed (transport_error).');
        }

        $expired = $report->isSubscriptionExpired();
        $success = $report->isSuccess();
        $response = $report->getResponse();
        $reason = $this->safeHttpReason($response);
        $this->logOutcome($correlationId, $subscriptionId, $success, $expired, $response?->getStatusCode(), $reason);

        if ($expired) {
            DB::table('push_subscriptions')->where('id', $s['id'])->delete();
        } elseif (! $success) {
            throw new RuntimeException("Push delivery failed ({$reason}).");
        }
    }

    private function logOutcome(string $correlationId, int $subscriptionId, bool $success, bool $expired, ?int $status, string $reason): void
    {
        Log::info('Push delivery outcome', [
            'correlation_id' => $correlationId,
            'subscription_id' => $subscriptionId,
            'success' => $success,
            'expired' => $expired,
            'http_status' => $status,
            'http_reason' => $reason,
        ]);
    }

    private function safeHttpReason(?ResponseInterface $response): string
    {
        if ($response === null) {
            return 'no_response';
        }

        $canonicalPhrases = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            408 => 'Request Timeout',
            410 => 'Gone',
            413 => 'Content Too Large',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        $phrase = $canonicalPhrases[$response->getStatusCode()] ?? null;

        if ($phrase !== null && hash_equals($phrase, $response->getReasonPhrase())) {
            return $phrase;
        }

        return $response->getReasonPhrase() === '' ? 'http_response' : 'nonstandard_http_reason';
    }
}
