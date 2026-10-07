<div class="w-full p-4 sm:p-8" data-testid="settings-profile">
    <x-ui.panel class="grid gap-8" style="view-transition-name: avatar-{{ $user->id }}">
        <form wire:submit="save" class="grid gap-6" data-testid="profile-form">
            <div class="flex flex-col items-center gap-3">
                <img class="size-40 rounded-full object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="{{ $avatar?->temporaryUrl() ?? $user->avatarUrl() }}" width="160" height="160" alt="Your avatar">
                <div class="flex flex-wrap justify-center gap-2"><label class="inline-flex min-h-10 cursor-pointer items-center rounded-full border border-stone-300 bg-white px-4 py-2 text-sm font-semibold hover:border-orange-400 dark:border-stone-600 dark:bg-stone-900"><input class="sr-only" type="file" wire:model="avatar" accept="image/*">Upload avatar</label>@if($hasAvatar)<x-ui.button type="button" wire:click="deleteAvatar" variant="danger">Delete avatar</x-ui.button>@endif</div>
                @error('avatar')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4">
                <x-ui.field label="Name"><input wire:model="name" required autofocus autocomplete="name"></x-ui.field>
                <x-ui.field label="Email address"><input type="email" wire:model="email" autocomplete="username"></x-ui.field>
                <x-ui.field label="Change password"><input type="password" wire:model="password" autocomplete="new-password" maxlength="72" placeholder="Leave blank to keep your password"></x-ui.field>
                <x-ui.field label="Bio"><textarea wire:model="bio" rows="3" maxlength="200" placeholder="A few words about yourself"></textarea></x-ui.field>
                @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @error('email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
            </div>
        </form>
        <section class="grid gap-3 border-t border-stone-200 pt-6 dark:border-stone-700" data-testid="profile-room-notifications">
            <div><h2 class="text-xl font-bold">Room notifications</h2><p class="text-sm text-stone-600 dark:text-stone-300">Choose how each room gets your attention.</p></div>
            @foreach($memberships as $membership)
                <div wire:key="profile-membership-{{ $membership->id }}" class="flex flex-col gap-2 rounded-2xl bg-stone-100 p-3 sm:flex-row sm:items-center dark:bg-stone-800"><a class="min-w-0 flex-1 truncate font-semibold hover:underline" href="{{ route('rooms.show', $membership->room_id, absolute: false) }}">{{ $membership->room->displayName($user) }}</a><select wire:change="setInvolvement({{ $membership->room_id }}, $event.target.value)" aria-label="Notifications for {{ $membership->room->displayName($user) }}">@foreach(['everything'=>'All messages','mentions'=>'Mentions','nothing'=>'None','invisible'=>'Hide room'] as $value=>$text)@if($membership->room->type !== 'Rooms::Direct' || in_array($value,['everything','nothing']))<option value="{{ $value }}" @selected($membership->involvement === $value)>{{ $text }}</option>@endif @endforeach</select></div>
            @endforeach
            <x-ui.button href="{{ route('push.index', ['user' => 'me'], absolute: false) }}">Notifications on your devices</x-ui.button>
        </section>
        @php($transferUrl = route('transfers.show', ['id' => app(\App\Support\SignedIdentifiers::class)->signedId($user->id, 'User', 'transfer', now()->addHours(4)->utc()->format('Y-m-d\TH:i:s.v\Z'))]))
        <section class="grid gap-4 border-t border-stone-200 pt-6 dark:border-stone-700" data-testid="settings-device-transfer">
            <div><h2 class="text-xl font-bold">Sign in on another device</h2><p class="text-sm text-stone-600 dark:text-stone-300">Treat this private link like a password. It expires in four hours.</p></div>
            <label class="sr-only" for="session_transfer_url">Private sign-in link</label><input type="text" id="session_transfer_url" value="{{ $transferUrl }}" readonly>
            <div class="flex flex-wrap gap-2"><x-ui.button href="{{ route('qr-code', ['id' => rtrim(strtr(base64_encode($transferUrl), '+/', '-_'), '=')], absolute: false) }}">Show QR code</x-ui.button><span x-data="clipboard(@js($transferUrl))"><x-ui.button @click="copy()" x-bind:class="{ 'bg-emerald-600 text-white': copied }"><span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span></x-ui.button></span><span x-data="webShare(@js(['title' => 'Your sign-in link', 'text' => 'This is your private Campfire sign-in URL.', 'url' => $transferUrl]))" x-cloak x-show="supported"><x-ui.button @click="share()">Share link</x-ui.button></span></div>
        </section>
    </x-ui.panel>
</div>
