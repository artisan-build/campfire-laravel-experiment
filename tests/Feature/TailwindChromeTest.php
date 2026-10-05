<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TailwindChromeTest extends TestCase
{
    public function test_authentication_and_signup_surfaces_have_structural_markers(): void
    {
        $this->assertTestId($this->get('/first_run')->assertOk(), 'auth-sign-up');

        $this->fixture();

        $this->assertTestId($this->get('/session/new')->assertOk(), 'auth-sign-in');
        $transfer = $this->get('/session/transfers/test-created-transfer')->assertOk();
        $this->assertTestId($transfer, 'auth-transfer');
        $transfer->assertSee('/session/transfers/test-created-transfer', false);
    }

    public function test_top_level_authenticated_surfaces_render_with_test_created_data(): void
    {
        [$user, $room] = $this->fixture();
        $user->update(['name' => 'Surface Pilot']);
        $room->update(['name' => 'Tailwind Observatory']);
        $bot = User::create(['name' => 'Marker Bot', 'bot_token' => 'MarkerBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);
        $this->auth($user);

        foreach ([
            '/rooms/'.$room->id => ['room-message-stream', 'Tailwind Observatory'],
            '/rooms/'.$room->id.'/settings' => ['room-settings', 'Tailwind Observatory'],
            '/rooms/'.$room->id.'/involvement' => ['room-notifications', null],
            '/rooms/opens/new' => ['room-form', null],
            '/rooms/directs/new' => ['room-form', null],
            '/users/'.$user->id => ['user-profile-card', 'Surface Pilot'],
            '/users/me/sidebar' => ['sidebar-rooms', 'Tailwind Observatory'],
            '/users/me/profile' => ['settings-profile', 'Surface Pilot'],
            '/users/me/push_subscriptions' => ['settings-notifications', null],
            '/account/edit' => ['settings-account', 'Surface Pilot'],
            '/account/custom_styles/edit' => ['settings-custom-styles', null],
            '/account/bots' => ['settings-bots', 'Marker Bot'],
            '/account/bots/new' => ['settings-bot-form', null],
            '/searches' => ['search-results', null],
        ] as $path => [$testId, $createdContent]) {
            $response = $this->get($path)->assertOk();
            $this->assertTestId($response, $testId);
            if ($createdContent !== null) {
                $response->assertSee($createdContent);
            }
        }
    }

    public function test_alpine_behaviors_are_implemented_and_wired_to_rendered_call_sites(): void
    {
        [$user, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $user, ['body' => '<p>Alpine behavior fixture</p>']);
        $this->auth($user);

        $roomHtml = $this->get('/rooms/'.$room->id)->assertOk()->getContent();
        $profileHtml = $this->get('/users/me/profile')->assertOk()->getContent();
        $alpine = file_get_contents(public_path('assets/campfire/alpine.js'));
        $messageStream = file_get_contents(public_path('assets/campfire/message_stream.js'));
        $application = file_get_contents(public_path('assets/campfire/application.js'));
        $importMap = json_decode(file_get_contents(resource_path('importmap.json')), true, flags: JSON_THROW_ON_ERROR)['imports'];
        $presentation = file_get_contents(resource_path('views/messages/presentation.blade.php'));

        $this->assertSame(1, preg_match('/import "(?<module>campfire\/alpine)"/', $application, $applicationImport));
        $this->assertSame('ASSET:campfire/alpine.js', $importMap[$applicationImport['module']] ?? null);
        $this->assertSame(1, preg_match('/import Alpine from "(?<module>[^"]+)"/', $alpine, $alpineImport));
        $this->assertArrayHasKey($alpineImport['module'], $importMap);
        $this->assertMatchesRegularExpression('/^Alpine\.start\(\)$/m', $alpine);
        $this->assertStringContainsString('x-data="appShell"', $roomHtml);
        $this->assertStringContainsString('@click="toggleSidebar()"', $roomHtml);
        $this->assertStringContainsString('x-data="messageStream(', $roomHtml);
        $this->assertStringContainsString('@drop="dropFiles($event)"', $roomHtml);
        $this->assertStringContainsString('@keydown="composerKeydown($event)"', $roomHtml);
        $this->assertStringContainsString('@click="handleMessageAction($event); handleEditAction($event)"', $roomHtml);
        $this->assertStringContainsString('data-stream-action="copy"', $roomHtml);
        $this->assertStringContainsString('/rooms/'.$room->id.'/@'.$message->id, $roomHtml);
        $this->assertStringContainsString('webShare(', $profileHtml);
        $this->assertStringContainsString('@click.prevent="openLightbox($el)"', $presentation);

        $this->assertStringContainsString('this.$refs.lightbox.showModal()', $alpine);
        $this->assertStringContainsString('navigator.clipboard.writeText(content)', $alpine);
        $this->assertStringContainsString('dropFiles(event) {', $messageStream);
        $this->assertStringContainsString('this.addFiles(event.dataTransfer.files)', $messageStream);
        $this->assertStringContainsString('composerKeydown(event) {', $messageStream);
        $this->assertStringContainsString('async handleMessageAction(event) {', $messageStream);
        $this->assertStringContainsString('navigator.clipboard?.writeText(message.dataset.messageUrl)', $messageStream);
        $this->assertStringContainsString('this.$refs.menu.getBoundingClientRect()', $alpine);
        $this->assertStringContainsString('document.createElement("input")', $alpine);
        $this->assertStringContainsString('await navigator.share(data)', $alpine);

        config(['campfire.json_message_stream' => false]);
        $legacyRoomHtml = $this->get('/rooms/'.$room->id)->assertOk()->getContent();
        $this->assertStringContainsString('x-data="dropTarget"', $legacyRoomHtml);
        $this->assertStringContainsString('@drop="drop($event)"', $legacyRoomHtml);
        $this->assertStringContainsString('x-data="softKeyboard"', $legacyRoomHtml);
        $this->assertStringContainsString('x-data="messagePopup"', $legacyRoomHtml);
        $this->assertStringContainsString('@click.outside="close()"', $legacyRoomHtml);
        $this->assertStringContainsString('clipboard(', $legacyRoomHtml);
        $this->assertStringContainsString('this.$dispatch("campfire:drop", { files: event.dataTransfer.files })', $alpine);
    }

    public function test_mobile_sidebar_translation_is_owned_by_the_alpine_state(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);

        $document = new DOMDocument;
        $document->loadHTML($this->get('/rooms/'.$room->id)->assertOk()->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $sidebars = (new DOMXPath($document))->query('//*[@data-testid="app-sidebar"]');

        $this->assertNotFalse($sidebars);
        $this->assertCount(1, $sidebars);

        $sidebar = $sidebars->item(0);
        $this->assertNotNull($sidebar);
        $staticClasses = preg_split('/\s+/', $sidebar->attributes->getNamedItem('class')->nodeValue);
        $this->assertNotContains('translate-x-full', $staticClasses);
        $this->assertNotContains('translate-x-0', $staticClasses);
        $this->assertSame("sidebarOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'", $sidebar->attributes->getNamedItem(':class')?->nodeValue);
    }

    public function test_json_room_and_rollback_keep_the_composer_inside_the_layout_shell(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);

        $document = $this->document($this->get('/rooms/'.$room->id)->assertOk());
        $xpath = new DOMXPath($document);
        $body = $xpath->query('//body')->item(0);
        $this->assertInstanceOf(DOMElement::class, $body);
        $this->assertNotContains('sidebar', preg_split('/\s+/', $body->getAttribute('class')));
        $this->assertCount(1, $xpath->query('//main[@id="main-content"]//form[@data-testid="room-json-composer"]'));
        $this->assertCount(1, $xpath->query('//main[@id="main-content"]/footer[@id="footer"]'));
        $this->assertCount(1, $xpath->query('//main[@id="main-content"]/following-sibling::aside[@id="sidebar"]'));

        config(['campfire.json_message_stream' => false]);
        $legacyDocument = $this->document($this->get('/rooms/'.$room->id)->assertOk());
        $legacyXPath = new DOMXPath($legacyDocument);
        $legacyBody = $legacyXPath->query('//body')->item(0);
        $this->assertInstanceOf(DOMElement::class, $legacyBody);
        $this->assertContains('sidebar', preg_split('/\s+/', $legacyBody->getAttribute('class')));
        $this->assertCount(1, $legacyXPath->query('//main[@id="main-content"]//form[@id="composer"]'));
        $this->assertCount(1, $legacyXPath->query('//main[@id="main-content"]/footer[@id="footer"]'));
        $this->assertCount(1, $legacyXPath->query('//main[@id="main-content"]/following-sibling::aside[@id="sidebar"]'));
    }

    public function test_retained_local_time_controller_owns_existing_and_optimistic_timestamps(): void
    {
        [$user, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $user, ['body' => '<p>Timestamp ownership fixture</p>']);
        $this->auth($user);

        $response = $this->get('/rooms/'.$room->id)->assertOk();
        $document = $this->document($response);
        $xpath = new DOMXPath($document);
        $owners = $xpath->query('//*[@data-testid="app-content" and contains(@x-data, "messageStream(")]');

        $this->assertNotFalse($owners);
        $this->assertCount(1, $owners);
        $owner = $owners->item(0);
        $this->assertInstanceOf(DOMElement::class, $owner);

        $existing = $xpath->query('.//*[@id="message_'.$message->client_message_id.'"]//*[@data-stream-time="date" or @data-stream-time="time"]', $owner);
        $templates = $xpath->query('.//template[@data-testid="room-message-template"]', $owner);
        $this->assertNotFalse($existing);
        $this->assertCount(2, $existing);
        $this->assertNotFalse($templates);
        $this->assertCount(1, $templates);

        $template = $templates->item(0);
        $this->assertNotNull($template);
        $templateTimes = $xpath->query('.//*[@data-stream-time="date" or @data-stream-time="time"]', $template);
        $this->assertNotFalse($templateTimes);
        $this->assertCount(2, $templateTimes);

        $messageStream = file_get_contents(public_path('assets/campfire/message_stream.js'));
        $this->assertStringContainsString('this.formatMessages()', $messageStream);
        $this->assertStringContainsString('message.querySelector("[data-stream-time=date]")', $messageStream);
        $this->assertStringContainsString('message.querySelector("[data-stream-time=time]")', $messageStream);
        $this->assertStringNotContainsString('data-controller="local-time"', $response->getContent());
    }

    public function test_account_bots_navigation_is_visible_without_a_breakpoint(): void
    {
        [$user] = $this->fixture();
        $this->auth($user);

        $links = (new DOMXPath($this->document($this->get('/account/edit')->assertOk())))->query('//a[@href="/account/bots"]');
        $this->assertNotFalse($links);
        $this->assertNotEmpty($links);

        $visibleLinks = array_filter(iterator_to_array($links), function ($link): bool {
            return $link instanceof DOMElement
                && ! in_array('hidden', preg_split('/\s+/', $link->getAttribute('class')), true);
        });

        $this->assertNotEmpty($visibleLinks);
    }

    public function test_lightbox_has_a_submit_capable_close_control(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);

        $xpath = new DOMXPath($this->document($this->get('/rooms/'.$room->id)->assertOk()));
        $closeControls = $xpath->query('//*[@data-testid="app-lightbox"]//form[translate(@method, "DIALOG", "dialog")="dialog"]//button[@type="submit"]');

        $this->assertNotFalse($closeControls);
        $this->assertCount(1, $closeControls);
    }

    public function test_unread_room_contract_uses_one_visible_class_token(): void
    {
        [$user, $room] = $this->fixture();
        $unreadRoom = Room::create(['name' => 'Unread Contract Room', 'type' => 'Rooms::Open', 'creator_id' => $user->id]);
        Membership::create([
            'room_id' => $unreadRoom->id,
            'user_id' => $user->id,
            'involvement' => 'mentions',
            'unread_at' => now(),
        ]);
        $this->auth($user);

        $roomResponse = $this->get('/rooms/'.$room->id)->assertOk();
        $roomDocument = $this->document($roomResponse);
        $roomPage = new DOMXPath($roomDocument);
        $owners = $roomPage->query('//*[@data-testid="app-content" and contains(@x-data, "messageStream(")]');
        $links = $roomPage->query('//*[@data-testid="sidebar-rooms"]//*[@data-room-id="'.$unreadRoom->id.'"]');
        $this->assertNotFalse($owners);
        $this->assertCount(1, $owners);
        $this->assertNotFalse($links);
        $this->assertCount(1, $links);

        $link = $links->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertContains('unread', preg_split('/\s+/', $link->getAttribute('class')));

        $messageStream = file_get_contents(public_path('assets/campfire/message_stream.js'));
        $this->assertStringContainsString('setRoomUnread(roomId, unread) {', $messageStream);
        $this->assertStringContainsString('room.classList.toggle("unread", unread)', $messageStream);
        $this->assertStringContainsString('document.querySelectorAll("[data-room-id].unread")', $messageStream);
        $this->assertStringNotContainsString('data-rooms-list-unread-class', $roomResponse->getContent());

        $manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $css = file_get_contents(public_path('assets/'.$manifest['app.css']));
        $this->assertSame(1, preg_match('/\.unread\{(?<declarations>[^}]*)\}/', $css, $unreadRule));
        $this->assertStringContainsString('--tw-ring-shadow:', $unreadRule['declarations']);
        $this->assertStringContainsString('--tw-ring-color:var(--color-orange-500)', $unreadRule['declarations']);
    }

    public function test_removed_chrome_assets_and_stimulus_controllers_cannot_be_loaded(): void
    {
        $manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $importMap = json_decode(file_get_contents(resource_path('importmap.json')), true, flags: JSON_THROW_ON_ERROR)['imports'];
        $source = file_get_contents(resource_path('css/app.css'));

        foreach (['copy_to_clipboard', 'drop_target', 'lightbox', 'popup', 'soft_keyboard', 'toggle_class', 'web_share'] as $controller) {
            $this->assertArrayNotHasKey('controllers/'.$controller.'_controller', $importMap);
            $this->assertArrayNotHasKey('controllers/'.$controller.'_controller.js', $manifest);
            $this->assertSame([], glob(public_path('assets/controllers/'.$controller.'_controller-*.js')));
        }

        foreach (['_reset.css', 'filters.css', 'flash.css', 'layout.css', 'lightbox.css', 'nav.css', 'panels.css', 'separators.css', 'sidebar.css', 'signup.css', 'trix.css'] as $stylesheet) {
            $this->assertArrayNotHasKey($stylesheet, $manifest);
            $this->assertStringNotContainsString($stylesheet, $source);
            $this->assertSame([], glob(public_path('assets/'.substr($stylesheet, 0, -4).'-*.css')));
        }

        foreach (['actiontext.css', 'autocomplete.css', 'avatars.css', 'boosts.css', 'buttons.css', 'code.css', 'colors.css', 'composer.css', 'embeds.css', 'inputs.css', 'messages.css', 'spinner.css', 'utilities.css'] as $stylesheet) {
            $this->assertArrayHasKey($stylesheet, $manifest);
            $this->assertStringContainsString($manifest[$stylesheet], $source);
        }
    }

    private function assertTestId(TestResponse $response, string $testId): void
    {
        $document = $this->document($response);
        $elements = (new DOMXPath($document))->query('//*[@data-testid="'.$testId.'"]');

        $this->assertNotFalse($elements);
        $this->assertCount(1, $elements, "Expected exactly one [data-testid=\"{$testId}\"].");
    }

    private function document(TestResponse $response): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

        return $document;
    }
}
