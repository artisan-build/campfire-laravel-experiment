<?php

return [
    // PR9 removes this temporary rollback switch and the retained Turbo path. The Laravel-native
    // JSON stream is the default and needs no installer or environment configuration.
    'json_message_stream' => true,

    // Signing key for Campfire's own cookies and signed ids. Upstream required SECRET_KEY_BASE to be
    // supplied. It falls back to a value derived from APP_KEY, which Cloud generates, so a fresh
    // install needs no operator-supplied value. Setting SECRET_KEY_BASE still works and is what an
    // install migrating from the Rails app would do.
    'secret' => env('SECRET_KEY_BASE') ?: hash_hmac('sha256', 'campfire-secret-key-base', (string) env('APP_KEY')),

    // Web Push identity. Generated once and stored on the account row when unset, so it survives
    // redeploys and is identical on every instance without anybody setting an environment variable.
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.org'),
    ],

    // Video posters and video metadata need ffmpeg and ffprobe, and Laravel Cloud's PHP runtime has
    // neither. `campfire:provision-ffmpeg` installs a pinned static build into `directory` during the
    // build step, and App\Support\Media looks there before it looks at PATH — so a Cloud instance
    // needs no environment variable and a developer's own ffmpeg on PATH keeps working untouched.
    'ffmpeg' => [
        // base_path() because only the application root is the deploy artifact: anything installed
        // beside it looks persistent on a live instance and is gone on the next deploy.
        'directory' => base_path('runtime/ffmpeg/bin'),

        // BtbN prunes daily autobuilds and retains month-end tags, so only a month-end tag is safe to
        // pin. This is the same build Slate runs, which is where the LGPL-not-GPL choice was settled:
        // the LGPL build has every decoder Campfire reads and the PNG encoder it writes, and needs no
        // GPL component. The checksum is read from the release's own checksums.sha256, never pinned
        // here, so a tag bump is a one-line change.
        'release_tag' => 'autobuild-2026-08-31-13-27',
        'assets' => [
            'linux-arm64' => 'ffmpeg-n9.0.1-11-ge47273f4d9-linuxarm64-lgpl-9.0.tar.xz',
            'linux-x86_64' => 'ffmpeg-n9.0.1-11-ge47273f4d9-linux64-lgpl-9.0.tar.xz',
        ],
    ],

    // Cloud's managed Reverb caps a single message at 10 000 bytes. Anything larger is gzipped, and
    // anything still larger is broadcast as a pointer the client resolves over HTTP.
    //
    // The budget is measured against the raw fragment, but the frame on the wire is that payload
    // JSON-encoded and then escaped again inside the Pusher envelope, and how much that inflates
    // depends on the content. Measured: a 7 842-byte fragment of plain text becomes a 9 933-byte
    // frame — 67 bytes of margin — while 120 fire emoji add 480 bytes of HTML and 2 144 bytes of
    // frame, so a fragment inside an 8 000-byte budget can still produce a frame over the ceiling.
    // 7 000 is below the floor of a message fragment (~7 250 bytes with no body at all), so every
    // message takes the gzip branch and lands under 3 000, which is where they all were before the
    // CSRF tokens came out of the markup. Small fragments — a boost, a removal, the sidebar — are
    // well under it and still ship inline.
    'broadcast_payload_limit' => (int) env('CAMPFIRE_BROADCAST_PAYLOAD_LIMIT', 7000),
];
