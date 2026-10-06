<?php

namespace Tests\Feature;

use Tests\TestCase;

final class BrowserHarnessContractTest extends TestCase
{
    public function test_pr8_harness_is_committed_and_covers_livewire_routes_and_security_controls(): void
    {
        $path = base_path('tests/Browser/pr8-livewire-routes.mjs');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('require.resolve("playwright"', $source);
        $this->assertStringContainsString('process.env.PR8_HEADLESS !== "false"', $source);
        $this->assertStringContainsString('process.env.PR8_PROFILE_ROOT', $source);
        $this->assertSame(3, substr_count($source, 'chromium.launchPersistentContext('));
        $this->assertStringContainsString('const deadline = Date.now() + 30_000', $source);
        $this->assertStringContainsString('getByTestId', $source);
        $this->assertStringContainsString('bot api post update boost delete', $source);
        $this->assertStringContainsString('search and personal history', $source);
        $this->assertStringContainsString('bot edit and key rotation invalidates old URL', $source);
        $this->assertStringContainsString('join signup with locked credential negative', $source);
        $this->assertStringContainsString('non admin bot access denied', $source);
        $this->assertStringContainsString('push registration test and cross-user protection', $source);
        $this->assertStringContainsString('session transfer locked credential and confirmation', $source);
        $this->assertStringContainsString('logout unsubscribe and login again', $source);
        $this->assertStringContainsString('bot delete', $source);
        $this->assertStringContainsString('Join-code substitution did not fail loudly', $source);
        $this->assertStringContainsString('Transfer-id substitution did not fail loudly', $source);
        $this->assertStringContainsString('Cross-user push removal did not fail loudly', $source);
        $this->assertStringContainsString('Console problems:', $source);
        $this->assertStringContainsString('const expectedNegativeEvidence = []', $source);
        $this->assertStringContainsString('activeNegativeControls.set(page, expectation)', $source);
        $this->assertStringContainsString('status === expectation.status', $source);
        $this->assertStringContainsString('expectation.matchesPath(sourcePath)', $source);
        $this->assertStringContainsString('expected_negative_evidence: expectedNegativeEvidence', $source);
        $this->assertStringContainsString('name: "join-code locked-property substitution"', $source);
        $this->assertStringContainsString('name: "non-admin bot access"', $source);
        $this->assertStringContainsString('name: "cross-user push removal"', $source);
        $this->assertStringContainsString('name: "transfer-id locked-property substitution"', $source);
        $this->assertSame(2, substr_count($source, 'status: 500'));
        $this->assertSame(1, substr_count($source, 'status: 403'));
        $this->assertSame(1, substr_count($source, 'status: 404'));
        $this->assertStringContainsString('writeFileSync(outputPath', $source);
        $this->assertStringContainsString('process.exitCode = 1', $source);
        $this->assertStringNotContainsString('setInterval', $source);

        foreach (['PR8_ADMIN_EMAIL', 'PR8_ADMIN_PASSWORD', 'PR8_JOIN_CODE'] as $credential) {
            $this->assertStringContainsString($credential, $source);
        }
    }

