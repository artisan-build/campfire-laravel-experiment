<div class="w-full p-4 sm:p-8" data-testid="settings-bot-form">
    <x-ui.panel class="max-w-xl">
        <h1 class="text-3xl font-black tracking-tight">{{ $bot ? 'Edit chat bot' : 'New chat bot' }}</h1>
        <form wire:submit="save" class="mt-6 grid gap-5" data-testid="bot-form">
            <label class="mx-auto grid cursor-pointer place-items-center gap-2"><img class="size-24 rounded-full object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="{{ $avatar ? $avatar->temporaryUrl() : ($bot ? $bot->avatarUrl() : app(\App\Support\Assets::class)->path('default-bot-avatar.svg')) }}" width="96" height="96" alt="Bot avatar"><span class="text-sm font-semibold text-orange-700 dark:text-orange-400">Choose avatar</span><input class="sr-only" type="file" wire:model="avatar" accept="image/*"></label>
            <x-ui.field label="Bot name"><input wire:model="name" required autofocus autocomplete="name"></x-ui.field>
            <x-ui.field label="Webhook URL"><input type="url" wire:model="webhookUrl" placeholder="https://example.com/webhook"></x-ui.field>
            <x-ui.field label="Description"><textarea wire:model="bio"></textarea></x-ui.field>
            @error('webhookUrl')<p class="text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
            <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
        </form>
        @if($bot)
            <div class="mt-8 flex flex-col justify-between gap-3 border-t border-stone-200 pt-6 sm:flex-row dark:border-stone-700"><x-ui.button type="button" wire:click="delete" wire:confirm="Are you sure you want to permanently remove this bot from the account? This can't be undone." variant="danger">Delete bot</x-ui.button><x-ui.button type="button" wire:click="rotateKey" wire:confirm="Are you sure you want to change the bot key? All usage of this bot must be updated." variant="danger">Generate new key</x-ui.button></div>
        @endif
    </x-ui.panel>
</div>
