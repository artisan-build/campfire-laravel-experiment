<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use App\Support\MessageWriter;
use DOMDocument;
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
        $application = file_get_contents(public_path('assets/campfire/application.js'));
        $presentation = file_get_contents(resource_path('views/messages/presentation.blade.php'));

        $this->assertStringContainsString('import "campfire/alpine"', $application);
        $this->assertStringContainsString('x-data="appShell"', $roomHtml);
        $this->assertStringContainsString('@click="toggleSidebar()"', $roomHtml);
        $this->assertStringContainsString('x-data="dropTarget"', $roomHtml);
        $this->assertStringContainsString('@drop="drop($event)"', $roomHtml);
        $this->assertStringContainsString('x-data="softKeyboard"', $roomHtml);
        $this->assertStringContainsString('x-data="messagePopup"', $roomHtml);
        $this->assertStringContainsString('@click.outside="close()"', $roomHtml);
        $this->assertStringContainsString('clipboard(', $roomHtml);
        $this->assertStringContainsString('/rooms/'.$room->id.'/@'.$message->id, $roomHtml);
        $this->assertStringContainsString('webShare(', $profileHtml);
        $this->assertStringContainsString('@click.prevent="openLightbox($el)"', $presentation);

        $this->assertStringContainsString('this.$refs.lightbox.showModal()', $alpine);
        $this->assertStringContainsString('navigator.clipboard.writeText(content)', $alpine);
        $this->assertStringContainsString('this.$dispatch("campfire:drop", { files: event.dataTransfer.files })', $alpine);
        $this->assertStringContainsString('this.$refs.menu.getBoundingClientRect()', $alpine);
        $this->assertStringContainsString('document.createElement("input")', $alpine);
        $this->assertStringContainsString('await navigator.share(data)', $alpine);
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
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $elements = (new DOMXPath($document))->query('//*[@data-testid="'.$testId.'"]');

        $this->assertNotFalse($elements);
        $this->assertCount(1, $elements, "Expected exactly one [data-testid=\"{$testId}\"].");
    }
}
