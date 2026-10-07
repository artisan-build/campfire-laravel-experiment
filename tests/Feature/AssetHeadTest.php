<?php

namespace Tests\Feature;

use App\Support\Assets;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

final class AssetHeadTest extends TestCase
{
    public function test_the_default_entry_graph_excludes_turbo_action_cable_and_message_stimulus(): void
    {
        $this->fixture();
        $html = $this->get('/session/new')->assertOk()->getContent();

        $this->assertStringContainsString('/assets/campfire/application_json.js', $html);
        $application = file_get_contents(public_path('assets/campfire/application_json.js'));
        $this->assertStringContainsString('"./confirm.js"', $application);
        $this->assertStringContainsString('"./message_stream.js"', $application);
        $this->assertStringNotContainsString('@hotwired/turbo-rails', $html);
        $this->assertStringNotContainsString('@rails/actioncable', $html);
        $this->assertStringNotContainsString('controllers/messages_controller', $html);
        $this->assertStringNotContainsString('campfire/echo/stream_source', $html);
        $this->assertStringNotContainsString('data-turbo-track', $html);
    }

    public function test_livewire_is_the_only_alpine_owner_and_campfire_registrations_are_preserved(): void
    {
        $this->fixture();
        $html = $this->get('/session/new')->assertOk()->getContent();
        $alpine = file_get_contents(public_path('assets/campfire/alpine.js'));
        $messageStream = file_get_contents(public_path('assets/campfire/message_stream.js'));
        $application = file_get_contents(public_path('assets/campfire/application_json.js'));

        $this->assertSame(1, substr_count($html, 'livewire.js'));
        $this->assertMatchesRegularExpression('/<script src="[^"]*livewire\.js\?id=[^"]+"[^>]*data-update-uri=/', $html);
        $this->assertStringContainsString('window.livewireScriptConfig', $html);
        $this->assertLessThan(strpos($html, 'livewire.js'), strpos($html, 'window.livewireScriptConfig'));
        $this->assertStringNotContainsString('alpine.esm', $html);
        $this->assertStringNotContainsString('import Alpine', $alpine);
        $this->assertStringNotContainsString('Alpine.start()', $alpine);
        $this->assertStringContainsString('window.Livewire.start()', $application);
        $this->assertStringNotContainsString('import Alpine', $messageStream);
        $this->assertStringContainsString('document.addEventListener("livewire:init"', $alpine);
        $this->assertStringContainsString('document.addEventListener("livewire:init"', $messageStream);

        foreach (['appShell', 'clipboard', 'dropTarget', 'messagePopup', 'softKeyboard', 'webShare'] as $registration) {
            $this->assertStringContainsString('Alpine.data("'.$registration.'"', $alpine);
        }
        $this->assertStringContainsString('Alpine.data("messageStream"', $messageStream);
    }

    public function test_push_registration_waits_for_an_active_service_worker_without_polling(): void
    {
        $alpine = file_get_contents(public_path('assets/campfire/alpine.js'));
        $ready = 'registration = await navigator.serviceWorker.ready';
        $subscribe = 'registration.pushManager.subscribe';

        $this->assertStringContainsString('if (!registration.active) '.$ready, $alpine);
        $this->assertLessThan(strpos($alpine, $subscribe), strpos($alpine, $ready));
        $this->assertStringNotContainsString('setInterval', $alpine);
        $this->assertStringNotContainsString('setTimeout', $alpine);
    }

    public function test_logout_always_reaches_the_server_when_push_cleanup_fails(): void
    {
        $alpine = file_get_contents(public_path('assets/campfire/alpine.js'));
        $logout = substr($alpine, strpos($alpine, 'Alpine.data("logoutButton"'));

        $this->assertStringContainsString('catch {', $logout);
        $this->assertStringContainsString('finally {', $logout);
        $this->assertStringContainsString('await wire.logout(endpoint)', $logout);
        $this->assertLessThan(strpos($logout, 'await wire.logout(endpoint)'), strpos($logout, 'endpoint = subscription.endpoint'));
    }

