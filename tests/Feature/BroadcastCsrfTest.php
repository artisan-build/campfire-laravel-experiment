<?php

namespace Tests\Feature;

use App\Events\RoomRead;
use App\Events\RoomUnread;
use App\Events\TurboStreamBroadcast;
use App\Events\TypingNotification;
use App\Models\Boost;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A broadcast fragment is rendered once, inside one user's request, and delivered to every other
 * member of the room. Nothing session-scoped may travel in it — above all not the actor's CSRF
 * token, whose only value is that nobody else knows it.
 *
 * These tests assert the property over the whole broadcast class, not over one partial: every
 * payload produced by a battery of actions is decoded and scanned.
 */
final class BroadcastCsrfTest extends TestCase
{
    /** @var array<int, object> */
    private array $broadcasts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->broadcasts = [];
        foreach ([TurboStreamBroadcast::class, RoomUnread::class, RoomRead::class, TypingNotification::class] as $event) {
            Event::listen($event, function (object $broadcast) {
                $this->broadcasts[] = $broadcast;
            });
        }
    }

    public function test_no_broadcast_payload_carries_the_actors_csrf_token(): void
    {
        [$author, $room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $this->auth($author);

        // Establish the session so there is a real token to leak, then exercise every action that
        // broadcasts: post, edit, boost, unboost, delete.
        $this->get('/rooms/'.$room->id)->assertOk();
        $token = session()->token();
        $this->assertNotSame('', $token);

        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => '<p>Coffee</p>', 'client_message_id' => 'abc']])->assertOk();
        $message = $room->messages()->firstOrFail();
        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => '<p>Tea</p>']])->assertRedirect();
        $this->post('/messages/'.$message->id.'/boosts', ['boost' => ['content' => '👍']])->assertRedirect();
        $boost = Boost::where('message_id', $message->id)->firstOrFail();
        $this->delete('/messages/'.$message->id.'/boosts/'.$boost->id)->assertOk();
        $this->delete('/rooms/'.$room->id.'/messages/'.$message->id)->assertOk();

        $this->assertGreaterThanOrEqual(5, count($this->broadcasts), 'the battery should have produced broadcasts to scan');
        $this->assertTokenAbsentFromEveryBroadcast($token);
    }

    public function test_a_bot_message_broadcast_carries_no_csrf_token(): void
    {
        [$author, $room] = $this->fixture();
        $bot = User::create(['name' => 'Robot', 'role' => 2, 'status' => 0, 'bot_token' => 'tok']);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'everything']);
        $this->auth($author);
        $this->get('/rooms/'.$room->id)->assertOk();
        $token = session()->token();
        $this->broadcasts = [];

        $this->call('POST', '/rooms/'.$room->id.'/'.$bot->id.'-tok/messages', [], [], [], [], 'Beep')->assertCreated();

        $this->assertNotEmpty($this->broadcasts);
        $this->assertTokenAbsentFromEveryBroadcast($token);
        // and no *other* session token was minted into it either
        foreach ($this->payloads() as $payload) {
            $this->assertStringNotContainsString('authenticity_token', $payload);
            $this->assertStringNotContainsString('name="_token"', $payload);
        }
    }

    public function test_a_sidebar_broadcast_carries_no_csrf_token(): void
    {
        [$author, $room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $this->auth($author);
        $this->get('/rooms/'.$room->id)->assertOk();
        $token = session()->token();
        $this->broadcasts = [];

        $this->post('/rooms/directs', ['user_ids' => [$other->id]])->assertRedirect();

        $this->assertNotEmpty($this->broadcasts);
        $this->assertTokenAbsentFromEveryBroadcast($token);
    }

    public function test_a_second_viewer_boosts_from_the_broadcast_markup_using_only_their_own_header_token(): void
    {
        [$author, $room] = $this->fixture();
        $viewer = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $viewer->id, 'involvement' => 'everything']);
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Coffee</p>']);

        $this->auth($author);
        $this->get('/rooms/'.$room->id)->assertOk();
        $this->broadcasts = [];
        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => '<p>Tea</p>', 'client_message_id' => 'abc']])->assertOk();

        $fragment = collect($this->broadcasts)->first(fn ($b) => $b instanceof TurboStreamBroadcast)->html;
        $this->assertStringContainsString('/boosts', $fragment, 'the broadcast fragment should carry the boost forms');
        $this->assertStringNotContainsString('authenticity_token', $fragment);
        $this->assertStringNotContainsString('name="_token"', $fragment);

        // Now be the OTHER viewer, with CSRF genuinely enforced, submitting that token-free form.
        $this->enforceCsrf();
        $this->auth($viewer);
        $page = $this->get('/rooms/'.$room->id)->assertOk();
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page->getContent(), $meta);
        $this->assertNotEmpty($meta[1] ?? '', 'the page must carry the viewer’s own token in the meta tag');
        $session = $this->campfireSessionCookie($page);

        $this->withUnencryptedCookie('_campfire_session', $session)
            ->post('/messages/'.$message->id.'/boosts', ['boost' => ['content' => '🔥']], ['X-CSRF-Token' => $meta[1]])
            ->assertRedirect();

        $this->assertDatabaseHas('boosts', ['message_id' => $message->id, 'booster_id' => $viewer->id, 'content' => '🔥']);
    }

    public function test_the_same_submission_without_any_token_is_still_rejected(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Coffee</p>']);
        $this->enforceCsrf();
        $this->auth($author);
        $page = $this->get('/rooms/'.$room->id)->assertOk();

        $this->withUnencryptedCookie('_campfire_session', $this->campfireSessionCookie($page))
            // 419 is what the app actually returns: Laravel maps TokenMismatchException to an
            // HttpException(419) in prepareException() before the 422 renderer in bootstrap/app.php
            // is consulted, so that renderer never fires. Asserting the real behaviour.
            ->post('/messages/'.$message->id.'/boosts', ['boost' => ['content' => '🔥']])
            ->assertStatus(419);

        $this->assertDatabaseMissing('boosts', ['message_id' => $message->id]);
    }

    /** Every broadcast payload, decoded through the same branch the browser decodes. */
    private function payloads(): array
    {
        return array_map(function (object $broadcast) {
            $payload = $broadcast->broadcastWith();
            if (isset($payload['gz'])) {
                return (string) gzdecode(base64_decode($payload['gz']));
            }

            return $payload['html'] ?? json_encode($payload);
        }, $this->broadcasts);
    }

    private function assertTokenAbsentFromEveryBroadcast(string $token): void
    {
        foreach ($this->payloads() as $index => $payload) {
            $this->assertSame(0, substr_count($payload, $token), 'broadcast #'.$index.' carries the actor’s CSRF token');
        }
        foreach ($this->broadcasts as $index => $broadcast) {
            if (property_exists($broadcast, 'html')) {
                $this->assertSame(0, substr_count($broadcast->html, $token), 'broadcast #'.$index.' carries the actor’s CSRF token before encoding');
            }
        }
    }

    /**
     * Laravel skips CSRF while the suite runs. Swap in a guard that does not, so a token-free form
     * is proved to work for a real reason and not because the check was off.
     */
    private function enforceCsrf(): void
    {
        $this->app->instance('env', 'production');
    }

    private function campfireSessionCookie($response): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === '_campfire_session') {
                return (string) $cookie->getValue();
            }
        }

        $this->fail('the response did not set _campfire_session');
    }
}
