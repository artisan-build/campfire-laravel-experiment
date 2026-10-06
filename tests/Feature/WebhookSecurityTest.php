<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessageNotifications;
use App\Livewire\BotForm;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\WebhookDestinations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public function test_livewire_and_compatibility_bot_saves_reject_non_public_webhooks(): void
    {
        [$administrator] = $this->fixture();

        Livewire::actingAs($administrator)->test(BotForm::class)
            ->set('name', 'Private Relay')
            ->set('webhookUrl', 'http://127.0.0.1:8080/internal')
            ->call('save')
            ->assertHasErrors(['webhookUrl']);

        $this->auth($administrator);
        $this->post(route('bots.store'), ['user' => [
            'name' => 'Mixed Relay',
            'bio' => '',
            'webhook_url' => 'https://mixed.example/hook',
        ]])->assertSessionHasErrors(['user.webhook_url']);

        $this->assertDatabaseMissing('users', ['name' => 'Private Relay']);
        $this->assertDatabaseMissing('users', ['name' => 'Mixed Relay']);
    }

    public function test_policy_rejects_every_non_public_resolution_and_builds_a_pinned_no_redirect_request(): void
    {
        $policy = new WebhookDestinations(fn (string $host): array => $host === 'mixed.example'
            ? ['93.184.216.34', '169.254.169.254']
            : ['93.184.216.34']);

        $this->assertNull($policy->resolve('https://mixed.example/hook'));
        $destination = $policy->resolve('https://public.example:8443/hook');
        $this->assertSame(['host' => 'public.example', 'port' => 8443, 'ip' => '93.184.216.34'], $destination);
        $this->assertSame([
            'allow_redirects' => false,
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => ['public.example:8443:93.184.216.34']],
        ], $policy->requestOptions($destination));
    }

    public function test_delivery_revalidates_the_destination_and_streams_a_bounded_text_reply(): void
    {
        $resolutions = 0;
        app()->instance(WebhookDestinations::class, new WebhookDestinations(function (string $host) use (&$resolutions): array {
            $resolutions++;

            return $host === 'fixture.test' ? ['93.184.216.34'] : [];
        }));
        [$message] = $this->webhookFixture('http://fixture.test/hook');
        Http::fake(['fixture.test/*' => Http::response('Bounded reply', 200, ['Content-Type' => 'text/plain'])]);

        (new DeliverMessageNotifications($message->id, true))->handle();

        $this->assertSame(1, $resolutions);
        $this->assertSame('Bounded reply', Message::where('creator_id', '!=', $message->creator_id)->firstOrFail()->plainText());
        Http::assertSentCount(1);
    }

    public function test_oversized_text_responses_are_not_persisted(): void
    {
        app()->instance(WebhookDestinations::class, new WebhookDestinations(fn (): array => ['93.184.216.34']));
        [$textMessage] = $this->webhookFixture('https://text.example/hook');
        Http::fake(['text.example/*' => Http::response(
            str_repeat('x', WebhookDestinations::MAX_RESPONSE_BYTES + 1),
            200,
            ['Content-Type' => 'text/plain'],
        )]);
        (new DeliverMessageNotifications($textMessage->id, true))->handle();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_oversized_attachment_responses_are_not_persisted(): void
    {
        app()->instance(WebhookDestinations::class, new WebhookDestinations(fn (): array => ['93.184.216.34']));
        [$attachmentMessage] = $this->webhookFixture('https://attachment.example/hook');
        Http::fake(['attachment.example/*' => Http::response('not buffered', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) (WebhookDestinations::MAX_RESPONSE_BYTES + 1),
        ])]);
        (new DeliverMessageNotifications($attachmentMessage->id, true))->handle();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('active_storage_attachments', 0);
    }

    /** @return array{0: Message, 1: User} */
    private function webhookFixture(string $url): array
    {
        Queue::fake();
        [$author, $room] = $this->fixture();
        $room->update(['type' => 'Rooms::Direct']);
        $bot = User::create(['name' => 'Relay', 'bot_token' => 'relay-token', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'everything']);
        DB::table('webhooks')->insert(['user_id' => $bot->id, 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
        $message = app(MessageWriter::class)->create($room, $author, ['body' => 'Hello'], true);

        return [$message, $bot];
    }
}
