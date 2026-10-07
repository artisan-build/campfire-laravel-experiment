<div id="presentation_message_{{ $message->client_message_id }}" dir="auto" @if($stream ?? false) data-stream-part="presentation" @endif>@if($message->attachment?->blob)
@php($blob = $message->attachment->blob)
@php($storage = app(\App\Support\BlobStorage::class))
@php($url = $storage->url($blob))
@if(str_starts_with($blob->content_type ?? '', 'video/'))
<video src="{{ $url }}" poster="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => 'webp']) }}" controls preload="none" class="message__attachment"></video>
@elseif(str_starts_with($blob->content_type ?? '', 'image/') || $blob->content_type === 'application/pdf')
<a href="{{ $url }}" class="flex" data-lightbox-url="{{ $url }}?disposition=attachment" @click.prevent="openLightbox($el)"><img src="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => $storage->thumbnailFormat($blob)]) }}" alt="{{ $blob->filename }}" class="message__attachment" loading="lazy"></a>
@else
<a href="{{ $url }}?disposition=attachment">{{ $blob->filename }}</a>
@endif
 @else @php($plain=$message->plainText()) @php($sounds=json_decode(file_get_contents(resource_path('sounds.json')),true)) @if(preg_match('/^\/play (\w+)$/',$plain,$match) && isset($sounds[$match[1]])) @php($sound=$sounds[$match[1]])<div class="sound" x-data="{ play() { new Audio(@js(app(\App\Support\Assets::class)->path($sound['asset']))).play() } }"><button class="btn btn--plain" @click="play()">Sound</button>@if($sound['image'])<img src="{{ app(\App\Support\Assets::class)->path($sound['image']['asset']) }}" width="{{ $sound['image']['width'] }}" height="{{ $sound['image']['height'] }}">@else {{ $sound['text'] }} @endif</div>@else {!! app(\App\Support\RichTextRenderer::class)->html($message->richText?->body ?? '') !!} @endif @endif</div>