    public function test_native_module_graph_is_transitively_closed_without_bare_specifiers(): void
    {
        $this->assertModuleGraphCloses(app(Assets::class)->head());
    }

    public function test_every_turbo_confirmation_has_a_default_path_confirmation_owner(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        $confirmations = 0;

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all('/<[^>]*data-turbo-confirm=(?:"[^"]*"|\'[^\']*\')[^>]*>/', $source, $matches);
            foreach ($matches[0] as $element) {
                $confirmations++;
                $this->assertStringContainsString('data-confirm=', $element, $file->getPathname());
            }
        }

        // Livewire-owned confirmations may leave no Turbo confirmations to inspect.
        if ($confirmations === 0) {
            $this->addToAssertionCount(1);
        }
        $this->assertStringContainsString('event.submitter?.dataset.confirm', file_get_contents(public_path('assets/campfire/confirm.js')));
    }

    public function test_the_head_emits_one_built_app_sheet_and_three_isolated_lexxy_sheets(): void
    {
        $this->fixture();
        $manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true);
        $response = $this->get('/session/new')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = (new DOMXPath($document))->query('//head/link[@rel="stylesheet"]');

        $this->assertNotFalse($links);
        $this->assertCount(4, $links);
        $hrefs = [];
        foreach ($links as $link) {
            $this->assertInstanceOf(DOMElement::class, $link);
            $hrefs[] = $link->getAttribute('href');
        }

        $this->assertSame(array_map(
            fn (string $logical): string => '/assets/'.$manifest[$logical],
            ['app.css', 'lexxy-variables.css', 'lexxy-content.css', 'lexxy-editor.css'],
        ), $hrefs);

        $appSheet = public_path('assets/'.$manifest['app.css']);
        $this->assertFileExists($appSheet);
        $this->assertMatchesRegularExpression('/^app-[a-f0-9]{8}\.css$/', $manifest['app.css']);
        $this->assertSame(substr(hash_file('sha256', $appSheet), 0, 8), substr($manifest['app.css'], 4, 8));
    }

    public function test_the_app_source_aggregates_every_legacy_non_lexxy_sheet(): void
    {
        $manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true);
        $source = file_get_contents(resource_path('css/app.css'));

        preg_match_all('/^@import\s+"\.\.\/\.\.\/public\/assets\/(?<asset>[^"]+)"(?<layer>[^;]*);$/m', $source, $imports, PREG_SET_ORDER);
        $this->assertNotEmpty($imports);
        foreach ($imports as $import) {
            $this->assertSame(' layer(components)', $import['layer'], "{$import['asset']} is outside the components layer");
        }

        foreach ($manifest as $logical => $fingerprinted) {
            if (! str_ends_with($logical, '.css') || $logical === 'app.css' || str_starts_with($logical, 'lexxy')) {
                continue;
            }

            $this->assertStringContainsString($fingerprinted, $source, "{$logical} is absent from the application aggregate");
        }
    }

    private function assertModuleGraphCloses(string $head): void
    {
        preg_match('/<script type="module" src="([^"]+)"><\/script>/', $head, $match);
        $pending = [public_path(ltrim($match[1], '/'))];
        $visited = [];

        while ($pending !== []) {
            $path = array_pop($pending);
            if (isset($visited[$path])) {
                continue;
            }

            $visited[$path] = true;
            $this->assertFileExists($path, "Imported module is missing: {$path}");
            $source = file_get_contents($path);
            preg_match_all('/^\s*(?:import|export)\s+(?:[^"\']*?\s+from\s+)?["\']([^"\']+)["\']/m', $source, $imports);
            preg_match_all('/^\s*import\s*\(\s*["\']([^"\']+)["\']\s*\)/m', $source, $dynamicImports);

            foreach (array_merge($imports[1], $dynamicImports[1]) as $dependency) {
                if (str_starts_with($dependency, '.')) {
                    $resolved = realpath(dirname($path).'/'.$dependency);
                    $this->assertNotFalse($resolved, "Unresolved relative module {$dependency} imported by {$path}");
                    $pending[] = $resolved;
                } else {
                    $this->fail("Bare module specifier {$dependency} imported by {$path}");
                }
            }
        }
    }
}
