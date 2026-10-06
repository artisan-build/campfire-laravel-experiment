<div class="w-full p-4 sm:p-8" data-testid="settings-notifications" x-data="pushSubscriptions($wire)">
    <x-ui.panel class="grid gap-5">
        <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">My settings</p><h1 class="text-3xl font-black tracking-tight">Device notifications</h1></div>
        <div><x-ui.button type="button" variant="primary" @click="subscribe()">Enable notifications on this device</x-ui.button><p x-cloak x-show="error" x-text="error" class="mt-2 text-sm font-semibold text-red-700" role="alert"></p></div>
        <div class="grid gap-3" data-testid="push-subscription-list">
            @forelse($subscriptions as $subscription)
                <div wire:key="push-subscription-{{ $subscription->id }}" class="flex flex-col gap-3 rounded-2xl border border-stone-200 p-4 sm:flex-row sm:items-center dark:border-stone-700" data-testid="push-subscription-row">
                    <p class="min-w-0 flex-1 break-words text-sm text-stone-600 dark:text-stone-300">{{ $subscription->user_agent }}</p>
                    <x-ui.button type="button" wire:click="testNotification({{ $subscription->id }})">Send test</x-ui.button>
                    <x-ui.button type="button" wire:click="remove({{ $subscription->id }})" variant="danger">Remove</x-ui.button>
                </div>
            @empty
                <p class="rounded-2xl bg-stone-100 p-4 text-stone-600 dark:bg-stone-800 dark:text-stone-300">No devices are subscribed yet.</p>
            @endforelse
        </div>
    </x-ui.panel>
</div>
