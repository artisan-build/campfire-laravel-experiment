<?php

namespace App\Providers;

use App\Auth\CampfireSession;
use App\Http\Middleware\AuthenticateCampfire;
use App\Models\Message;
use App\Models\Room;
use App\Policies\MessagePolicy;
use App\Policies\RoomPolicy;
use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\RichTextRenderer;
use App\Support\SignedIdentifiers;
use App\Support\Vapid;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RichTextRenderer::class);
        $this->app->singleton(Assets::class);
        $this->app->singleton(BlobStorage::class);
        $this->app->singleton(SignedIdentifiers::class);
        $this->app->singleton(Vapid::class);
        $this->app->singleton(CampfireSession::class);
    }

    public function boot(): void
    {
        Auth::viaRequest('campfire', fn ($request) => app(CampfireSession::class)->user($request));
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Livewire::addPersistentMiddleware(AuthenticateCampfire::class);
    }
}
