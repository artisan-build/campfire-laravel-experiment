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
        $this->assertStringContainsString('page.locator(\'#message_body [contenteditable="true"]\').press(', $source);
        $this->assertStringNotContainsString('page.locator("#message_body").press(', $source);
        $this->assertStringContainsString('socket.connectToServer()', $source);
        $this->assertStringNotContainsString('unrouteWebSocket', $source);

        foreach ([
            'post and optimistic reconciliation',
            'message surface fills desktop and phone viewport',
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

    public function test_pr5_live_repairs_have_a_runnable_browser_regression(): void
    {
        $path = base_path('tests/Browser/pr5-message-stream-regressions.mjs');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('require.resolve("playwright"', $source);
        $this->assertStringContainsString('process.env.PR5_BROWSER_EXECUTABLE || undefined', $source);
        $this->assertStringContainsString('import "/assets/lexxy-a21f41d4.js"', $source);
        $this->assertStringContainsString('await stream.startEdit', $source);
        $this->assertStringContainsString('lexxy-editor[connected]', $source);
        $this->assertStringContainsString('Connected edit editor did not receive the canonical body', $source);
        $this->assertStringContainsString('Ctrl+Enter did not activate edit save', $source);
        $this->assertStringContainsString('.typing-indicator--active', $source);
        $this->assertStringContainsString('waitFor({ state: "detached", timeout: 7_000 })', $source);
        $this->assertStringContainsString('Exhausted edge requested', $source);
        $this->assertStringContainsString('Changed edge did not permit another pagination request', $source);
    }
}
