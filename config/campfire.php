<?php

return [
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

    // Cloud's managed Reverb caps a single message at 10 000 bytes. Anything larger is broadcast as a
    // pointer the client resolves over HTTP instead of as inline Turbo Stream HTML.
    'broadcast_payload_limit' => (int) env('CAMPFIRE_BROADCAST_PAYLOAD_LIMIT', 8000),
];
