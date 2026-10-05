@extends('layouts.app', ['title' => 'Search'])
@section('nav')<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button><h1 class="text-lg font-black">Search</h1>@endsection
@section('content')
<div class="flex min-h-full flex-col" data-testid="search-results">
    <form action="/searches" method="get" class="sticky top-0 z-10 flex gap-2 border-b border-stone-200 bg-stone-50/95 p-4 backdrop-blur sm:px-8 dark:border-stone-800 dark:bg-stone-950/95">
        <label class="sr-only" for="search-query">Search messages</label>
        <input id="search-query" class="min-w-0 flex-1" name="q" value="{{ $query }}" type="search" placeholder="Search messages">
        <x-ui.button type="submit" variant="primary">Search</x-ui.button>
    </form>
    <div id="search-results-list" class="messages searches__results flex-1" data-controller="search-results" data-search-results-target="messages" data-search-results-me-class="message--me" data-search-results-threaded-class="message--threaded" data-search-results-mentioned-class="message--mentioned" data-search-results-formatted-class="message--formatted">@include('messages.index')</div>
</div>
@endsection
