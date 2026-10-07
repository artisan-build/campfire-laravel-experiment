<?php

namespace App\Jobs;

use App\Support\PushEndpoints;
use App\Support\Vapid;
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
        $ip = app(PushEndpoints::class)->resolve($s['endpoint']);
        if (! $ip) {
            return;
        }
        $keys = app(Vapid::class)->keys();
        if (! $keys) {
            return;
        }
        $client = new WebPush(['VAPID' => ['subject' => config('campfire.vapid.subject'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], [], 10, ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [parse_url($s['endpoint'], PHP_URL_HOST).':443:'.$ip]]]);
        $this->payload['badge'] = DB::table('memberships')->where('user_id', $s['user_id'])->whereNotNull('unread_at')->count();
        $report = $client->sendOneNotification(Subscription::create(['endpoint' => $s['endpoint'], 'publicKey' => $s['p256dh_key'], 'authToken' => $s['auth_key']]), json_encode($this->payload));
        if ($report->isSubscriptionExpired()) {
            DB::table('push_subscriptions')->where('id', $s['id'])->delete();
        } elseif (! $report->isSuccess()) {
            throw new \RuntimeException($report->getReason());
        }
    }
}
