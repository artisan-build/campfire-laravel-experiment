@extends('layouts.app', ['title' => 'Sign up'])
@section('content')
@php($assets = app(\App\Support\Assets::class))
<div class="grid min-h-full place-items-center p-4 sm:p-8" data-testid="auth-sign-up">
    <form action="{{ $action }}" method="post" enctype="multipart/form-data" class="w-full max-w-lg">@csrf
        <x-ui.panel class="grid gap-5">
            <div><p class="text-sm font-bold uppercase tracking-[0.2em] text-orange-600">Pull up a chair</p><h1 class="mt-1 text-3xl font-black tracking-tight">Welcome to Campfire</h1></div>
            <label class="mx-auto grid cursor-pointer place-items-center gap-2" data-controller="upload-preview">
                <span class="relative size-28 overflow-hidden rounded-full border-4 border-orange-100 bg-stone-100 shadow-sm dark:border-orange-950 dark:bg-stone-800"><img class="size-full object-cover" src="{{ $assets->path('default-avatar.svg') }}" data-upload-preview-target="image" alt=""><input class="absolute inset-0 cursor-pointer opacity-0" type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage"></span>
                <span class="text-sm font-semibold text-orange-700 dark:text-orange-400">Choose an avatar</span>
            </label>
            <x-ui.field label="Your name"><input name="user[name]" autocomplete="name" required></x-ui.field>
            <x-ui.field label="Email address"><input name="user[email_address]" type="email" autocomplete="username" required></x-ui.field>
            <x-ui.field label="Password"><input name="user[password]" type="password" autocomplete="new-password" maxlength="72" required></x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full">Create account</x-ui.button>
        </x-ui.panel>
    </form>
</div>
@endsection
