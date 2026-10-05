<div id="boost_{{ $boost->id }}" class="boost boost-item flex-inline max-width align-center fill-white gap" data-boost-id="{{ $boost->id }}" data-booster-id="{{ $boost->booster_id }}">
    <figure class="avatar boost__avatar flex-item-no-shrink"><a class="btn avatar" href="/users/{{ $boost->booster_id }}"><img src="{{ $boost->booster->avatarUrl() }}" width="48" height="48" alt="{{ $boost->booster->name }} boosted {{ $boost->content }}"></a></figure>
    <span class="txt-small">{{ $boost->content }}</span>
    @if((int) $boost->booster_id === (int) $currentUser->id)<button type="button" class="btn btn--negative boost__delete" data-stream-action="remove-boost" aria-label="Delete this boost">−</button>@endif
</div>
