@php($permalink = '/rooms/'.$message->room_id.'/@'.$message->id)
<article id="message_{{ $message->client_message_id }}" class="message" data-message-id="{{ $message->id }}" data-client-message-id="{{ $message->client_message_id }}" data-user-id="{{ $message->creator_id }}" data-message-timestamp="{{ $message->created_at->getTimestampMs() }}" data-message-updated-at="{{ $message->updated_at->getTimestampMs() }}" data-message-url="{{ url('/rooms/'.$message->room_id.'/messages/'.$message->id) }}">
    <h2 class="message__day-separator"><time datetime="{{ $message->created_at->toISOString() }}" data-stream-time="date">{{ $message->created_at->format('F j, Y') }}</time></h2>
    <figure class="avatar message__avatar"><a title="{{ $message->creator->name }}" class="btn avatar" href="/users/{{ $message->creator_id }}" data-stream-part="author-link"><img src="{{ $message->creator->avatarUrl() }}" width="48" height="48" alt="" data-stream-part="avatar"></a></figure>
    <div class="message__body"><div class="message__body-content"><div class="message__meta"><h3 class="message__heading">
        <span class="message__author"><strong data-stream-part="author">{{ $message->creator->name }}</strong></span>
        <a class="message__permalink" href="{{ $permalink }}" data-stream-part="permalink"><time datetime="{{ $message->created_at->toISOString() }}" class="message__timestamp" data-stream-time="time">{{ $message->created_at->format('g:i A') }}</time></a>
        <span class="message__room" data-stream-part="room">{{ $message->room->displayName() }}</span>
    </h3>
    <div class="message__actions"><details class="position-relative"><summary class="btn message__action-btn message__options-btn"><img src="{{ app(\App\Support\Assets::class)->path('menu-dots-horizontal.svg') }}" width="20" height="20" class="colorize--black" aria-hidden="true"><span class="for-screen-reader">Message options</span></summary>
        <div class="message__actions-menu border shadow"><div class="quick-boosts">
            @foreach(['👍'=>'Thumbs up','👏'=>'Clapping','👋'=>'Waving hand','💪'=>'Muscle','❤️'=>'Red heart','😂'=>'Face with tears of joy','🎉'=>'Party popper','🔥'=>'Fire'] as $emoji=>$label)<button type="button" title="{{ $label }}" class="btn message__action-btn" data-stream-action="boost" data-boost-content="{{ $emoji }}"><figure class="margin-none boost-character">{{ $emoji }}</figure><span class="for-screen-reader">{{ $label }}</span></button>@endforeach
            <button type="button" class="btn message__action-btn message__boost-btn" data-stream-action="custom-boost"><span>Add boost</span></button>
        </div><div class="flex flex-wrap border-top margin-block-start-half pad-block-start-half message__actions-grid">
            <button type="button" class="btn message__action-btn center full-width" data-stream-action="reply">Reply</button>
            <button type="button" class="btn message__action-btn center full-width" data-stream-action="copy">Copy link</button>
            <button type="button" class="btn message__action-btn center full-width message__edit-btn" data-stream-action="edit" data-owner-action @if(! $currentUser->canAdminister($message)) hidden @endif>Edit</button>
            <button type="button" class="btn btn--negative message__action-btn center full-width" data-stream-action="delete" data-owner-action @if(! $currentUser->canAdminister($message)) hidden @endif>Delete</button>
        </div></div>
    </details></div></div>
    <div id="presentation_message_{{ $message->client_message_id }}" dir="auto" data-stream-part="presentation">@include('messages.stream_presentation')</div>
    <div class="boosts flex flex-wrap align-center gap full-width"><div class="flex-inline flex-wrap gap" data-stream-part="boosts">@foreach($message->boosts as $boost)@include('boosts.stream_boost')@endforeach</div><button type="button" class="btn boost__action txt-small" data-stream-action="custom-boost">Add a boost</button></div>
    </div></div>
</article>
