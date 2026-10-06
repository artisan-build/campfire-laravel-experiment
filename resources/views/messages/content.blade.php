@php($assets = app(\App\Support\Assets::class))
@php($permalink = "/rooms/".$message->room_id."/@".$message->id)
@php($dom = "message_".$message->client_message_id)
<div class="message__body"><div class="message__body-content"><div class="message__meta"><h3 class="message__heading">
<span class="message__author" title="{{ trim($message->creator->name.' – '.$message->creator->bio,' –') }}"><strong data-reply-target="author">{{ $message->creator->name }}</strong></span>
<a class="message__permalink" href="{{ $permalink }}" target="_top"><time datetime="{{ $message->created_at->toISOString() }}" class="message__timestamp" data-local-time-target="time">{{ $message->created_at->format('g:i A') }}</time></a>
<span class="message__room"><a href="{{ $permalink }}" target="_top" data-reply-target="link">{{ $message->room->displayName() }}</a></span>
</h3>
<div class="message__actions" x-data="softKeyboard">
<details class="position-relative" x-data="messagePopup" x-ref="popup" @keydown.escape.stop="close()" @toggle="orient()" @click.outside="close()" :class="{ 'popup-orientation-top': opensUp }">
<summary class="btn message__action-btn message__options-btn"><img src="{{ $assets->path('menu-dots-horizontal.svg') }}" width="20" height="20" class="colorize--black" aria-hidden="true"><span class="for-screen-reader">Message options</span></summary>
<div class="message__actions-menu border shadow" x-ref="menu" :style="`--max-width: ${maxWidth}px`">
{{-- Rendered into broadcasts, so it must carry nothing session-scoped: a CSRF token here would be the POSTER's, delivered to every other member of the room. Turbo sends each viewer's own token as X-CSRF-Token from <meta name="csrf-token">, which RailsCsrf accepts. --}}
<div class="quick-boosts">
@foreach(['👍'=>'Thumbs up','👏'=>'Clapping','👋'=>'Waving hand','💪'=>'Muscle','❤️'=>'Red heart','😂'=>'Face with tears of joy','🎉'=>'Party popper','🔥'=>'Fire'] as $emoji=>$label)
<form action="{{ route('boosts.store', $message, absolute: false) }}" method="post" data-turbo-frame="boosting_{{ $dom }}" @submit="close()"><input type="hidden" name="boost[content]" value="{{ $emoji }}"><button type="submit" title="{{ $label }}" class="btn message__action-btn" data-emoji="{{ $emoji }}"><figure class="margin-none boost-character">{{ $emoji }}</figure><span class="for-screen-reader">{{ $label }}</span></button></form>
@endforeach
<a href="{{ route('boosts.new', $message, absolute: false) }}" class="btn message__action-btn message__boost-btn" data-turbo-frame="new_boost_{{ $dom }}" @click="openSoftKeyboard(); close()"><img src="{{ $assets->path('boost.svg') }}" class="colorize--black" width="20" height="20" aria-hidden="true"><span class="for-screen-reader">New boost</span></a>
</div>
<div class="flex flex-wrap border-top margin-block-start-half pad-block-start-half message__actions-grid">
@if($message->attachment?->blob)
<a href="{{ app(\App\Support\BlobStorage::class)->url($message->attachment->blob) }}?disposition=attachment" class="btn message__action-btn center full-width hide-in-ios-pwa" title="Download" aria-label="Download"><img src="{{ $assets->path('download.svg') }}" aria-hidden="true" width="20" height="20"></a>
@else
<button class="btn message__action-btn center full-width" data-action="reply#reply" title="Reply" aria-label="Reply"><img src="{{ $assets->path('reply.svg') }}" class="colorize--black" aria-hidden="true" width="20" height="20"></button>
@endif
<button class="btn message__action-btn center full-width" title="Copy link" aria-label="Copy link" x-data="clipboard(@js(url($permalink)))" @click.prevent="copy()" :class="{ 'btn--success': copied }"><img src="{{ $assets->path('link.svg') }}" class="colorize--black" aria-hidden="true" width="20" height="20"></button>
<a href="{{ route('messages.edit', ['room' => $message->room_id, 'id' => $message->id], absolute: false) }}" class="btn message__action-btn center full-width message__edit-btn" data-turbo-frame="edit_{{ $dom }}" title="Edit" aria-label="Edit"><img src="{{ $assets->path('pencil.svg') }}" class="colorize--black" aria-hidden="true" width="20" height="20"></a>
</div></div></details></div></div>
@include('messages.presentation')
@include('boosts.index')
</div></div>
