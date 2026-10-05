<?php

namespace Tests\Feature;

use Tests\TestCase;

final class BrowserHarnessContractTest extends TestCase
{
    public function test_pr5_harness_is_committed_executable_and_covers_the_frozen_matrix(): void
    {
        $path = base_path('tests/Browser/pr5-json-stream.mjs');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('require.resolve("playwright"', $source);
        $this->assertStringContainsString('const desktop = await browser.newContext', $source);
        $this->assertStringContainsString('const phone = await browser.newContext', $source);
        $this->assertStringContainsString('writeFileSync(outputPath', $source);
        $this->assertStringNotContainsString('writeFileSync(outputPath, payload', $source);
        $this->assertStringContainsString('page.locator(\'form[action="/session"]\').getByRole("button", { name: "Sign in", exact: true }).click()', $source);
        $this->assertStringNotContainsString('page.locator(\'button[type="submit"]\').click()', $source);

        foreach ([
            'post and optimistic reconciliation',
            'keyboard edit and focus restoration',
            'escape cancels edit',
            'boost add remove and failed rollback',
            'typing start stop and expiry',
            'unread sidebar membership and app badge',
            'own-message and local day styling',
            'scroll latest and before/after pagination stability',
            'image lightbox and video poster',
            'first connection convergence',
            'reconnect around post edit and delete',
            'touch menu dialog and responsive layouts',
            'managed Reverb JSON frame contract',
        ] as $criterion) {
            $this->assertStringContainsString($criterion, $source);
        }

        foreach (['message.posted', 'message.updated', 'message.deleted', 'boost.added', 'boost.removed'] as $event) {
            $this->assertStringContainsString($event, $source);
        }

        foreach (['turbo-stream', '"gz"', '"oversize"', 'csrf', 'cookie', 'session', 'password', 'authorization'] as $forbidden) {
            $this->assertStringContainsString($forbidden, $source);
        }

        foreach (['PR5_USER_A_EMAIL', 'PR5_USER_A_PASSWORD', 'PR5_USER_B_EMAIL', 'PR5_USER_B_PASSWORD'] as $credential) {
            $this->assertStringContainsString($credential, $source);
        }
    }
}
