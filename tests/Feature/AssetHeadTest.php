<?php

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

final class AssetHeadTest extends TestCase
{
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

        foreach ($manifest as $logical => $fingerprinted) {
            if (! str_ends_with($logical, '.css') || $logical === 'app.css' || str_starts_with($logical, 'lexxy')) {
                continue;
            }

            $this->assertStringContainsString($fingerprinted, $source, "{$logical} is absent from the application aggregate");
        }
    }
}
