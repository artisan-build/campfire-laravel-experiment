@php($assets = app(\App\Support\Assets::class))
<!DOCTYPE html>
<html lang="en">
<head>
<title>{{ $title ?? 'Campfire' }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, interactive-widget=resizes-content">
<meta name="view-transition" content="same-origin"><meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#fafaf9" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#0c0a09" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="csrf-token" content="{{ csrf_token() }}"><meta name="csrf-param" content="authenticity_token">
@if(config('broadcasting.default') === 'reverb')<meta name="echo-config" content="{{ json_encode(['key' => config('broadcasting.connections.reverb.key'), 'host' => config('broadcasting.connections.reverb.options.host'), 'port' => config('broadcasting.connections.reverb.options.port'), 'scheme' => config('broadcasting.connections.reverb.options.scheme')]) }}">@endif
@if(isset($currentUser))
<meta name="current-user-id" content="{{ $currentUser->id }}"><meta name="current-user-name" content="{{ $currentUser->name }}">
@endif
<meta name="vapid-public-key" content="{{ app(\App\Support\Vapid::class)->publicKey() }}"><meta name="turbo-prefetch" content="true">
<link rel="manifest" href="/webmanifest.json"><link rel="icon" href="/account/logo" type="image/png"><link rel="apple-touch-icon" href="/account/logo">
{!! $assets->head() !!}
@yield('head')
</head>
<body class="flex h-dvh flex-col overflow-hidden {{ $bodyClass ?? '' }}" data-controller="local-time" x-data="appShell" data-testid="app-shell">
<a href="#main-content" class="sr-only z-50 rounded-full bg-orange-500 px-4 py-2 font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to main content</a>
<header id="nav" class="relative z-20 flex min-h-16 items-center gap-3 border-b border-stone-200 bg-white/90 px-3 backdrop-blur sm:px-5 dark:border-stone-800 dark:bg-stone-950/90" data-testid="app-navigation">
    @hasSection('sidebar')
        <button type="button" class="inline-grid size-10 place-items-center rounded-full border border-stone-300 bg-white lg:hidden dark:border-stone-700 dark:bg-stone-900" @click="toggleSidebar()" :aria-expanded="sidebarOpen.toString()" aria-controls="sidebar">
            <span class="grid gap-1" aria-hidden="true"><span class="h-0.5 w-5 bg-current"></span><span class="h-0.5 w-5 bg-current"></span><span class="h-0.5 w-5 bg-current"></span></span>
            <span class="sr-only">Toggle rooms</span>
        </button>
    @endif
    @yield('nav')
</header>
@if(session('notice') || session('alert'))
<div class="fixed left-1/2 top-20 z-50 -translate-x-1/2 rounded-full px-5 py-3 font-semibold text-white shadow-xl {{ session('alert') ? 'bg-red-600' : 'bg-emerald-600' }}" data-controller="element-removal" data-action="animationend->element-removal#remove" role="alert" aria-atomic="true" data-testid="app-flash">
    {{ session('alert') ?? session('notice') }}
</div>
@endif
<div class="flex min-h-0 flex-1 overflow-hidden">
    <main id="main-content" class="relative flex min-w-0 flex-1 flex-col overflow-auto bg-stone-50 dark:bg-stone-950" data-testid="app-content">
        @yield('content')
        <footer id="footer" class="mt-auto shrink-0" data-testid="app-footer">@yield('footer')</footer>
    </main>
    @hasSection('sidebar')
        <button type="button" aria-label="Close rooms" class="fixed inset-0 z-20 bg-stone-950/40 transition lg:hidden" x-cloak x-show="sidebarOpen" x-transition.opacity @click="closeSidebar()"></button>
        <aside id="sidebar" class="fixed inset-y-0 right-0 z-30 w-[min(22rem,88vw)] border-l border-stone-200 bg-stone-100 shadow-2xl transition-transform duration-300 lg:relative lg:z-10 lg:w-80 lg:translate-x-0 lg:shadow-none dark:border-stone-800 dark:bg-stone-900" :class="sidebarOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'" @keydown.escape.window="closeSidebar()" data-testid="app-sidebar">
            @yield('sidebar')
        </aside>
    @endif
</div>
<dialog class="m-auto max-h-[90dvh] max-w-[92vw] rounded-3xl bg-stone-950/95 p-3 text-white shadow-2xl backdrop:bg-stone-950/80" aria-label="Image viewer" x-ref="lightbox" @close="resetLightbox()" data-testid="app-lightbox">
    <img :src="lightboxSource" alt="" class="max-h-[78dvh] max-w-[88vw] rounded-2xl object-contain">
    <div class="mt-3 flex items-center justify-center gap-2">
        <form method="dialog"><x-ui.button type="submit" variant="secondary">Close</x-ui.button></form>
        <x-ui.button href="#" class="hide-in-ios-pwa" x-bind:href="lightboxDownload">Download</x-ui.button>
        <x-ui.button x-cloak x-show="canShareFiles" @click="shareLightbox()">Share</x-ui.button>
    </div>
</dialog>
<a href="https://once.com" class="fixed bottom-3 left-3 hidden opacity-40 transition hover:opacity-100 lg:block" target="_blank" rel="noreferrer" aria-label="Once software from 37signals home page"><img src="{{ $assets->path('campfire-icon.png') }}" alt="" width="34" height="29"></a>
</body>
</html>
