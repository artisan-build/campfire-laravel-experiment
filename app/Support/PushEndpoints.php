<?php

namespace App\Support;

final class PushEndpoints
{
    public function __construct(private WebhookDestinations $destinations) {}

    /** @return array{host: string, port: int, ip: string}|null */
    public function resolve(string $endpoint): ?array
    {
        $u = parse_url($endpoint);
        if (! $u || ($u['scheme'] ?? '') !== 'https' || ($u['port'] ?? 443) !== 443 || isset($u['user']) || isset($u['pass'])) {
            return null;
        }
        $host = strtolower($u['host'] ?? '');
        $allowed = false;
        foreach (['jmt17.google.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $allowed = true;
            }
        }
        if (! $allowed) {
            return null;
        }

        return $this->destinations->resolve($endpoint);
    }
}
