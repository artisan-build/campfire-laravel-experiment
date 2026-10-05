<?php

namespace Tests\Feature;

use App\Events\RoomUnread;
use App\Events\TurboStreamBroadcast;
use App\Events\TypingNotification;
use App\Http\Controllers\ChatController;
use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Boost;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use Illuminate\Support\Facades\Artisan;
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

    public function test_a_fragment_is_sent_inline_then_gzipped_then_as_a_pointer(): void
    {
        config(['campfire.broadcast_payload_limit' => 2000]);

        $inline = (new TurboStreamBroadcast('rooms.1', '<turbo-stream></turbo-stream>', 1))->broadcastWith();
        $this->assertSame(['html' => '<turbo-stream></turbo-stream>'], $inline);

        // Repetitive HTML is past the limit raw and well under it compressed.
        $html = str_repeat('<turbo-stream action="append" target="messages_room_1"></turbo-stream>', 200);
        $compressed = (new TurboStreamBroadcast('rooms.1', $html, 1))->broadcastWith();
        $this->assertArrayHasKey('gz', $compressed);
        $this->assertLessThanOrEqual(2000, strlen($compressed['gz']));
        $this->assertSame($html, gzdecode(base64_decode($compressed['gz'])));

        // Incompressible and past the limit: nothing left but a pointer.
        $pointer = (new TurboStreamBroadcast('rooms.1', random_bytes(4096), 1))->broadcastWith();
        $this->assertSame(['oversize' => true, 'roomId' => 1], $pointer);
    }

    public function test_a_real_message_fragment_fits_in_a_reverb_frame_once_compressed(): void
    {
        [$author, $room] = $this->fixture();
        app(MessageWriter::class)->create($room, $author, ['body' => '<p>A message of ordinary length, the kind people actually send.</p>']);
        $message = Message::presentation()->first();
        $html = app(ChatController::class)->stream('append', 'messages_room_'.$room->id, view('messages.message', ['message' => $message])->render());

        $payload = (new TurboStreamBroadcast('rooms.'.$room->id, $html, $room->id))->broadcastWith();

        // Reverb's managed application caps a frame at 10 000 bytes.
        $this->assertLessThan(10000, strlen(json_encode($payload)));
        $this->assertArrayNotHasKey('oversize', $payload);
    }

    public function test_the_current_message_broadcast_stays_within_the_application_payload_budget(): void
    {
        [$author, $room] = $this->fixture();
        $body = '<p>'.substr(str_repeat(hash('sha256', 'frame-budget-fixture'), 65), 0, 4096).'</p>';
        $message = app(MessageWriter::class)->create($room, $author, ['body' => $body]);
        $blob = Blob::create([
            'key' => 'framebudgetattachment',
            'filename' => 'quarterly-plan.pdf',
            'content_type' => 'application/pdf',
            'metadata' => '{}',
            'service_name' => 'campfire',
            'byte_size' => 4096,
            'created_at' => now(),
        ]);
        Attachment::create([
            'name' => 'attachment',
            'record_type' => 'Message',
            'record_id' => $message->id,
            'blob_id' => $blob->id,
            'created_at' => now(),
        ]);

        foreach (range(1, 10) as $number) {
            $booster = User::create(['name' => 'Budget Booster '.$number, 'role' => 0, 'status' => 0]);
            Boost::create(['message_id' => $message->id, 'booster_id' => $booster->id, 'content' => 'boost-'.$number]);
        }

        $message = Message::presentation()->findOrFail($message->id);
        $this->assertSame(10, $message->boosts()->count());
        $this->assertSame('quarterly-plan.pdf', $message->attachment()->firstOrFail()->blob()->firstOrFail()->filename);

        $html = app(ChatController::class)->stream('append', 'messages_room_'.$room->id, view('messages.message', compact('message'))->render());
        $payload = (new TurboStreamBroadcast('rooms.'.$room->id, $html, $room->id))->broadcastWith();
        $budget = 7000;

        $this->assertStringContainsString('quarterly-plan.pdf', $html);
        $this->assertStringContainsString('boost-10', $html);
        $this->assertSame($budget, config('campfire.broadcast_payload_limit'));
        $this->assertArrayHasKey('gz', $payload, 'The representative message must exercise a real payload, not the refresh pointer');
        $this->assertLessThanOrEqual(
            $budget,
            strlen(json_encode($payload, JSON_THROW_ON_ERROR)),
            'The serialized application payload exceeded its 7,000-byte Reverb safety budget',
        );
    }

    public function test_doctor_reports_the_active_broadcast_encoding(): void
    {
        $this->assertSame([
            'format' => 'turbo-stream-html',
            'variants' => ['inline', 'gzip+base64', 'refresh-pointer'],
        ], TurboStreamBroadcast::encoding());

        $this->assertSame(0, Artisan::call('campfire:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('broadcast encoding', $output);
        $this->assertStringContainsString('turbo-stream-html (inline, gzip+base64, refresh-pointer)', $output);
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
