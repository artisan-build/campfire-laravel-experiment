<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessageNotifications;
use App\Jobs\DeliverPush;
use App\Models\Membership;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\Presence;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Pusher\Pusher;
use Tests\TestCase;

/**
 * Presence is whatever Reverb says it is. These pin the three things that follow from that: the
 * lookup itself, the unread decision, and what happens when Reverb cannot be reached.
 */
final class PresenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @param  array<int, int>|\Throwable  $users
     */
    private function reverbReports(array|\Throwable $users): void
    {
        $pusher = \Mockery::mock(Pusher::class);

        if ($users instanceof \Throwable) {
            $pusher->shouldReceive('getPresenceUsers')->andThrow($users);
        } else {
            $pusher->shouldReceive('getPresenceUsers')
                ->andReturn((object) ['users' => array_map(static fn ($id) => (object) ['id' => (string) $id], $users)]);
        }

        $broadcaster = new PusherBroadcaster($pusher);
        Broadcast::extend('reverb-fake', fn () => $broadcaster);
        config(['broadcasting.default' => 'reverb-fake', 'broadcasting.connections.reverb-fake' => ['driver' => 'reverb-fake']]);
    }

    public function test_the_lookup_asks_reverb_for_the_rooms_presence_channel(): void
    {
        $pusher = \Mockery::mock(Pusher::class);
        $pusher->shouldReceive('getPresenceUsers')
            ->once()
            ->with('presence-rooms.7.presence')
            ->andReturn((object) ['users' => [(object) ['id' => '3'], (object) ['id' => '9'], (object) ['id' => '3']]]);
        Broadcast::extend('reverb-fake', fn () => new PusherBroadcaster($pusher));
        config(['broadcasting.default' => 'reverb-fake', 'broadcasting.connections.reverb-fake' => ['driver' => 'reverb-fake']]);

        $this->assertSame([3, 9], app(Presence::class)->inRoom(7));
    }

    public function test_a_member_reverb_reports_as_present_is_not_marked_unread(): void
    {
        [$author, $room] = $this->fixture();
        $watching = User::create(['name' => 'Watching', 'role' => 0, 'status' => 0]);
        $away = User::create(['name' => 'Away', 'role' => 0, 'status' => 0]);
        foreach ([$watching->id, $away->id] as $id) {
            Membership::create(['room_id' => $room->id, 'user_id' => $id, 'involvement' => 'everything']);
        }
        $this->reverbReports([$watching->id]);

        app(MessageWriter::class)->create($room, $author, ['body' => '<p>Coffee</p>']);

        $this->assertNull($room->memberships()->where('user_id', $watching->id)->value('unread_at'));
        $this->assertNotNull($room->memberships()->where('user_id', $away->id)->value('unread_at'));
        $this->assertNull($room->memberships()->where('user_id', $author->id)->value('unread_at'));
    }

    public function test_an_unreachable_reverb_fails_open_so_everyone_is_still_notified(): void
    {
        [$author, $room] = $this->fixture();
        $watching = User::create(['name' => 'Watching', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $watching->id, 'involvement' => 'everything']);
        $this->reverbReports(new \RuntimeException('connection refused'));

        $this->assertSame([], app(Presence::class)->inRoom($room->id));

        app(MessageWriter::class)->create($room, $author, ['body' => '<p>Coffee</p>']);

        $this->assertNotNull($room->memberships()->where('user_id', $watching->id)->value('unread_at'));
    }

    public function test_with_no_reverb_configured_nobody_is_present(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertSame([], app(Presence::class)->inRoom(1));
    }

    public function test_push_is_suppressed_for_a_member_reverb_reports_as_present(): void
    {
        [$author, $room] = $this->fixture();
        $watching = User::create(['name' => 'Watching', 'role' => 0, 'status' => 0]);
        $away = User::create(['name' => 'Away', 'role' => 0, 'status' => 0]);
        foreach ([$watching->id, $away->id] as $id) {
            Membership::create(['room_id' => $room->id, 'user_id' => $id, 'involvement' => 'everything']);
            DB::table('push_subscriptions')->insert([
                'user_id' => $id, 'endpoint' => 'https://fcm.googleapis.com/'.$id,
                'p256dh_key' => 'key', 'auth_key' => 'auth', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Coffee</p>']);
        $this->reverbReports([$watching->id]);
        Queue::fake();

        (new DeliverMessageNotifications($message->id))->handle();

        Queue::assertPushed(DeliverPush::class, 1);
        Queue::assertPushed(DeliverPush::class, fn (DeliverPush $job) => (int) $job->subscription['user_id'] === $away->id);
    }
}
