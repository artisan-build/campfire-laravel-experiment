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
        $this->assertStringContainsString('/assets/campfire/confirm.js', $html);
        $this->assertStringContainsString('/assets/campfire/message_stream.js', $html);
        $this->assertStringNotContainsString('@hotwired/turbo-rails', $html);
        $this->assertStringNotContainsString('@rails/actioncable', $html);
        $this->assertStringNotContainsString('controllers/messages_controller', $html);
        $this->assertStringNotContainsString('campfire/echo/stream_source', $html);
        $this->assertStringNotContainsString('data-turbo-track', $html);
    }

    public function test_the_rollback_flag_restores_the_legacy_entry_graph(): void
    {
        config(['campfire.json_message_stream' => false]);
        $this->fixture();
        $html = $this->get('/session/new')->assertOk()->getContent();

        $this->assertStringContainsString('/assets/campfire/application.js', $html);
        $this->assertStringContainsString('@hotwired/turbo-rails', $html);
        $this->assertStringContainsString('campfire/echo/stream_source', $html);
    }

    public function test_default_and_rollback_module_graphs_are_transitively_closed(): void
    {
        foreach ([true, false] as $jsonStream) {
            config(['campfire.json_message_stream' => $jsonStream]);
            $this->assertModuleGraphCloses(app(Assets::class)->head());
        }
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

        $this->assertGreaterThan(0, $confirmations);
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
        preg_match('/<script type="importmap">(.*?)<\/script>/', $head, $match);
        $map = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['imports'];
        $pending = array_merge(['application'], array_values(array_filter(
            array_keys($map),
            fn (string $specifier): bool => preg_match('#^controllers/.+_controller$#', $specifier) === 1,
        )));
        $visited = [];

        while ($pending !== []) {
            $specifier = array_pop($pending);
            if (isset($visited[$specifier])) {
                continue;
            }

            $visited[$specifier] = true;
            if (str_starts_with($specifier, '@path:')) {
                $path = substr($specifier, 6);
            } else {
                $this->assertArrayHasKey($specifier, $map, "Unresolved module specifier: {$specifier}");
                $path = public_path(ltrim($map[$specifier], '/'));
            }
            $this->assertFileExists($path, "Mapped module is missing: {$specifier}");
            $source = file_get_contents($path);
            preg_match_all('/^\s*(?:import|export)\s+(?:[^"\']*?\s+from\s+)?["\']([^"\']+)["\']/m', $source, $imports);
            preg_match_all('/^\s*import\s*\(\s*["\']([^"\']+)["\']\s*\)/m', $source, $dynamicImports);

            foreach (array_merge($imports[1], $dynamicImports[1]) as $dependency) {
                if (str_starts_with($dependency, '.')) {
                    $resolved = realpath(dirname($path).'/'.$dependency);
                    $this->assertNotFalse($resolved, "Unresolved relative module {$dependency} imported by {$specifier}");
                    $pending[] = '@path:'.$resolved;
                } else {
                    $pending[] = $dependency;
                }
            }
        }
    }
}
