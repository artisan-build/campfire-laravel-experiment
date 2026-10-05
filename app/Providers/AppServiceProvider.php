<?php

namespace App\Providers;

use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\RailsCrypto;
use App\Support\RichTextRenderer;
use App\Support\Vapid;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RichTextRenderer::class);
        $this->app->singleton(Assets::class);
        $this->app->singleton(BlobStorage::class);
        $this->app->singleton(RailsCrypto::class);
        $this->app->singleton(Vapid::class);
    }
}
