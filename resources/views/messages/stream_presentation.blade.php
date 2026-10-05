@if($message->attachment?->blob)
    @php($blob = $message->attachment->blob)
    @php($storage = app(\App\Support\BlobStorage::class))
    @php($url = $storage->url($blob))
    @if(str_starts_with($blob->content_type ?? '', 'video/'))
        <video src="{{ $url }}" poster="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => 'webp']) }}" controls preload="none" class="message__attachment"></video>
    @elseif(str_starts_with($blob->content_type ?? '', 'image/') || $blob->content_type === 'application/pdf')
        <a href="{{ $url }}" class="flex" data-stream-action="lightbox" data-download-url="{{ $url }}?disposition=attachment"><img src="{{ $storage->representationUrl($blob, ['resize_to_limit' => [1200, 800], 'format' => $storage->thumbnailFormat($blob)]) }}" alt="{{ $blob->filename }}" class="message__attachment" loading="lazy"></a>
    @else
        <a href="{{ $url }}?disposition=attachment">{{ $blob->filename }}</a>
    @endif
@else
    <div class="lexxy-content">{!! app(\App\Support\RichTextRenderer::class)->html($message->richText?->body ?? '') !!}</div>
@endif
