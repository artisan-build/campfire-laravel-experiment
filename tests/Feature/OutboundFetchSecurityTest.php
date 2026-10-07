<?php

namespace Tests\Feature;

use App\Http\Controllers\LinksController;
use App\Jobs\DeliverPush;
use App\Support\PushEndpoints;
use App\Support\WebhookDestinations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\TestCase;

final class OutboundFetchSecurityTest extends TestCase
{
    public function test_push_endpoints_reject_non_global_answers_and_accept_a_real_push_service_with_a_global_answer(): void
    {
        foreach (['240.0.0.1', 'fec0::1'] as $address) {
            $endpoints = new PushEndpoints(new WebhookDestinations(fn (string $host): array => [$address]));

            $this->assertNull($endpoints->resolve('https://fcm.googleapis.com/push-token'));
        }

        $endpoints = new PushEndpoints(new WebhookDestinations(fn (string $host): array => ['93.184.216.34']));

        $this->assertSame([
            'host' => 'fcm.googleapis.com',
            'port' => 443,
            'ip' => '93.184.216.34',
        ], $endpoints->resolve('https://fcm.googleapis.com/push-token'));
    }

    public function test_push_delivery_resolves_once_and_pins_the_classified_address_on_the_actual_client(): void
    {
        $resolverCalls = 0;
        $destinations = new WebhookDestinations(function (string $host) use (&$resolverCalls): array {
            $resolverCalls++;

            return $resolverCalls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });
        $this->app->instance(PushEndpoints::class, new PushEndpoints($destinations));
        config([
            'campfire.vapid.public_key' => 'test-public-key',
            'campfire.vapid.private_key' => 'test-private-key',
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSubscriptionExpired')->once()->andReturnFalse();
        $report->shouldReceive('isSuccess')->once()->andReturnTrue();
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->andReturn($report);
        $clientOptions = null;
        $this->app->bind(WebPush::class, function ($app, array $parameters) use ($client, &$clientOptions) {
            $clientOptions = $parameters['clientOptions'];

            return $client;
        });

        (new DeliverPush([
            'id' => 1,
            'user_id' => 1,
            'endpoint' => 'https://fcm.googleapis.com/push-token',
            'p256dh_key' => 'test-public-key',
            'auth_key' => 'test-auth-key',
        ], ['title' => 'Test']))->handle();

        $this->assertSame(1, $resolverCalls);
        $this->assertSame(['fcm.googleapis.com:443:93.184.216.34'], $clientOptions['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $clientOptions['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $clientOptions['curl'][CURLOPT_NOPROXY]);
        $this->assertSame('', $clientOptions['proxy']);
        $this->assertFalse($clientOptions['allow_redirects']);
    }

    public function test_push_delivery_refuses_non_global_ipv4_and_ipv6_answers_before_creating_a_client(): void
    {
        $client = Mockery::mock(WebPush::class);
        $client->shouldNotReceive('sendOneNotification');
        $this->app->instance(WebPush::class, $client);

        foreach (['240.0.0.1', 'fec0::1'] as $address) {
            $resolverCalls = 0;
            $destinations = new WebhookDestinations(function (string $host) use ($address, &$resolverCalls): array {
                $resolverCalls++;

                return [$address];
            });
            $this->app->instance(PushEndpoints::class, new PushEndpoints($destinations));

            (new DeliverPush([
                'id' => 1,
                'user_id' => 1,
                'endpoint' => 'https://fcm.googleapis.com/push-token',
                'p256dh_key' => 'test-public-key',
                'auth_key' => 'test-auth-key',
            ], ['title' => 'Test']))->handle();

            $this->assertSame(1, $resolverCalls);
        }
    }

    public function test_link_unfurl_rejects_non_global_ipv4_and_ipv6_answers_before_sending(): void
    {
        foreach (['240.0.0.1', 'fec0::1'] as $address) {
            $this->app->instance(WebhookDestinations::class, new WebhookDestinations(fn (string $host): array => [$address]));
            Http::fake();

            $response = app(LinksController::class)->unfurl($this->unfurlRequest('https://metadata.example/article'));

            $this->assertSame(204, $response->getStatusCode());
            Http::assertNothingSent();
        }
    }

    public function test_link_unfurl_resolves_once_and_pins_the_classified_address_on_the_actual_request(): void
    {
        $resolverCalls = 0;
        $this->app->instance(WebhookDestinations::class, new WebhookDestinations(function (string $host) use (&$resolverCalls): array {
            $resolverCalls++;

            return $resolverCalls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        }));
        $requestOptions = null;
        Http::fake(function ($request, array $options) use (&$requestOptions) {
            $requestOptions = $options;

            return Http::response('<meta property="og:title" content="Title"><meta property="og:description" content="Description">');
        });

        $response = app(LinksController::class)->unfurl($this->unfurlRequest('https://metadata.example/article'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $resolverCalls);
        $this->assertSame(['metadata.example:443:93.184.216.34'], $requestOptions['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $requestOptions['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $requestOptions['curl'][CURLOPT_NOPROXY]);
        $this->assertSame('', $requestOptions['proxy']);
        $this->assertFalse($requestOptions['allow_redirects']);
    }

    public function test_link_unfurl_resolves_classifies_and_pins_each_redirect_hop(): void
    {
        $answers = [
            'first.example' => ['93.184.216.34'],
            'second.example' => ['1.1.1.1'],
        ];
        $resolvedHosts = [];
        $this->app->instance(WebhookDestinations::class, new WebhookDestinations(function (string $host) use ($answers, &$resolvedHosts): array {
            $resolvedHosts[] = $host;

            return $answers[$host];
        }));
        $requests = [];
        Http::fake(function ($request, array $options) use (&$requests) {
            $requests[] = ['url' => $request->url(), 'options' => $options];

            if (count($requests) === 1) {
                return Http::response('', 302, ['Location' => 'https://second.example/final']);
            }

            return Http::response('<meta property="og:title" content="Title"><meta property="og:description" content="Description">');
        });

        $response = app(LinksController::class)->unfurl($this->unfurlRequest('https://first.example/start'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['first.example', 'second.example'], $resolvedHosts);
        $this->assertSame('https://first.example/start', $requests[0]['url']);
        $this->assertSame(['first.example:443:93.184.216.34'], $requests[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('https://second.example/final', $requests[1]['url']);
        $this->assertSame(['second.example:443:1.1.1.1'], $requests[1]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_link_unfurl_refuses_a_redirect_hop_that_resolves_non_global(): void
    {
        $answers = [
            'first.example' => ['93.184.216.34'],
            'second.example' => ['fec0::1'],
        ];
        $this->app->instance(WebhookDestinations::class, new WebhookDestinations(fn (string $host): array => $answers[$host]));
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://second.example/private']));

        $response = app(LinksController::class)->unfurl($this->unfurlRequest('https://first.example/start'));

        $this->assertSame(204, $response->getStatusCode());
        Http::assertSentCount(1);
    }

    private function unfurlRequest(string $url): Request
    {
        return Request::create('/unfurl_link', 'POST', ['url' => $url]);
    }
}
