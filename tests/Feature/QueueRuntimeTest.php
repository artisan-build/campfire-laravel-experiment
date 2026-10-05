<?php

namespace Tests\Feature;

use App\Jobs\ReportRuntime;
use App\Support\FfmpegRuntime;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The job host is a different host.
 *
 * Cloud's managed queue runs jobs off the application instance, so "ffmpeg is on the web container"
 * does not answer whether a job can make a poster — and DeliverMessageNotifications genuinely needs
 * one, because it creates an attachment when a bot's webhook replies with video/mp4.
 */
class QueueRuntimeTest extends TestCase
{
    /**
     * Run something with nothing on PATH, so "absent" is about the vendored runtime and not about
     * whatever the machine happens to have installed.
     */
    private function withoutPath(callable $callback): void
    {
        $path = getenv('PATH');
        putenv('PATH=');

        try {
            $callback();
        } finally {
            putenv('PATH='.$path);
        }
    }

    private function fakeRuntime(array $binaries): string
    {
        $directory = sys_get_temp_dir().'/campfire-ffmpeg-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);

        foreach ($binaries as $binary) {
            file_put_contents($directory.'/'.$binary, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
            chmod($directory.'/'.$binary, 0o755);
        }

        config()->set('campfire.ffmpeg.directory', $directory);
        // Pin the platform a Cloud instance is, so these hold on a developer's macOS too.
        $this->app->instance(FfmpegRuntime::class, new FfmpegRuntime('Linux', 'aarch64'));

        return $directory;
    }

    private function removeRuntime(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($directory);
    }

    public function test_the_worker_reports_the_ffmpeg_it_resolved_and_names_what_is_missing(): void
    {
        $directory = $this->fakeRuntime(['ffmpeg']);

        try {
            $this->withoutPath(function () use ($directory) {
                $token = bin2hex(random_bytes(8));
                (new ReportRuntime($token))->handle();

                $report = Cache::get(ReportRuntime::cacheKey($token));

                $this->assertNotNull($report, 'The worker must leave its answer where the dispatcher can read it');
                $this->assertSame($directory.'/ffmpeg', $report['ffmpeg']);
                $this->assertSame(gethostname(), $report['host']);
                $this->assertSame(base_path(), $report['base_path']);

                // Only ffmpeg was installed. An absent binary has to come back as an explicit null,
                // because `campfire:doctor --queue` prints "absent" from it — a missing key would
                // print nothing at all and read as fine.
                $this->assertArrayHasKey('ffprobe', $report);
                $this->assertNull($report['ffprobe']);
            });
        } finally {
            $this->removeRuntime($directory);
        }
    }

    public function test_doctor_queue_reports_a_worker_that_resolved_the_vendored_runtime(): void
    {
        // QUEUE_CONNECTION is `sync` in this suite, so the "worker" is this process — which proves the
        // reporting path, not that two hosts agree. Two hosts is a live claim, verified on Cloud.
        $directory = $this->fakeRuntime(['ffmpeg', 'ffprobe']);

        try {
            $this->assertTrue(app(FfmpegRuntime::class)->provisioned());

            $this->artisan('campfire:doctor --queue')
                ->expectsOutputToContain('the same host as above')
                ->expectsOutputToContain($directory.'/ffmpeg')
                ->assertExitCode(0);
        } finally {
            $this->removeRuntime($directory);
        }
    }

    public function test_doctor_queue_reports_an_absent_runtime_rather_than_staying_silent(): void
    {
        $directory = $this->fakeRuntime([]);

        try {
            $this->withoutPath(function () {
                $this->artisan('campfire:doctor --queue')
                    ->expectsOutputToContain('absent (a bot video reply would get no poster)')
                    ->expectsOutputToContain('absent (a bot video reply would get no metadata)')
                    ->assertExitCode(0);
            });
        } finally {
            $this->removeRuntime($directory);
        }
    }

    public function test_doctor_without_the_flag_says_nothing_about_a_worker(): void
    {
        $this->artisan('campfire:doctor')
            ->doesntExpectOutputToContain('what the WORKER resolved')
            ->assertExitCode(0);
    }
}
