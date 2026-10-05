<?php

namespace App\Support;

final class Assets
{
    private array $manifest;

    public function __construct()
    {
        $this->manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true) ?: [];
    }

    public function path(string $name): string
    {
        return '/assets/'.($this->manifest[$name] ?? $name);
    }

    public function head(): string
    {
        $imports = json_decode(file_get_contents(resource_path('importmap.json')), true);
        $jsonStream = (bool) config('campfire.json_message_stream');

        if ($jsonStream) {
            $imports['imports']['application'] = 'ASSET:campfire/application_json.js';
            $imports['imports']['campfire/message_stream'] = 'ASSET:campfire/message_stream.js';

            foreach ([
                '@hotwired/turbo-rails',
                '@rails/actioncable',
                'campfire/echo',
                'campfire/echo/consumer',
                'campfire/echo/stream_source',
                'helpers/turbo_helpers',
                'controllers/boost_delete_controller',
                'controllers/composer_controller',
                'controllers/maintain_scroll_controller',
                'controllers/messages_controller',
                'controllers/notifications_controller',
                'controllers/presence_controller',
                'controllers/read_rooms_controller',
                'controllers/refresh_room_controller',
                'controllers/rooms_list_controller',
                'controllers/turbo_frame_controller',
                'controllers/turbo_streaming_controller',
                'controllers/typing_notifications_controller',
                'models/client_message',
                'models/message_formatter',
                'models/message_paginator',
                'models/scroll_manager',
                'models/typing_tracker',
            ] as $legacyImport) {
                unset($imports['imports'][$legacyImport]);
            }
        }

        foreach ($imports['imports'] as &$path) {
            if (str_starts_with($path, 'ASSET:')) {
                $path = $this->path(substr($path, 6));
            }
        }
        $s = '';
        foreach (['app.css', 'lexxy-variables.css', 'lexxy-content.css', 'lexxy-editor.css'] as $logical) {
            $s .= '<link rel="stylesheet" href="'.e($this->path($logical)).'"'.($jsonStream ? '' : ' data-turbo-track="reload"').'>';
        }

        return $s.'<script type="importmap">'.json_encode($imports, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script><script type="module">import "application"</script>';
    }
}
