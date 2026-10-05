@extends('layouts.app', ['title' => 'Room notifications'])
@section('nav')<x-ui.button href="/rooms/{{ $membership->room_id }}" variant="ghost">Back to room</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8">
<turbo-frame id="involvement_{{ $membership->room_id }}">
    <form action="/rooms/{{ $membership->room_id }}/involvement" method="post" class="mx-auto grid w-full max-w-xl gap-4 rounded-3xl border border-stone-200 bg-white p-5 shadow-sm sm:p-8 dark:border-stone-700 dark:bg-stone-900" data-testid="room-notifications">
        @csrf
        @method('PATCH')
        <fieldset class="grid gap-3">
            <legend class="mb-1 text-2xl font-black tracking-tight">Notifications</legend>
            @foreach(['invisible' => 'Hide room', 'nothing' => 'Nothing', 'mentions' => 'Mentions', 'everything' => 'Everything'] as $value => $label)
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-stone-200 px-3 py-2 hover:border-orange-400 dark:border-stone-700">
                    <input class="size-4 accent-orange-500" type="radio" name="involvement" value="{{ $value }}" @checked($membership->involvement === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </fieldset>
        <x-ui.button type="submit" variant="primary">Save notifications</x-ui.button>
    </form>
</turbo-frame>
</div>
@endsection
