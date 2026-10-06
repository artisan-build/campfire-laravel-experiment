<div class="w-full p-4 sm:p-8" data-testid="settings-account">
    <x-ui.panel class="grid gap-8" style="view-transition-name: account-settings">
        <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">Administration</p><h1 class="text-3xl font-black tracking-tight">Account settings</h1></div>
        @if($currentUser->role === 1)
            <form wire:submit="save" class="grid gap-5" data-testid="account-form">
                <div class="flex flex-col items-center gap-3"><img class="size-20 rounded-2xl object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="{{ $logo?->temporaryUrl() ?? route('account.logo', absolute: false) }}" width="80" height="80" alt="Account logo"><div class="flex flex-wrap justify-center gap-2"><label class="inline-flex min-h-10 cursor-pointer items-center rounded-full border border-stone-300 px-4 py-2 text-sm font-semibold dark:border-stone-600"><input class="sr-only" type="file" wire:model="logo" accept="image/*">Upload logo</label>@if($hasLogo)<x-ui.button type="button" wire:click="deleteLogo" variant="danger">Delete logo</x-ui.button>@endif</div></div>
                <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"><x-ui.field label="Account name"><input wire:model="name" required autofocus></x-ui.field><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></div>
                <label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-stone-100 p-4 font-semibold dark:bg-stone-800"><input type="checkbox" wire:model="restricted" class="size-5 accent-orange-500"><span>Only administrators can create rooms</span></label>
                @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @error('logo')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            </form>
        @endif
        <section class="grid gap-4 rounded-2xl bg-orange-50 p-5 dark:bg-orange-950/40" data-testid="settings-invitations">
            @php($joinUrl = route('join', ['code' => $account->join_code]))
            <div><h2 class="text-xl font-bold">Invite people</h2><p class="break-all text-sm text-stone-600 dark:text-stone-300">{{ $joinUrl }}</p></div>
            <div class="flex flex-wrap gap-2"><span x-data="clipboard(@js($joinUrl))"><x-ui.button @click="copy()" x-bind:class="{ 'bg-emerald-600 text-white': copied }"><span x-text="copied ? 'Copied' : 'Copy invitation link'">Copy invitation link</span></x-ui.button></span><x-ui.button href="{{ route('qr-code', ['id' => rtrim(strtr(base64_encode($joinUrl), '+/', '-_'), '=')], absolute: false) }}">QR code</x-ui.button>@if($currentUser->role === 1)<x-ui.button type="button" wire:click="resetJoinCode" wire:confirm="Are you sure you want to generate a new invite code?" variant="danger">Reset invite link</x-ui.button>@endif</div>
        </section>
        <section class="grid gap-3" data-testid="account-people">
            <h2 class="text-xl font-bold">People</h2>
            @foreach($users as $user)
                <div wire:key="account-user-{{ $user->id }}" class="flex flex-wrap items-center gap-3 rounded-2xl border border-stone-200 p-3 dark:border-stone-700 {{ $user->status === 2 ? 'opacity-50' : '' }}"><img class="size-10 rounded-full object-cover" src="{{ $user->avatarUrl() }}" width="40" height="40" loading="lazy" alt=""><strong class="min-w-0 flex-1 truncate">{{ $user->name }}</strong>
                    @if($currentUser->role === 1 && $user->status === 0 && $user->id !== $currentUser->id)<x-ui.button type="button" wire:click="toggleAdministrator({{ $user->id }})">{{ $user->role === 1 ? 'Remove admin' : 'Make admin' }}</x-ui.button><x-ui.button type="button" wire:click="deleteMember({{ $user->id }})" wire:confirm="Are you sure you want to permanently remove this person?" variant="danger">Delete</x-ui.button>@endif
                    @if($user->id === $currentUser->id)<x-ui.button href="{{ route('profile.show', ['user' => 'me'], absolute: false) }}" variant="ghost">My settings</x-ui.button>@endif
                </div>
            @endforeach
        </section>
    </x-ui.panel>
</div>
