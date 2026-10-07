<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RouteContractTest extends TestCase
{
    public function test_canonical_named_routes_generate_for_test_created_ids(): void
    {
        [$user, $room] = $this->fixture();

        $this->assertSame(url('/rooms/'.$room->id), route('rooms.show', $room));
        $this->assertSame(url('/users/'.$user->id), route('users.show', $user));
        $this->assertSame(url('/account/bots/731/edit'), route('bots.edit', ['id' => 731]));
    }

    public function test_message_bot_and_active_storage_method_uri_contracts_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $expected = [
            'messages.index' => ['GET', 'rooms/{room}/messages'],
            'messages.store' => ['POST', 'rooms/{room}/messages'],
            'messages.show' => ['GET', 'rooms/{room}/messages/{id}'],
            'messages.update' => ['PUT', 'rooms/{room}/messages/{id}'],
            'messages.destroy' => ['DELETE', 'rooms/{room}/messages/{id}'],
            'bots.api' => ['POST', 'rooms/{room}/{key}/messages/{id?}'],
            'bots.boost' => ['POST', 'rooms/{room}/{key}/messages/{id}/boosts/{boost?}'],
            'storage.blob' => ['GET', 'rails/active_storage/blobs/redirect/{signed}/{filename}'],
            'storage.disk.store' => ['PUT', 'rails/active_storage/disk/{signed}'],
            'storage.disk.show' => ['GET', 'rails/active_storage/disk/{signed}/{filename}'],
            'storage.representation' => ['GET', 'rails/active_storage/representations/redirect/{signed}/{variation}/{filename}'],
            'storage.direct-uploads' => ['POST', 'rails/active_storage/direct_uploads'],
        ];

        foreach ($expected as $name => [$method, $uri]) {
            $route = $routes->firstWhere('action.as', $name);
            $this->assertNotNull($route, $name);
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains($method, $route->methods(), $name);
        }

        $this->assertFalse($routes->contains(fn ($route) => $route->uri() === 'messages/{id?}'));
    }

    public function test_blade_internal_link_and_form_destinations_use_named_routes(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());
            if (preg_match('/<(?:a|form|x-ui\.button)\b[^>]*(?:href|action)="\/(?!\/)/i', $source)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_indirect_blade_destinations_use_named_routes(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());
            if (preg_match('/\$permalink\s*=\s*["\']\/rooms\//', $source)
                || preg_match('/data-refresh(?:-room)?-url(?:-value)?="\//', $source)
                || preg_match('/\?\?\s*["\']\/account\/logo["\']/', $source)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
