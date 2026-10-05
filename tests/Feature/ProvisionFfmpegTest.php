<?php

namespace Tests\Feature;

use App\Support\FfmpegRuntime;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The provisioner's refusals.
 *
 * The success path needs a real 90 MB archive and a Linux host, so CI's `ffmpeg (linux/arm64)` job
 * owns it. What belongs here is every reason the command must REFUSE to install, because an install
 * that proceeded anyway would be executing an unverified binary on every instance.
 */
class ProvisionFfmpegTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/campfire-ffmpeg-'.bin2hex(random_bytes(6));
        config()->set('campfire.ffmpeg.directory', $this->directory.'/ffmpeg/bin');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));

        parent::tearDown();
    }

    private function linuxRuntime(): FfmpegRuntime
    {
        return new FfmpegRuntime('Linux', 'aarch64');
    }

    public function test_a_checksum_mismatch_refuses_to_install_anything(): void
    {
        $asset = $this->linuxRuntime()->asset();

        Http::fake([
            '*/checksums.sha256' => Http::response(str_repeat('a', 64).'  '.$asset."\n"),
            '*/'.$asset => Http::response('not really an ffmpeg archive'),
        ]);

        try {
            $this->linuxRuntime()->install();
            $this->fail('An archive whose hash does not match must not be installed');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Checksum mismatch', $error->getMessage());
        }

        // Nothing survives a refusal: not the install, and not the staging directory either.
        $this->assertFalse($this->linuxRuntime()->provisioned());
        $this->assertDirectoryDoesNotExist($this->directory.'/ffmpeg/bin');
        $this->assertDirectoryDoesNotExist($this->directory.'/ffmpeg-staging');
    }

    public function test_a_release_that_publishes_no_checksum_for_the_asset_refuses_to_install(): void
    {
        Http::fake([
            '*/checksums.sha256' => Http::response(str_repeat('b', 64)."  some-other-asset.tar.xz\n"),
            '*' => Http::response('archive bytes'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publishes no SHA-256');

        $this->linuxRuntime()->install();
    }

    public function test_the_checksum_is_matched_on_the_whole_filename_not_a_suffix_of_one(): void
    {
        // The release lists `…-lgpl-9.0.tar.xz` beside `…-lgpl-shared-9.0.tar.xz`. Matching loosely
        // would install one build against the other's hash, which is a mismatch that reads as a pass.
        $asset = $this->linuxRuntime()->asset();
        $decoy = str_replace('-lgpl-', '-lgpl-shared-', $asset);
        $this->assertNotSame($asset, $decoy);

        Http::fake([
            '*/checksums.sha256' => Http::response(
                str_repeat('c', 64).'  '.$decoy."\n".str_repeat('d', 64).'  '.$asset."\n"
            ),
            '*' => Http::response('archive bytes'),
        ]);

        try {
            $this->linuxRuntime()->install();
            $this->fail('Expected the download to be rejected');
        } catch (RuntimeException $error) {
            // It got as far as comparing, which means it found a checksum — and it has to be the one
            // belonging to this asset, never the decoy's.
            $this->assertStringContainsString('Checksum mismatch', $error->getMessage());
        }
    }

    public function test_a_platform_with_no_pinned_build_refuses_before_it_downloads_anything(): void
    {
        Http::fake();

        try {
            (new FfmpegRuntime('Darwin', 'arm64'))->install();
            $this->fail('A platform with no pinned asset must not install anything');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('No pinned ffmpeg build for [darwin-arm64]', $error->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_command_reports_a_failure_as_a_non_zero_exit(): void
    {
        // A build step that fails silently is the whole reason this is asserted: Cloud would carry on
        // and the app would quietly have no posters.
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('campfire:provision-ffmpeg --force')->assertExitCode(1);
    }

    public function test_an_already_provisioned_runtime_is_left_alone_without_force(): void
    {
        $bin = $this->directory.'/ffmpeg/bin';
        mkdir($bin, 0o755, true);

        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            file_put_contents($bin.'/'.$binary, '#!/bin/sh'.PHP_EOL);
            chmod($bin.'/'.$binary, 0o755);
        }

        $this->app->instance(FfmpegRuntime::class, $this->linuxRuntime());
        Http::fake();

        $this->artisan('campfire:provision-ffmpeg')
            ->expectsOutputToContain('already installed')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
