<?php

namespace App\Support;

use Closure;

final class WebhookDestinations
{
    public const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;

    public function __construct(private ?Closure $resolver = null) {}

    /** @return array{host: string, port: int, ip: string}|null */
    public function resolve(string $url): ?array
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($host === '' || $port < 1) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : ($this->resolver ? ($this->resolver)($host) : $this->resolveHost($host));
        $addresses = array_values(array_unique(array_filter($addresses, 'is_string')));
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public function validationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $this->resolve($value) === null) {
                $fail("The {$attribute} must resolve only to public network addresses.");
            }
        };
    }

    /** @param array{host: string, port: int, ip: string} $destination */
    public function requestOptions(array $destination): array
    {
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];

        return [
            'allow_redirects' => false,
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip]],
        ];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
