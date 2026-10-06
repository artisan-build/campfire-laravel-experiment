<div class="w-full p-4 sm:p-8" data-testid="room-involvement">
    <form wire:submit="save" class="mx-auto grid w-full max-w-xl gap-4 rounded-3xl border border-stone-200 bg-white p-5 shadow-sm sm:p-8 dark:border-stone-700 dark:bg-stone-900" data-testid="room-notifications">
        <fieldset class="grid gap-3">
            <legend class="mb-1 text-2xl font-black tracking-tight">Notifications</legend>
            @foreach(['invisible' => 'Hide room', 'nothing' => 'Nothing', 'mentions' => 'Mentions', 'everything' => 'Everything'] as $value => $label)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-stone-200 px-3 py-2 hover:border-orange-400 dark:border-stone-700"><input class="size-4 accent-orange-500" type="radio" wire:model="involvement" value="{{ $value }}"><span>{{ $label }}</span></label>
            @endforeach
        </fieldset>
        @error('involvement')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <x-ui.button type="submit" variant="primary">Save notifications</x-ui.button>
    </form>
</div>
