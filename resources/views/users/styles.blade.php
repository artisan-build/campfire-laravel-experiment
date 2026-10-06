@extends('layouts.app', ['title' => 'Custom styles'])
@section('nav')<x-ui.button href="{{ route('account.edit', absolute: false) }}" variant="ghost">Back to account settings</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-custom-styles">
    <x-ui.panel>
        <h1 class="text-3xl font-black tracking-tight">Custom styles</h1>
        <p class="mt-2 text-sm text-stone-600 dark:text-stone-300">Add account-wide CSS for details unique to your Campfire.</p>
        <form action="{{ route('account.styles.update', absolute: false) }}" method="post" class="mt-6 grid gap-4">@csrf @method('PATCH')<x-ui.field label="CSS"><textarea class="min-h-80 font-mono text-sm" name="account[custom_styles]" spellcheck="false">{{ $styles }}</textarea></x-ui.field><x-ui.button type="submit" variant="primary">Save styles</x-ui.button></form>
    </x-ui.panel>
</div>
@endsection
