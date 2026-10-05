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
