<?php

namespace Tests\Feature;

use Tests\TestCase;

final class BrowserHarnessContractTest extends TestCase
{
    public function test_pr9_final_harness_covers_native_security_realtime_media_idle_and_sleep(): void
    {
        $source = file_get_contents(base_path('tests/Browser/pr5-json-stream.mjs'));

        foreach ([
            'login and native CSRF rejection',
            'presence subscription',
            'post and optimistic reconciliation',
            'unread sidebar membership and app badge',
            'typing start stop and expiry',
            'keyboard edit and focus restoration',
            'delete propagation',
            'boost add remove and failed rollback',
            'image lightbox and video poster',
            'search',
            'kept-awake latency control',
            'sixty-minute reconnect-attributed idle and sleep latency signature',
            'logout invalidates the session',
        ] as $criterion) {
            $this->assertStringContainsString($criterion, $source);
        }

        $this->assertStringContainsString('const idleObservationMs = 3_600_000', $source);
        $this->assertStringContainsString('const reconnectAttributionWindowMs = 5_000', $source);
        $this->assertStringContainsString('const quietStretchThresholdMs = 600_000', $source);
        $this->assertStringContainsString('const sleepSignatureMinDeltaMs = 250', $source);
        $this->assertStringNotContainsString('PR9_SLEEP_SIGNATURE_MIN_DELTA_MS', $source);
        $this->assertStringContainsString('const minimumHistoryMessages = 81', $source);
        $this->assertStringContainsString('const response = await userA.request.post(`${baseUrl}/session`)', $source);
        $this->assertStringContainsString('if (status !== 419)', $source);
        $this->assertStringNotContainsString('fetch("/session"', $source);
        $this->assertStringNotContainsString('credentials: "include"', $source);
        $this->assertStringNotContainsString('credentials: "omit"', $source);
        $this->assertStringNotContainsString('Sec-Fetch-Site', $source);
        $this->assertStringNotContainsString('X-CSRF-TOKEN', $source);
        $this->assertStringNotContainsString('form: {', $source);
        $this->assertStringContainsString('locator("[data-message-id]:visible", { hasText: edited })', $source);
        $this->assertStringContainsString("} finally {\n      await openRoom(userA)", $source);
        $this->assertStringContainsString('PR5_ROOM_URL requires at least ${minimumHistoryMessages} existing messages for pagination', $source);
        $this->assertStringContainsString('if (before.length !== 40)', $source);
        $this->assertStringContainsString('url.searchParams.has("after")', $source);
        $this->assertStringContainsString('Initial connection discarded the permalink anchor', $source);
        $this->assertStringContainsString('After-page response was not applied to the permalink window', $source);
        $this->assertStringNotContainsString('if (before.length >= 40', $source);
        $this->assertStringNotContainsString('if (around.length >= 81', $source);
        $this->assertStringNotContainsString('getByTestId("search-result-list").getByText(edited)', $source);

        foreach ([
            'first connection convergence',
            'reconnect around post edit and delete',
            'touch menu dialog and responsive layouts',
            'delete propagation',
        ] as $roomDependentCheck) {
            $this->assertMatchesRegularExpression('/check\("'.preg_quote($roomDependentCheck, '/').'".*?await (?:Promise\.all\(\[)?openRoom\(userA\)/s', $source);
        }

        $this->assertStringContainsString('connection.bind("state_change"', $source);
        $this->assertStringContainsString('hasConnected && disconnectedAt !== null', $source);
        $this->assertStringContainsString('is_reconnect: false', $source);
        $this->assertStringContainsString('cause: "reconnect-driven"', $source);
        $this->assertStringContainsString('cause: "unattributed"', $source);
        $this->assertStringContainsString('request.started_at_ms >= connected_at_ms && request.started_at_ms <= connected_at_ms + reconnectAttributionWindowMs', $source);
        $this->assertStringContainsString('if (unattributedRequests.length)', $source);
        $this->assertMatchesRegularExpression('/if \(url\.origin === appOrigin && request\.resourceType\(\) !== "websocket"\) \{\s+idleRequests\.push\(/s', $source);
        $this->assertStringContainsString('"/broadcasting/auth"', $source);
        $this->assertStringContainsString('"/rooms/{id}/messages"', $source);
        $this->assertStringContainsString('started_at: new Date(startedAt).toISOString()', $source);
        $this->assertStringContainsString('method: request.method()', $source);
        $this->assertStringContainsString('path: `${url.pathname}${url.search}`', $source);
        $this->assertMatchesRegularExpression('/let observationStartedAt = null.*?if \(observationStartedAt === null \|\| startedAt < observationStartedAt\) return.*?userA\.on\("request", observeRequest\)\s+observationStartedAt = Date\.now\(\)/s', $source);

        foreach ([
            'reconnect_count',
            'reconnect_timestamps',
            'reconnect_rate_per_hour',
            'idle_tab_application_requests',
            'kept_awake_control',
            'probe_source',
            'quiet_stretch_threshold_ms',
            'sleep_signature_min_delta_ms',
            'first_probe_started_at',
            'first_probe_latency_ms',
            'warm_probe_started_at',
            'warm_probe_latency_ms',
        ] as $artifactField) {
            $this->assertStringContainsString($artifactField, $source);
        }

        $this->assertStringContainsString('validSleepSignatures.length === 0', $source);
        $this->assertStringContainsString('const quietStartedAt = Math.max(lastIdleActivityAt, lastSleepProbeCompletedAt)', $source);
        $this->assertStringContainsString('if (Date.now() - quietStartedAt < quietStretchThresholdMs) continue', $source);
        $this->assertStringNotContainsString('if (!precedingReconnect ||', $source);
        $this->assertStringContainsString('preceding_reconnect_connected_at: precedingReconnect?.connected_at ?? null', $source);
        $this->assertStringContainsString('interval_origin: quietIntervalOrigin', $source);

        foreach (['initial-stable-connection', 'post-reconnect', 'post-probe', 'idle-tab-request'] as $intervalOrigin) {
            $this->assertStringContainsString($intervalOrigin, $source);
        }

        $this->assertStringContainsString('afterProbeSnapshot.transitions.some', $source);
        $this->assertStringContainsString('const firstProbe = await timedHealthRequest()', $source);
        $this->assertStringContainsString('const warmProbe = await timedHealthRequest()', $source);
        $this->assertStringContainsString('presence_frames: presenceFrames', $source);
    }

