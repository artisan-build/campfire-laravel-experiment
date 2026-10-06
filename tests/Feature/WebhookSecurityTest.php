<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessageNotifications;
use App\Livewire\BotForm;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Support\BoundedResponseStream;
use App\Support\MessageWriter;
use App\Support\WebhookDestinations;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public function test_livewire_and_compatibility_bot_saves_reject_non_global_webhooks_without_dns(): void
    {
        [$administrator] = $this->fixture();

        Livewire::actingAs($administrator)->test(BotForm::class)
            ->set('name', 'Benchmark Relay')
            ->set('webhookUrl', 'http://198.18.0.1:8080/internal')
            ->call('save')
            ->assertHasErrors(['webhookUrl']);

        $this->auth($administrator);
        $this->post(route('bots.store'), ['user' => [
            'name' => 'Documentation Relay',
            'bio' => '',
            'webhook_url' => 'https://[2001:db8::1]/hook',
        ]])->assertSessionHasErrors(['user.webhook_url']);

        $this->assertDatabaseMissing('users', ['name' => 'Benchmark Relay']);
        $this->assertDatabaseMissing('users', ['name' => 'Documentation Relay']);
    }

    public function test_policy_rejects_every_non_public_resolution_and_builds_a_pinned_no_redirect_request(): void
    {
        $policy = new WebhookDestinations(fn (string $host): array => $host === 'mixed.example'
            ? ['93.184.216.34', '169.254.169.254']
            : ['93.184.216.34']);

        $this->assertNull($policy->resolve('https://mixed.example/hook'));
        $destination = $policy->resolve('https://public.example:8443/hook');
        $this->assertSame(['host' => 'public.example', 'port' => 8443, 'ip' => '93.184.216.34'], $destination);
        $resource = fopen('php://temp', 'w+b');
        $this->assertIsResource($resource);
        $sink = new BoundedResponseStream(Utils::streamFor($resource));
        $options = $policy->requestOptions($destination, $sink);

        $this->assertFalse($options['allow_redirects']);
        $this->assertArrayNotHasKey('stream', $options);
        $this->assertSame($sink, $options['sink']);
        $this->assertSame(['public.example:8443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
    }

    public function test_policy_allows_public_unicast_and_rejects_every_non_global_address_class_before_resolution(): void
    {
        $resolverCalls = 0;
        $policy = new WebhookDestinations(function () use (&$resolverCalls): array {
            $resolverCalls++;

            return ['93.184.216.34'];
        });

        foreach ([
            'http://0.0.0.1/hook',
            'http://127.0.0.1/hook',
            'http://10.0.0.1/hook',
            'http://169.254.169.254/latest/meta-data',
            'http://100.64.0.1/hook',
            'http://172.16.0.1/hook',
            'http://192.0.0.1/hook',
            'http://192.0.2.1/hook',
            'http://192.31.196.1/hook',
            'http://192.52.193.1/hook',
            'http://192.88.99.1/hook',
            'http://192.168.1.1/hook',
            'http://192.175.48.1/hook',
            'http://198.18.0.1/hook',
            'http://198.51.100.1/hook',
            'http://203.0.113.1/hook',
            'http://224.0.0.1/hook',
            'http://239.255.255.255/hook',
            'http://240.0.0.1/hook',
            'http://[::1]/hook',
            'http://[::7f00:1]/hook',
            'http://[64:ff9b::7f00:1]/hook',
            'http://[64:ff9b:1::7f00:1]/hook',
            'http://[100::1]/hook',
            'http://[100:0:0:1::1]/hook',
            'http://[2001::1]/hook',
            'http://[2001:db8::1]/hook',
            'http://[2002:7f00:1::]/hook',
            'http://[2620:4f:8000::1]/hook',
            'http://[3fff::1]/hook',
            'http://[fc00::1]/hook',
            'http://[fe80::1]/hook',
            'http://[ff02::1]/hook',
            'http://[::ffff:127.0.0.1]/hook',
            'http://[::ffff:8.8.8.8]/hook',
            'http://2130706433/hook',
            'http://0177.0.0.1/hook',
            'http://0x7f000001/hook',
            'http://127.1/hook',
            'http://%31%32%37.0.0.1/hook',
        ] as $url) {
            $this->assertNull($policy->resolve($url), $url);
        }

        $this->assertSame(['host' => '93.184.216.34', 'port' => 80, 'ip' => '93.184.216.34'], $policy->resolve('http://93.184.216.34/hook'));
        $this->assertSame(['host' => '2606:4700:4700::1111', 'port' => 443, 'ip' => '2606:4700:4700::1111'], $policy->resolve('https://[2606:4700:4700::1111]/hook'));
        $this->assertSame(0, $resolverCalls);
    }

    public function test_a_rebinding_resolver_is_consulted_once_and_the_public_answer_is_the_only_connection_pin(): void
    {
        $answers = [['93.184.216.34'], ['127.0.0.1']];
        $policy = new WebhookDestinations(function () use (&$answers): array {
            return array_shift($answers);
        });
        $destination = $policy->resolve('http://localhost:8080/hook');
        $resource = fopen('php://temp', 'w+b');
        $this->assertIsResource($resource);
        $options = $policy->requestOptions($destination, new BoundedResponseStream(Utils::streamFor($resource)));

        $this->assertSame(['127.0.0.1'], $answers[0]);
        $this->assertSame(['localhost:8080:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_delivery_revalidates_the_destination_and_streams_a_bounded_text_reply(): void
    {
        $resolutions = 0;
        $answers = [['93.184.216.34'], ['127.0.0.1']];
        app()->instance(WebhookDestinations::class, new WebhookDestinations(function (string $host) use (&$answers, &$resolutions): array {
            $resolutions++;

            return $host === 'fixture.test' ? array_shift($answers) : [];
        }));
        [$message] = $this->webhookFixture('http://fixture.test/hook');
        $connectionPin = null;
        Http::fake(function ($request, array $options) use (&$connectionPin) {
            $connectionPin = $options['curl'][CURLOPT_RESOLVE];
            $options['sink']->write('Bounded reply');

            return Http::response('', 200, ['Content-Type' => 'text/plain']);
        });

        (new DeliverMessageNotifications($message->id, true))->handle();

        $this->assertSame(1, $resolutions);
        $this->assertSame([['127.0.0.1']], $answers);
        $this->assertSame(['fixture.test:80:93.184.216.34'], $connectionPin);
        $this->assertSame('Bounded reply', Message::where('creator_id', '!=', $message->creator_id)->firstOrFail()->plainText());
        Http::assertSentCount(1);
    }

    public function test_delivery_rejects_a_persisted_non_global_destination_before_sending(): void
    {
        [$message] = $this->webhookFixture('http://198.18.0.1/hook');
        Http::fake();

        (new DeliverMessageNotifications($message->id, true))->handle();

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_oversized_text_responses_are_not_persisted(): void
    {
        app()->instance(WebhookDestinations::class, new WebhookDestinations(fn (): array => ['93.184.216.34']));
        [$textMessage] = $this->webhookFixture('https://text.example/hook');
        Http::fake(function ($request, array $options) {
            $options['sink']->write(str_repeat('x', WebhookDestinations::MAX_RESPONSE_BYTES + 1));

            return Http::response('', 200, ['Content-Type' => 'text/plain']);
        });
        (new DeliverMessageNotifications($textMessage->id, true))->handle();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_oversized_attachment_responses_are_not_persisted(): void
    {
        app()->instance(WebhookDestinations::class, new WebhookDestinations(fn (): array => ['93.184.216.34']));
        [$attachmentMessage] = $this->webhookFixture('https://attachment.example/hook');
        Http::fake(function ($request, array $options) {
            $options['sink']->write(str_repeat('x', WebhookDestinations::MAX_RESPONSE_BYTES + 1));

            return Http::response('', 200, ['Content-Type' => 'application/pdf']);
        });
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