    public function test_pr7_harness_is_committed_and_covers_every_sidebar_transition(): void
    {
        $path = base_path('tests/Browser/pr7-livewire-sidebar.mjs');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('require.resolve("playwright"', $source);
        $this->assertStringContainsString('const contextA = await browser.newContext()', $source);
        $this->assertStringContainsString('const contextB = await browser.newContext()', $source);
        $this->assertStringContainsString('const pageB1 = await contextB.newPage()', $source);
        $this->assertStringContainsString('const pageB2 = await contextB.newPage()', $source);
        $this->assertStringContainsString('room create reaches both B tabs without navigation', $source);
        $this->assertStringContainsString('room rename reaches both B tabs without navigation', $source);
        $this->assertStringContainsString('unread reaches both B tabs without navigation', $source);
        $this->assertStringContainsString('opening in one B tab clears the other tab', $source);
        $this->assertStringContainsString('room deletion reaches both B tabs without navigation', $source);
        $this->assertStringContainsString('direct message reorders both B tabs without navigation', $source);
        $this->assertStringContainsString('Created room link state was not exact', $source);
        $this->assertStringContainsString('Renamed room link state was not exact', $source);
        $this->assertStringContainsString('Tab 1 missed unread class', $source);
        $this->assertStringContainsString('Other tab missed read clearing', $source);
        $this->assertStringContainsString('Deleted room link remained in a receiving tab', $source);
        $this->assertStringContainsString('url.pathname === "/" || (/^\\/rooms\\/\\d+$/.test(url.pathname) && url.pathname !== `/rooms/${targetId}`)', $source);
        $this->assertStringContainsString('B navigated while receiving room deletion', $source);
        $this->assertStringNotContainsString('pageA.waitForURL(`${baseUrl}/`)', $source);
        $this->assertStringContainsString('Tab 1 missed direct-room reorder', $source);
        $this->assertStringContainsString('Room ${roomId} classes were not exact', $source);
        $this->assertStringContainsString('writeFileSync(outputPath', $source);
        $this->assertStringContainsString('states.push({ name, url: page.url(), room_id: roomId, link: state })', $source);
        $this->assertStringContainsString('states.push({ name, url: page.url(), order: links.map((link) => link.room_id), links })', $source);
        $this->assertStringContainsString('/\\/rooms\\/\\d+\\/messages\\?before=0$/', $source);
        $this->assertStringContainsString('message.type() === "error" && /\\/rooms\\/\\d+\\/messages\\?before=0$/.test(location)', $source);
        $this->assertStringNotContainsString('message.text().includes("404 (Not Found)")', $source);
        $this->assertStringContainsString('consoleProblems.push(detail)', $source);
        $this->assertStringContainsString('console_advisories: consoleAdvisories', $source);
        $this->assertStringNotContainsString('setInterval', $source);
        $this->assertStringNotContainsString('page.reload()', $source);

        foreach (['PR7_USER_A_EMAIL', 'PR7_USER_A_PASSWORD', 'PR7_USER_B_EMAIL', 'PR7_USER_B_PASSWORD'] as $credential) {
            $this->assertStringContainsString($credential, $source);
        }
    }

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
        $this->assertStringContainsString('function lexxyEditable(scope, editorSelector = "lexxy-editor")', $source);
        $this->assertStringContainsString('lexxyEditable(page, "#message_body").evaluate(', $source);
        $this->assertStringContainsString('lexxyEditable(page, "#message_body").press(', $source);
        $this->assertStringContainsString('lexxyEditable(row).evaluate(', $source);
        $this->assertStringContainsString('lexxyEditable(row).press(process.platform', $source);
        $this->assertStringContainsString('lexxyEditable(row).press("Escape")', $source);
        $this->assertStringNotContainsString('page.locator("#message_body").press(', $source);
        $this->assertStringNotContainsString('page.locator("#message_body").evaluate(', $source);
        $this->assertStringContainsString('socket.connectToServer()', $source);
        $this->assertStringNotContainsString('unrouteWebSocket', $source);
        $this->assertStringContainsString('row.locator(".message__actions > details")', $source);
        $this->assertStringContainsString('row.locator(".message__actions > details > summary")', $source);
        $this->assertStringNotContainsString('row.locator("details")', $source);
        $this->assertStringNotContainsString('row.locator("summary")', $source);
        $this->assertStringContainsString('const messageId = await message(page, oldText).getAttribute("data-message-id")', $source);
        $this->assertStringContainsString('const row = messageById(page, messageId)', $source);
        $this->assertStringContainsString('await row.filter({ hasText: newText }).waitFor()', $source);
        $this->assertStringContainsString('row.isConnected && Number(row.dataset.messageId) > 0', $source);
        $this->assertStringContainsString('document.activeElement === button', $source);
        $this->assertStringContainsString('await messageById(page, messageId).waitFor({ state: "detached" })', $source);
        $this->assertStringContainsString('} finally {', $source);
        $this->assertStringContainsString('await page.context().setOffline(false)', $source);
        $this->assertStringContainsString('const content = boost.locator(\'[data-stream-action="reveal-boost"]\')', $source);
        $this->assertStringContainsString('await removeBoost.waitFor({ state: "visible" })', $source);
        $this->assertStringContainsString('await userA.route("**/messages/*/boosts", (route) => route.abort(), { times: 1 })', $source);
        $this->assertStringNotContainsString('userA.unroute("**/messages/*/boosts")', $source);
        $this->assertStringNotContainsString('const row = message(page, oldText)', $source);
        $this->assertStringNotContainsString('row.locator("lexxy-editor").press("Escape")', $source);
        $this->assertStringNotContainsString('const editor = row.locator("lexxy-editor")', $source);

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
        $this->assertStringContainsString('/assets/boosts-da4032a8.css', $source);
        $this->assertStringContainsString('await stream.startEdit', $source);
        $this->assertStringContainsString('await editRequestStarted', $source);
        $this->assertStringContainsString('stream.unindexMessage(original)', $source);
        $this->assertStringContainsString('original.replaceWith(replacement)', $source);
        $this->assertStringContainsString('stream.indexMessage(replacement)', $source);
        $this->assertStringContainsString('lexxy-editor[connected]', $source);
        $this->assertStringContainsString('Connected edit editor did not receive the canonical body', $source);
        $this->assertStringContainsString('Ctrl+Enter did not activate edit save', $source);
        $this->assertStringContainsString('Race fixture did not start from the indexed optimistic row', $source);
        $this->assertStringContainsString('optimistic.dataset.messageId = "0"', $source);
        $this->assertStringContainsString('Optimistic reconciliation detached the active Lexxy editor', $source);
        $this->assertStringContainsString('Optimistic reconciliation destroyed the edit draft', $source);
        $this->assertStringContainsString('Boost updates disturbed the active Lexxy editor', $source);
        $this->assertStringContainsString('Reconnect convergence detached the active Lexxy editor', $source);
        $this->assertStringContainsString('Delete update detached the active Lexxy editor before edit closed', $source);
        $this->assertStringContainsString('globalThis.raceEditor.isConnected', $source);
        $this->assertStringContainsString('globalThis.raceEditable.isContentEditable', $source);
        $this->assertStringContainsString('await stream.receiveMessage(message)', $source);
        $this->assertStringContainsString('await stream.replaceCurrentWindow([message])', $source);
        $this->assertStringContainsString('.typing-indicator--active', $source);
        $this->assertStringContainsString('waitFor({ state: "detached", timeout: 7_000 })', $source);
        $this->assertStringContainsString('Exhausted edge requested', $source);
        $this->assertStringContainsString('Changed edge did not permit another pagination request', $source);
        $this->assertStringContainsString('Owner boost content is not keyboard-focusable', $source);
        $this->assertStringContainsString('Owner boost content lost its accessible description', $source);
        $this->assertStringContainsString('Click did not reveal boost removal', $source);
        $this->assertStringContainsString('Second click did not hide boost removal', $source);
        $this->assertStringContainsString('Enter did not reveal boost removal', $source);
        $this->assertStringContainsString('Boost removal did not receive focus', $source);
        $this->assertStringContainsString('remoteBoost.waitFor({ state: "detached" })', $source);
    }
}
