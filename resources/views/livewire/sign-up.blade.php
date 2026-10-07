<div class="grid min-h-full place-items-center p-4 sm:p-8" data-testid="auth-sign-up">
    <form wire:submit="submit" class="w-full max-w-lg" data-testid="sign-up-form">
        <x-ui.panel class="grid gap-5">
            <div><p class="text-sm font-bold uppercase tracking-[0.2em] text-orange-600">Pull up a chair</p><h1 class="mt-1 text-3xl font-black tracking-tight">Welcome to Campfire</h1></div>
            <label class="mx-auto grid cursor-pointer place-items-center gap-2">
                <span class="relative size-28 overflow-hidden rounded-full border-4 border-orange-100 bg-stone-100 shadow-sm dark:border-orange-950 dark:bg-stone-800"><img class="size-full object-cover" src="{{ $avatar ? $avatar->temporaryUrl() : app(\App\Support\Assets::class)->path('default-avatar.svg') }}" alt=""><input class="absolute inset-0 cursor-pointer opacity-0" type="file" wire:model="avatar" accept="image/*"></span>
                <span class="text-sm font-semibold text-orange-700 dark:text-orange-400">Choose an avatar</span>
            </label>
            <x-ui.field label="Your name"><input wire:model="name" autocomplete="name" required></x-ui.field>
            <x-ui.field label="Email address"><input wire:model="email" type="email" autocomplete="username" required></x-ui.field>
            <x-ui.field label="Password"><input wire:model="password" type="password" autocomplete="new-password" maxlength="72" required></x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full">Create account</x-ui.button>
        </x-ui.panel>
    </form>
</div>
