<?php

use App\Jobs\ReportRuntime;
use App\Support\BlobStorage;
use App\Support\FfmpegRuntime;
use App\Support\Media;
use App\Support\Presence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Upstream's campfire:install executed database/schema.sql, faked Rails' schema_migrations rows,
 * created a second SQLite file for the queue and wrote storage/vapid.json. All four are gone: the
 * schema is real migrations, the queue is Cloud's managed queue (or the same database locally), and
 * the Web Push keys live on the account row.
 */
Artisan::command('campfire:install', function () {
    $this->call('migrate', ['--force' => true]);
    $this->info('Campfire schema ready');
})->purpose('Run the migrations needed to serve Campfire');

/**
 * Vendor ffmpeg and ffprobe into the deploy artifact.
 *
 * Laravel Cloud's PHP runtime has neither, so this runs in the BUILD step:
 *
 *     cloud environment:update production --force \
 *         --build-command="<the existing command> && php artisan campfire:provision-ffmpeg"
 *
 * The build log is NOT evidence that it worked — the Rust sibling's bundle appeared to install and
 * then never reached the application. Verify with campfire:doctor on the instance.
 */
Artisan::command('campfire:provision-ffmpeg {--force}', function (): int {
    $runtime = FfmpegRuntime::make();

    if (! $this->option('force') && $runtime->provisioned()) {
        $this->info('ffmpeg is already installed at '.$runtime->directory().'; pass --force to reinstall.');

        return 0;
    }

    try {
        $result = $runtime->install();
    } catch (Throwable $error) {
        $this->error($error->getMessage());

        return 1;
    }

    $this->table(['what', 'value'], [
        ['asset', $result['asset']],
        ['extracted', sprintf('%.1f MB', $result['extracted'] / 1024 / 1024)],
        ['installed', sprintf('%.1f MB', $result['installed'] / 1024 / 1024)],
        ['ffmpeg', $runtime->path('ffmpeg')],
        ['ffprobe', $runtime->path('ffprobe')],
    ]);

    return 0;
})->purpose('Install the pinned static ffmpeg/ffprobe into the application root');

/**
 * What this instance actually resolved, and whether each resource answers. Prints names and never a
 * credential, so it is safe to run anywhere and paste the output.
 */
Artisan::command('campfire:doctor {--queue : Also report what a QUEUE WORKER resolved, which on Cloud is a different host}', function () {
    $rows = [
        ['instance', gethostname()],
        ['php', PHP_VERSION],
        ['env', app()->environment()],
        ['database', config('database.default')],
        ['cache', config('cache.default')],
        ['session', config('session.driver')],
        ['queue', config('queue.default')],
        ['broadcasting', config('broadcasting.default')],
        ['disk', config('filesystems.default')],
        ['disk signs responses', app(BlobStorage::class)->signsResponses() ? 'yes' : 'no (streams instead)'],
        ['imagick', extension_loaded('imagick') ? 'yes' : 'no'],
        ['gd', extension_loaded('gd') ? 'yes' : 'no'],
        ['ffmpeg', app(Media::class)->binary('ffmpeg') ?? 'absent (no video posters)'],
        ['ffprobe', app(Media::class)->binary('ffprobe') ?? 'absent (no video metadata)'],
        ['reverb host', config('broadcasting.connections.reverb.options.host') ?: 'unset'],
    ];

    // Presence is read from Reverb on the message-post path, so its round trip is a latency cost
    // every message pays. Measure it from where it actually happens.
    $samples = [];
    for ($i = 0; $i < 5; $i++) {
        $started = hrtime(true);
        app(Presence::class)->inRoom(1);
        $samples[] = (hrtime(true) - $started) / 1e6;
    }
    sort($samples);
    $rows[] = ['reverb presence lookup', sprintf('%.0f ms median of 5 (min %.0f, max %.0f)', $samples[2], $samples[0], $samples[4])];

    try {
        DB::select('select 1');
        $rows[] = ['database reachable', 'yes, '.DB::table('messages')->count().' messages'];
    } catch (Throwable $error) {
        $rows[] = ['database reachable', 'NO: '.$error->getMessage()];
    }

    try {
        Cache::put('campfire:doctor', 'ok', 10);
        $rows[] = ['cache reachable', Cache::get('campfire:doctor') === 'ok' ? 'yes' : 'wrote but did not read back'];
    } catch (Throwable $error) {
        $rows[] = ['cache reachable', 'NO: '.$error->getMessage()];
    }

    try {
        $disk = Storage::disk(config('filesystems.default'));
        $path = 'doctor/'.bin2hex(random_bytes(6));
        $disk->put($path, 'ok');
        $read = $disk->get($path);
        $disk->delete($path);
        $rows[] = ['disk writable', $read === 'ok' ? 'yes, round-tripped '.$path : 'wrote but read back '.var_export($read, true)];
    } catch (Throwable $error) {
        $rows[] = ['disk writable', 'NO: '.$error->getMessage()];
    }

    $this->table(['what', 'value'], $rows);

    if (! $this->option('queue')) {
        return 0;
    }

    // Cloud's managed queue runs jobs off this container, and DeliverMessageNotifications reaches
    // ffmpeg whenever a bot's webhook replies with video, so "ffmpeg is here" is only half an answer.
    $token = bin2hex(random_bytes(8));
    ReportRuntime::dispatch($token);
    $deadline = microtime(true) + 60;
    $report = null;

    while (microtime(true) < $deadline) {
        $report = Cache::get(ReportRuntime::cacheKey($token));

        if ($report !== null) {
            break;
        }

        usleep(500_000);
    }

    if ($report === null) {
        $this->error('No queue worker answered within 60s. Either none is running, or it cannot reach the cache.');

        return 1;
    }

    $this->newLine();
    $this->table(['what the WORKER resolved', 'value'], [
        ['worker host', $report['host'].(($report['host'] === gethostname()) ? ' (the same host as above)' : ' (a different host)')],
        ['worker php', $report['php']],
        ['worker base path', $report['base_path']],
        ['worker ffmpeg', $report['ffmpeg'] ?? 'absent (a bot video reply would get no poster)'],
        ['worker ffprobe', $report['ffprobe'] ?? 'absent (a bot video reply would get no metadata)'],
        ['reported at', $report['reported_at']],
    ]);

    return 0;
})->purpose('Report the drivers this instance resolved and whether each resource answers');
