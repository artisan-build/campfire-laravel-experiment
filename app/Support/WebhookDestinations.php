<?php

namespace App\Support;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookDestinations
{
    public const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;

    /** @var list<string> */
    private const IANA_GLOBAL_IPV4_RANGES = [
        '192.0.0.9/32',
        '192.0.0.10/32',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.175.48.0/24',
    ];

    /** @var list<string> */
    private const PHP_GLOBAL_IPV4_EXCLUSIONS = [
        '192.88.99.0/24',
        '224.0.0.0/4',
    ];

    /** @var list<string> */
    private const IANA_GLOBAL_IPV6_RANGES = [
        '2001:1::1/128',
        '2001:1::2/128',
        '2001:1::3/128',
        '2001:3::/32',
        '2001:4:112::/48',
        '2001:20::/28',
        '2001:30::/28',
        '2620:4f:8000::/48',
    ];

    /** @var list<string> */
    private const PHP_GLOBAL_IPV6_EXCLUSIONS = [
        '100:0:0:1::/64',
        '3fff::/20',
        '5f00::/16',
        'ff00::/8',
    ];

    /** @var list<string> */
    private const TRANSITION_IPV6_RANGES = [
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2001::/32',
        '2002::/16',
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

        $addresses = $hostIsAddress ? [$host] : ($this->resolver ? ($this->resolver)($host) : $this->resolveHost($host));
        $addresses = array_values(array_unique(array_filter($addresses, 'is_string')));
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->publicAddress($address)) {
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
    public function requestOptions(array $destination, BoundedResponseStream $sink): array
    {
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];

        return [
            'allow_redirects' => false,
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
            'proxy' => '',
            'sink' => $sink,
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

    private function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($address);
        $normalized = $packed === false ? false : inet_ntop($packed);
        if ($normalized === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return IpUtils::checkIp($normalized, self::IANA_GLOBAL_IPV4_RANGES)
                || (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false
                    && ! IpUtils::checkIp($normalized, self::PHP_GLOBAL_IPV4_EXCLUSIONS));
        }

        if (IpUtils::checkIp($normalized, self::TRANSITION_IPV6_RANGES)) {
            return false;
        }

        return IpUtils::checkIp($normalized, self::IANA_GLOBAL_IPV6_RANGES)
            || (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_GLOBAL_RANGE) !== false
                && ! IpUtils::checkIp($normalized, self::PHP_GLOBAL_IPV6_EXCLUSIONS));
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
