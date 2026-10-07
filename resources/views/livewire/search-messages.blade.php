<div class="flex min-h-full flex-col" data-testid="search-results">
    <form wire:submit="search" class="sticky top-0 z-10 flex gap-2 border-b border-stone-200 bg-stone-50/95 p-4 backdrop-blur sm:px-8 dark:border-stone-800 dark:bg-stone-950/95" data-testid="search-form">
        <label class="sr-only" for="search-query">Search messages</label>
        <input id="search-query" class="min-w-0 flex-1" wire:model="query" type="search" placeholder="Search messages">
        <x-ui.button type="submit" variant="primary">Search</x-ui.button>
    </form>
    @if($history->isNotEmpty())
        <section class="flex flex-wrap items-center gap-2 border-b border-stone-200 p-4 sm:px-8 dark:border-stone-800" data-testid="search-history">
            @foreach($history as $historyQuery)<x-ui.button href="{{ route('searches.index', ['q' => $historyQuery], absolute: false) }}" wire:key="search-history-{{ md5($historyQuery) }}">{{ $historyQuery }}</x-ui.button>@endforeach
            <x-ui.button type="button" wire:click="clearHistory" variant="danger">Clear history</x-ui.button>
        </section>
    @endif
    <div id="search-results-list" class="messages searches__results flex-1" data-testid="search-result-list">@include('messages.index', ['messageIsFormatted' => true])</div>
</div>
