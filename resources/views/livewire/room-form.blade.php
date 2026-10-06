<div class="w-full p-4 sm:p-8" data-testid="room-form">
    @if($kind === 'directs' && $room)
        <x-ui.panel class="max-w-xl text-center">
            <h1 class="text-3xl font-black tracking-tight">Ping members</h1>
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach($room->users->where('id', '!=', auth()->id()) as $member)
                    <div class="rounded-2xl bg-stone-100 p-4 dark:bg-stone-800"><img class="mx-auto size-20 rounded-full object-cover" src="{{ $member->avatarUrl() }}" width="80" height="80" loading="lazy" alt=""><strong class="mt-2 block truncate">{{ $member->name }}</strong></div>
                @endforeach
            </div>
            <x-ui.button type="button" wire:click="delete" wire:confirm="Are you sure you want to delete this ping and all messages in it? This can't be undone." variant="danger" class="mt-8">Delete Ping</x-ui.button>
        </x-ui.panel>
    @else
        <x-ui.panel class="max-w-2xl">
            <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">{{ $kind === 'directs' ? 'Private conversation' : ($kind === 'opens' ? 'Open room' : 'Private room') }}</p><h1 class="text-3xl font-black tracking-tight">{{ $room ? 'Edit room' : ($kind === 'directs' ? 'Start a Ping' : 'New chat room') }}</h1></div>
            <form wire:submit="save" class="mt-6 grid gap-6" data-testid="room-form-fields">
                @if($kind !== 'directs')
                    <x-ui.field label="Room name"><input wire:model="name" id="room_name" required autofocus placeholder="Name the room"></x-ui.field>
                    @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <section class="grid gap-4 rounded-2xl bg-stone-100 p-4 dark:bg-stone-800" data-testid="room-form-access">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center"><div class="flex-1"><h2 class="font-bold">Access</h2><p class="text-sm text-stone-600 dark:text-stone-300">{{ $kind === 'opens' ? 'Everyone can enter this room.' : 'Choose who can enter this room.' }}</p></div><x-ui.button type="button" wire:click="changeKind('{{ $kind === 'opens' ? 'closeds' : 'opens' }}')">{{ $kind === 'opens' ? 'Make private' : 'Open to everyone' }}</x-ui.button></div>
                @else
                    <section class="grid gap-3" data-testid="room-form-access"><h2 class="font-bold">People to ping</h2>
                @endif
                        <div class="grid gap-2">
                            @foreach($users as $member)
                                <label wire:key="room-member-{{ $member->id }}" class="flex items-center gap-3 rounded-xl bg-white p-3 dark:bg-stone-900"><img class="size-10 rounded-full object-cover" src="{{ $member->avatarUrl() }}" width="40" height="40" alt=""><strong class="min-w-0 flex-1 truncate">{{ $member->name }}</strong>
                                    @if($kind === 'opens')<span class="text-sm font-semibold text-stone-500">Included</span>
                                    @elseif(!$room && $member->id === auth()->id())<span class="text-sm font-semibold text-stone-500">You</span>
                                    @else<input type="checkbox" wire:model="selected" value="{{ $member->id }}" class="size-5 accent-orange-500"><span class="sr-only">Give {{ $member->name }} access</span>@endif
                                </label>
                            @endforeach
                        </div>
                    </section>
                <x-ui.button type="submit" variant="primary">{{ $kind === 'directs' ? 'Start Ping' : 'Save room' }}</x-ui.button>
            </form>
            @if($room)<x-ui.button type="button" wire:click="delete" wire:confirm="Are you sure you want to delete this room and all messages in it? This can't be undone." variant="danger" class="mt-6">Delete {{ $room->name }}</x-ui.button>@endif
        </x-ui.panel>
    @endif
</div>
