@props(['href' => null, 'variant' => 'secondary', 'type' => 'button'])
@php
    $classes = [
        'inline-flex min-h-10 items-center justify-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 disabled:cursor-not-allowed disabled:opacity-50',
        'bg-orange-500 text-white shadow-sm hover:bg-orange-600' => $variant === 'primary',
        'border border-stone-300 bg-white text-stone-800 hover:border-orange-400 hover:text-orange-700 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100' => $variant === 'secondary',
        'bg-red-600 text-white hover:bg-red-700' => $variant === 'danger',
        'text-stone-600 hover:bg-stone-100 hover:text-stone-950 dark:text-stone-300 dark:hover:bg-stone-800 dark:hover:text-white' => $variant === 'ghost',
    ];
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
