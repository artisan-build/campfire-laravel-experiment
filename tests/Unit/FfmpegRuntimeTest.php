<?php

namespace Tests\Unit;

use App\Support\FfmpegRuntime;
use App\Support\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FfmpegRuntimeTest extends TestCase
{
    public static function platforms(): array
    {
        return [
            'cloud, and the only one with an asset' => ['Linux', 'aarch64', 'linux-arm64', true],
            'linux arm64 reported as arm64' => ['Linux', 'arm64', 'linux-arm64', true],
            'linux intel' => ['Linux', 'x86_64', 'linux-x86_64', true],
            'linux intel reported as amd64' => ['Linux', 'amd64', 'linux-x86_64', true],
            'an apple silicon laptop has no vendored build' => ['Darwin', 'arm64', 'darwin-arm64', false],
            'nor does an intel one' => ['Darwin', 'x86_64', 'darwin-x86_64', false],
        ];
    }

    #[DataProvider('platforms')]
    public function test_only_the_linux_platforms_the_deploy_artifact_runs_on_have_a_pinned_asset(
        string $osFamily,
        string $machine,
        string $platform,
        bool $vendored,
    ): void {
        $runtime = new FfmpegRuntime($osFamily, $machine);

        $this->assertSame($platform, $runtime->platform());

        if (! $vendored) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("No pinned ffmpeg build for [{$platform}]");
            $runtime->asset();

            return;
        }

        $this->assertStringEndsWith('.tar.xz', $runtime->asset());
        $this->assertStringContainsString('lgpl', $runtime->asset());
    }

    public function test_the_install_directory_is_inside_the_application_root(): void
    {
        // Only the application root is the deploy artifact. A sibling directory looks persistent on a
        // live instance and is gone on the next deploy, which is the whole reason this is asserted.
        $this->assertStringStartsWith(base_path().'/', FfmpegRuntime::make()->directory());
        $this->assertSame(base_path('runtime/ffmpeg/bin'), FfmpegRuntime::make()->directory());
    }

    public function test_a_platform_with_no_pinned_build_never_trusts_the_directory(): void
    {
        // Verifying the build step means provisioning in a bind-mounted linux/arm64 container, which
        // leaves Linux ELF binaries in the directory that macOS happily calls is_executable(). Reading
        // them on the host dies with exit 126, so the platform gates the lookup.
        $directory = sys_get_temp_dir().'/campfire-ffmpeg-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);
        config()->set('campfire.ffmpeg.directory', $directory);

        try {
            foreach (['ffmpeg', 'ffprobe'] as $binary) {
                file_put_contents($directory.'/'.$binary, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
                chmod($directory.'/'.$binary, 0o755);
            }

            $linux = new FfmpegRuntime('Linux', 'aarch64');
            $this->assertTrue($linux->vendorable());
            $this->assertTrue($linux->provisioned());

            $macos = new FfmpegRuntime('Darwin', 'arm64');
            $this->assertFalse($macos->vendorable());
            $this->assertFalse($macos->installed('ffmpeg'));
            $this->assertFalse($macos->provisioned(), 'A Linux binary is not a provisioned runtime on macOS');
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function test_a_binary_is_only_reported_installed_when_it_is_present_and_executable(): void
    {
        $directory = sys_get_temp_dir().'/campfire-ffmpeg-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);
        config()->set('campfire.ffmpeg.directory', $directory);
        // The platform a Cloud instance is, so the assertions hold wherever this suite runs.
        $runtime = new FfmpegRuntime('Linux', 'aarch64');

        try {
            $this->assertFalse($runtime->installed('ffmpeg'));
            $this->assertFalse($runtime->provisioned());

            file_put_contents($directory.'/ffmpeg', '#!/bin/sh'.PHP_EOL);
            $this->assertFalse($runtime->installed('ffmpeg'), 'A non-executable file is not an installed binary');

            chmod($directory.'/ffmpeg', 0o755);
            $this->assertTrue($runtime->installed('ffmpeg'));
            $this->assertSame($directory.'/ffmpeg', $runtime->path('ffmpeg'));

            // ffprobe is still missing, so the pair is not provisioned.
            $this->assertFalse($runtime->provisioned());

            file_put_contents($directory.'/ffprobe', '#!/bin/sh'.PHP_EOL);
            chmod($directory.'/ffprobe', 0o755);
            $this->assertTrue($runtime->provisioned());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function test_media_prefers_the_vendored_runtime_over_whatever_is_on_path(): void
    {
        // The Rust sibling's bundle was never actually reached because its PATH was never prepended.
        // Looking the configured directory up directly is what removes that failure mode.
        $directory = sys_get_temp_dir().'/campfire-ffmpeg-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);
        config()->set('campfire.ffmpeg.directory', $directory);
        $this->app->instance(FfmpegRuntime::class, new FfmpegRuntime('Linux', 'aarch64'));

        try {
            file_put_contents($directory.'/ffmpeg', '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
            chmod($directory.'/ffmpeg', 0o755);

            $this->assertSame($directory.'/ffmpeg', (new Media)->binary('ffmpeg'));
        } finally {
            @unlink($directory.'/ffmpeg');
            @rmdir($directory);
        }
    }

    public function test_an_absent_runtime_falls_back_to_path_and_then_to_null(): void
    {
        config()->set('campfire.ffmpeg.directory', sys_get_temp_dir().'/campfire-ffmpeg-absent');

        // `sh` is on PATH everywhere this suite runs, so the fallback is observable without ffmpeg.
        $this->assertNotNull((new Media)->binary('sh'));
        $this->assertNull((new Media)->binary('campfire-no-such-binary'));
    }
}
