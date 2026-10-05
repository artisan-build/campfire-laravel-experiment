@extends('layouts.app', ['title' => 'Sign in'])
@section('content')
<div class="grid min-h-full place-items-center p-4 sm:p-8" data-testid="auth-sign-in">
    <x-ui.panel class="max-w-md">
        <div class="mb-6">
            <p class="text-sm font-bold uppercase tracking-[0.2em] text-orange-600">Campfire</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight">Welcome back</h1>
        </div>
        @if($error ?? false)<p class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700 dark:bg-red-950 dark:text-red-200" role="alert">Too many requests or unauthorized.</p>@endif
        <form method="post" action="/session" class="grid gap-4">
            @csrf
            <input type="hidden" name="authenticity_token" value="{{ csrf_token() }}">
            <x-ui.field label="Email address"><input type="email" name="email_address" required autocomplete="username"></x-ui.field>
            <x-ui.field label="Password"><input type="password" name="password" required autocomplete="current-password"></x-ui.field>
            <x-ui.button type="submit" variant="primary" class="mt-2 w-full">Sign in</x-ui.button>
        </form>
    </x-ui.panel>
</div>
@endsection
