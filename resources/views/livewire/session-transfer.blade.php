<div class="grid min-h-full place-items-center p-4" data-testid="auth-transfer">
    <x-ui.panel class="max-w-md text-center">
        <h1 class="text-3xl font-black tracking-tight">Continue to Campfire</h1>
        <p class="mt-2 text-stone-600 dark:text-stone-300">Use this one-time link to finish signing in on this device.</p>
        <form wire:submit="confirm" class="mt-6" data-testid="transfer-form"><x-ui.button type="submit" variant="primary" class="w-full">Sign in</x-ui.button></form>
    </x-ui.panel>
</div>