    public function test_pr8_harness_is_committed_and_covers_livewire_routes_and_security_controls(): void
    {
        $path = base_path('tests/Browser/pr8-livewire-routes.mjs');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('require.resolve("playwright"', $source);
        $this->assertStringContainsString('process.env.PR8_HEADLESS !== "false"', $source);
        $this->assertStringContainsString('process.env.PR8_PROFILE_ROOT', $source);
        $this->assertStringContainsString('process.env.PR8_CANDIDATE', $source);
        $this->assertStringContainsString('process.env.PR8_DATABASE_STAMP', $source);
        $this->assertStringContainsString('process.env.PR8_QUEUE_STAMP', $source);
        $this->assertStringContainsString('browser_user_agent: await admin.evaluate', $source);
        $this->assertStringContainsString('context_model: "persistent"', $source);
        $this->assertSame(2, substr_count($source, 'run_id: runId, provenance, states'));
        $this->assertSame(3, substr_count($source, 'chromium.launchPersistentContext('));
        $this->assertStringContainsString('const roomId = integerInput("PR8_ROOM_ID", undefined, { positive: true })', $source);
        $this->assertStringContainsString('const lockedTamperExpectedStatus = integerInput("PR8_LOCKED_TAMPER_STATUS", "500", { positive: true })', $source);
        $this->assertStringContainsString('const notificationTimeoutSeconds = integerInput("PR8_NOTIFICATION_TIMEOUT_SECONDS", "30", { positive: true })', $source);
        $this->assertStringContainsString('const deadline = Date.now() + notificationTimeoutSeconds * 1_000', $source);
        $this->assertStringContainsString('room_id: roomId', $source);
        $this->assertStringContainsString('locked_tamper_expected_status: lockedTamperExpectedStatus', $source);
        $this->assertStringContainsString('notification_timeout_seconds: notificationTimeoutSeconds', $source);
        $this->assertStringContainsString('command.includes(path)', $source);
        $this->assertStringContainsString('Expected one bot command for room ${roomId}', $source);
        $this->assertStringNotContainsString('locator(\'input[aria-label="curl command for posting messages"]\').inputValue()', $source);
        $this->assertStringContainsString('getByTestId', $source);
        $this->assertStringContainsString('bot api post update boost delete', $source);
        $this->assertStringContainsString('search and personal history', $source);
        $this->assertStringContainsString('bot edit and key rotation invalidates old URL', $source);
        $this->assertStringContainsString('join signup with locked credential negative', $source);
        $this->assertStringContainsString('non admin bot access denied', $source);
        $this->assertStringContainsString('push registration test and cross-user protection', $source);
        $this->assertStringContainsString('session transfer locked credential and confirmation', $source);
        $this->assertStringContainsString('logout survives rejected unsubscribe and login again', $source);
        $this->assertStringContainsString('PushSubscription.prototype.unsubscribe = async function', $source);
        $this->assertStringContainsString('pr8-unsubscribe-rejection-executed', $source);
        $this->assertStringContainsString('Rejected unsubscribe preserved the server session', $source);
        $this->assertStringContainsString('url.pathname === "/account/bots"', $source);
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
        $this->assertSame(2, substr_count($source, '      status: lockedTamperExpectedStatus,'));
        $this->assertSame(1, substr_count($source, 'status: 403'));
        $this->assertSame(1, substr_count($source, 'status: 404'));
        $this->assertSame(2, substr_count($source, 'writeArtifact({'));
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
        $this->assertStringContainsString('const form = page.getByTestId("sign-in-form")', $source);
        $this->assertStringContainsString('form.locator(\'input[type="email"]\').fill(email)', $source);
        $this->assertStringContainsString('form.locator(\'input[type="password"]\').fill(password)', $source);
        $this->assertStringContainsString('form.getByRole("button", { name: "Sign in" }).click()', $source);
        $this->assertStringNotContainsString('form[action="/session"]', $source);
        $this->assertStringNotContainsString('input[name="email_address"]', $source);
        $this->assertStringNotContainsString('input[name="password"]', $source);
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
