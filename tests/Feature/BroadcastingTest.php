<?php

namespace Tests\Feature;

use App\Events\RoomRead;
use App\Events\RoomUnread;
use App\Events\TurboStreamBroadcast;
use App\Events\TypingNotification;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BroadcastingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_posted_message_broadcasts_the_room_stream_and_one_unread_per_member(): void
    {
        [$author, $room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $this->auth($author);
        Event::fake([TurboStreamBroadcast::class, RoomUnread::class]);

        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => '<p>Coffee</p>', 'client_message_id' => 'abc']])->assertOk();

        Event::assertDispatched(TurboStreamBroadcast::class, function (TurboStreamBroadcast $event) use ($room) {
            return $event->channel === 'rooms.'.$room->id
                && str_contains($event->html, 'Coffee')
                && $event->broadcastOn()[0]->name === 'private-rooms.'.$room->id
                && $event->broadcastAs() === 'turbo-stream';
        });

        foreach ([$author->id, $other->id] as $id) {
            Event::assertDispatched(RoomUnread::class, fn (RoomUnread $event) => $event->userId === $id && $event->roomId === $room->id);
        }
    }

    public function test_an_edit_and_a_delete_each_broadcast_to_the_room(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Before</p>']);
        $this->auth($author);
        Event::fake([TurboStreamBroadcast::class]);

        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => '<p>After</p>']])->assertRedirect();
        Event::assertDispatched(TurboStreamBroadcast::class, fn (TurboStreamBroadcast $e) => $e->channel === 'rooms.'.$room->id && str_contains($e->html, 'After'));

        $this->delete('/rooms/'.$room->id.'/messages/'.$message->id)->assertOk();
        Event::assertDispatched(TurboStreamBroadcast::class, fn (TurboStreamBroadcast $e) => str_contains($e->html, 'action="remove"'));
    }

    public function test_an_oversized_fragment_broadcasts_a_pointer_instead_of_html(): void
    {
        config(['campfire.broadcast_payload_limit' => 64]);

        $small = new TurboStreamBroadcast('rooms.1', '<turbo-stream></turbo-stream>', 1);
        $large = new TurboStreamBroadcast('rooms.1', str_repeat('x', 200), 1);

        $this->assertSame(['html' => '<turbo-stream></turbo-stream>'], $small->broadcastWith());
        $this->assertSame(['oversize' => true, 'roomId' => 1], $large->broadcastWith());
    }

    public function test_the_typing_endpoint_broadcasts_only_for_members(): void
    {
        [$author, $room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);

        $this->auth($stranger);
        Event::fake([TypingNotification::class]);
        $this->post('/rooms/'.$room->id.'/typing', ['action' => 'start'])->assertForbidden();
        Event::assertNothingDispatched();

        $this->flushSession();
        $this->auth($author);
        $this->post('/rooms/'.$room->id.'/typing', ['action' => 'start'])->assertNoContent();
        Event::assertDispatched(TypingNotification::class, function (TypingNotification $event) use ($room, $author) {
            return $event->roomId === $room->id
                && $event->broadcastOn()[0]->name === 'private-rooms.'.$room->id.'.typing'
                && $event->broadcastWith() === ['action' => 'start', 'user' => ['id' => $author->id, 'name' => $author->name]];
        });

        $this->post('/rooms/'.$room->id.'/typing', ['action' => 'nonsense'])->assertStatus(302);
    }

    public function test_the_presence_endpoint_records_presence_only_for_members(): void
    {
        [$author, $room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        $membership = $room->memberships()->first();
        $membership->update(['unread_at' => now()]);

        $this->auth($stranger);
        $this->post('/rooms/'.$room->id.'/presence', ['action' => 'present'])->assertForbidden();
        $this->assertSame(0, $membership->fresh()->connections);

        $this->flushSession();
        $this->auth($author);
        Event::fake([RoomRead::class]);
        $this->post('/rooms/'.$room->id.'/presence', ['action' => 'present'])->assertNoContent();
        $this->assertSame(1, $membership->fresh()->connections);
        $this->assertNull($membership->fresh()->unread_at);
        Event::assertDispatched(RoomRead::class);

        $this->post('/rooms/'.$room->id.'/presence', ['action' => 'absent'])->assertNoContent();
        $this->assertSame(0, $membership->fresh()->connections);
    }

    public function test_the_room_page_names_its_own_channel_and_the_sidebar_names_the_users(): void
    {
        [$author, $room] = $this->fixture();
        $this->auth($author);

        $this->get('/rooms/'.$room->id)->assertOk()
            ->assertSee('<turbo-echo-stream-source channel="rooms.'.$room->id.'"', false);

        $this->get('/users/me/sidebar')->assertOk()
            ->assertSee('<turbo-echo-stream-source channel="rooms">', false)
            ->assertSee('<turbo-echo-stream-source channel="users.'.$author->id.'.rooms">', false);
    }

    public function test_a_direct_message_refreshes_both_sidebars(): void
    {
        [$author, $room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        $direct = Room::create(['name' => null, 'type' => 'Rooms::Direct', 'creator_id' => $author->id]);
        foreach ([$author->id, $other->id] as $id) {
            Membership::create(['room_id' => $direct->id, 'user_id' => $id, 'involvement' => 'everything']);
        }
        Event::fake([TurboStreamBroadcast::class]);

        app(MessageWriter::class)->create($direct, $author, ['body' => '<p>Hi</p>']);

        foreach ([$author->id, $other->id] as $id) {
            Event::assertDispatched(TurboStreamBroadcast::class, fn (TurboStreamBroadcast $e) => $e->channel === 'users.'.$id.'.rooms');
        }
    }
}
