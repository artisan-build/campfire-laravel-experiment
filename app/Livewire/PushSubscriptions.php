<?php

namespace App\Livewire;

use App\Jobs\DeliverPush;
use App\Models\User;
use App\Support\PushEndpoints;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class PushSubscriptions extends Component
{
    public function register(string $endpoint, string $p256dhKey, string $authKey): void
    {
        $validated = validator(compact('endpoint', 'p256dhKey', 'authKey'), [
            'endpoint' => 'required|string|max:2048',
            'p256dhKey' => 'required|string|max:2048',
            'authKey' => 'required|string|max:2048',
        ])->validate();
        abort_unless(app(PushEndpoints::class)->resolve($validated['endpoint']) !== null, 422);

        DB::table('push_subscriptions')->updateOrInsert([
            'user_id' => $this->user()->id,
            'endpoint' => $validated['endpoint'],
            'p256dh_key' => $validated['p256dhKey'],
            'auth_key' => $validated['authKey'],
        ], [
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function remove(int $subscriptionId): void
    {
        $subscription = $this->subscription($subscriptionId);
        DB::table('push_subscriptions')->where('id', $subscription['id'])->where('user_id', $this->user()->id)->delete();
    }

    public function testNotification(int $subscriptionId): void
    {
        $subscription = $this->subscription($subscriptionId);
        DeliverPush::dispatch($subscription, DeliverPush::payload('Campfire', 'Notifications are working', route('chat.root', absolute: false)));
    }

    public function render()
    {
        return view('livewire.push-subscriptions', [
            'subscriptions' => DB::table('push_subscriptions')->where('user_id', $this->user()->id)->get(),
        ]);
    }

    /** @return array<string, mixed> */
    private function subscription(int $id): array
    {
        $subscription = DB::table('push_subscriptions')->where('id', $id)->where('user_id', $this->user()->id)->first();
        abort_unless($subscription !== null, 404);

        return (array) $subscription;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
