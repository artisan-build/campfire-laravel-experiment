<?php

declare(strict_types=1);

final class TailwindPlatform
{
    public static function current(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return self::select(PHP_OS_FAMILY, php_uname('m'));
        }

        return self::select(
            PHP_OS_FAMILY,
            php_uname('m'),
            is_file('/etc/alpine-release'),
            self::hasLoader([
                '/lib/ld-musl-*.so.1',
                '/lib/*/ld-musl-*.so.1',
                '/usr/lib/ld-musl-*.so.1',
                '/usr/lib/*/ld-musl-*.so.1',
            ]),
            self::hasLoader([
                '/lib/ld-linux-*.so.*',
                '/lib64/ld-linux-*.so.*',
                '/lib/*/ld-linux-*.so.*',
                '/usr/lib/ld-linux-*.so.*',
                '/usr/lib/*/ld-linux-*.so.*',
            ]),
        );
    }

    public static function select(
        string $osFamily,
        string $machine,
        bool $alpine = false,
        bool $muslLoader = false,
        bool $glibcLoader = false,
    ): string {
        $architecture = match (strtolower($machine)) {
            'arm64', 'aarch64' => 'arm64',
            'amd64', 'x86_64' => 'x64',
            default => throw new RuntimeException("Unsupported Tailwind architecture [{$machine}]."),
        };

        if ($osFamily !== 'Linux') {
            return match ($osFamily) {
                'Darwin' => 'macos-'.$architecture,
                'Windows' => 'windows-'.$architecture,
                default => throw new RuntimeException("Unsupported Tailwind operating system [{$osFamily}]."),
            };
        }

        if ($alpine) {
            return 'linux-'.$architecture.'-musl';
        }

        if ($muslLoader === $glibcLoader) {
            throw new RuntimeException('Unable to classify the Linux libc safely.');
        }

        return 'linux-'.$architecture.($muslLoader ? '-musl' : '');
    }

    /** @param list<string> $patterns */
    private static function hasLoader(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ((glob($pattern) ?: []) !== []) {
                return true;
            }
        }

        return false;
    }
}
