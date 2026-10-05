<?php

namespace App\Jobs;

use App\Support\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * What the QUEUE WORKER resolved, reported back through the cache.
 *
 * Cloud's managed queue runs jobs off the application instance, so `campfire:doctor` on the web
 * container says nothing about the worker. That matters here and is not hypothetical:
 * DeliverMessageNotifications creates an attachment when a bot's webhook replies with video/mp4,
 * which takes a job through BlobStorage::attach() to ffmpeg and ffprobe.
 *
 * The cache is the channel because it is the one resource both hosts share and can write (Postgres,
 * in this app's Cloud configuration). Nothing here is a credential.
 */
final class ReportRuntime implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $key) {}

    public static function cacheKey(string $token): string
    {
        return 'campfire:doctor:runtime:'.$token;
    }

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->key), [
            'host' => gethostname(),
            'php' => PHP_VERSION,
            'base_path' => base_path(),
            'ffmpeg' => app(Media::class)->binary('ffmpeg'),
            'ffprobe' => app(Media::class)->binary('ffprobe'),
            'reported_at' => now()->toIso8601String(),
        ], 300);
    }
}
