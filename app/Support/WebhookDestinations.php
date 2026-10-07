<?php

namespace App\Support;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookDestinations
{
    public const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;

    /** @var list<string> */
    private const PUBLIC_IPV4_UNICAST_RANGES = [
        '1.0.0.0/8',
        '2.0.0.0/7',
        '4.0.0.0/6',
        '8.0.0.0/5',
        '16.0.0.0/4',
        '32.0.0.0/3',
        '64.0.0.0/2',
        '128.0.0.0/2',
        '192.0.0.0/3',
    ];

    /** @var list<string> */
    private const IANA_GLOBAL_IPV4_RANGES = [
        '192.0.0.9/32',
        '192.0.0.10/32',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.175.48.0/24',
    ];

    /** @var list<string> */
    private const NON_GLOBAL_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const GLOBAL_UNICAST_IPV6_RANGE = '2000::/3';

    /** @var list<string> */
    private const IANA_GLOBAL_IPV6_RANGES = [
        '2001:1::1/128',
        '2001:1::2/128',
        '2001:1::3/128',
        '2001:3::/32',
        '2001:4:112::/48',
        '2001:30::/28',
        '2620:4f:8000::/48',
    ];

    /** @var list<string> */
    private const NON_GLOBAL_IPV6_RANGES = [
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
    ];

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

        $hostIsAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $hostIsAddress && ! $this->validHostname($host)) {
            return null;
        }

        $resolvedAddresses = $hostIsAddress ? [$host] : ($this->resolver ? ($this->resolver)($host) : $this->resolveHost($host));
        $addresses = [];
        foreach (array_values(array_unique(array_filter($resolvedAddresses, 'is_string'))) as $address) {
            $normalized = $this->normalizePublicAddress($address);
            if ($normalized === null) {
                return null;
            }

            $addresses[] = $normalized;
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            return null;
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
    public function requestOptions(array $destination, BoundedResponseStream $sink): array
    {
        return [
            ...$this->connectionOptions($destination),
            'decode_content' => false,
            'headers' => ['Accept-Encoding' => 'identity'],
            'on_headers' => function (ResponseInterface $response) use ($sink): void {
                $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                if ($encoding !== '' && $encoding !== 'identity') {
                    $sink->reject('Encoded webhook responses are not accepted.');
                }

                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                    $sink->reject('Webhook response exceeded the allowed size.');
                }
            },
            'sink' => $sink,
        ];
    }

    /** @param array{host: string, port: int, ip: string} $destination */
    public function connectionOptions(array $destination): array
    {
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
            ],
        ];
    }

    private function validHostname(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('/^(?:0x[0-9a-f]+|[0-9]+(?:\.[0-9]+)*)$/iD', $host)) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function normalizePublicAddress(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);
        $normalized = $packed === false ? false : inet_ntop($packed);
        if ($normalized === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            if (! IpUtils::checkIp($normalized, self::PUBLIC_IPV4_UNICAST_RANGES)) {
                return null;
            }

            if (IpUtils::checkIp($normalized, self::IANA_GLOBAL_IPV4_RANGES)) {
                return $normalized;
            }

            return IpUtils::checkIp($normalized, self::NON_GLOBAL_IPV4_RANGES) ? null : $normalized;
        }

        if (! IpUtils::checkIp($normalized, self::GLOBAL_UNICAST_IPV6_RANGE)) {
            return null;
        }

        if (IpUtils::checkIp($normalized, self::IANA_GLOBAL_IPV6_RANGES)) {
            return $normalized;
        }

        return IpUtils::checkIp($normalized, self::NON_GLOBAL_IPV6_RANGES) ? null : $normalized;
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
