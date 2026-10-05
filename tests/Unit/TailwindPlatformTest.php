<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TailwindPlatform;

require_once __DIR__.'/../../scripts/TailwindPlatform.php';

final class TailwindPlatformTest extends TestCase
{
    public static function linuxPlatforms(): array
    {
        return [
            'glibc Linux' => ['x86_64', false, false, true, 'linux-x64'],
            'Alpine musl' => ['aarch64', true, false, false, 'linux-arm64-musl'],
            'non-Alpine musl' => ['x86_64', false, true, false, 'linux-x64-musl'],
        ];
    }

    #[DataProvider('linuxPlatforms')]
    public function test_linux_asset_selection_uses_the_detected_libc(
        string $machine,
        bool $alpine,
        bool $muslLoader,
        bool $glibcLoader,
        string $expected,
    ): void {
        $this->assertSame($expected, TailwindPlatform::select('Linux', $machine, $alpine, $muslLoader, $glibcLoader));
    }

    public function test_linux_with_no_identifiable_libc_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to classify the Linux libc safely.');

        TailwindPlatform::select('Linux', 'x86_64');
    }
}
