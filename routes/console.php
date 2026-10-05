<?php

use Illuminate\Support\Facades\Artisan;

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
