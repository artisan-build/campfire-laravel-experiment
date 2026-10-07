<?php

namespace App\Jobs;

use App\Support\PushEndpoints;
use App\Support\Vapid;
use App\Support\WebhookDestinations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class DeliverPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $subscription, public array $payload) {}

    public function handle(): void
    {
        $s = $this->subscription;
        $destination = app(PushEndpoints::class)->resolve($s['endpoint']);
        if (! $destination) {
            return;
        }
        $keys = app(Vapid::class)->keys();
        if (! $keys) {
            return;
        }
        $client = app(WebPush::class, [
            'auth' => ['VAPID' => ['subject' => config('campfire.vapid.subject'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]],
            'defaultOptions' => [],
            'timeout' => 10,
            'clientOptions' => app(WebhookDestinations::class)->connectionOptions($destination),
        ]);
        $this->payload['badge'] = DB::table('memberships')->where('user_id', $s['user_id'])->whereNotNull('unread_at')->count();
        $report = $client->sendOneNotification(Subscription::create(['endpoint' => $s['endpoint'], 'publicKey' => $s['p256dh_key'], 'authToken' => $s['auth_key']]), json_encode($this->payload));
        if ($report->isSubscriptionExpired()) {
            DB::table('push_subscriptions')->where('id', $s['id'])->delete();
        } elseif (! $report->isSuccess()) {
            throw new \RuntimeException($report->getReason());
        }
    }
}
