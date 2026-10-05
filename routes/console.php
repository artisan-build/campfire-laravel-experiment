<?php

use App\Support\BlobStorage;
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
 * What this instance actually resolved, and whether each resource answers. Prints names and never a
 * credential, so it is safe to run anywhere and paste the output.
 */
Artisan::command('campfire:doctor', function () {
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
})->purpose('Report the drivers this instance resolved and whether each resource answers');
