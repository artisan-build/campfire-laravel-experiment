@props(['label'])
<label {{ $attributes->class('grid gap-2 text-sm font-semibold text-stone-700 dark:text-stone-200') }}>
    <span>{{ $label }}</span>
    {{ $slot }}
</label>
