<?php

namespace Tests\Feature;

use App\Events\BoostAdded;
use App\Events\BoostRemoved;
use App\Events\MessageDeleted;
use App\Events\MessagePosted;
use App\Events\MessageUpdated;
use App\Events\SidebarChanged;
use App\Events\TurboStreamBroadcast;
use App\Http\Resources\MessageResource;
use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Boost;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\RailsCrypto;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class JsonBroadcastingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_an_ordinary_posted_event_has_the_named_private_channel_and_stays_under_one_kib(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, [
            'body' => '<p>Deploy completed for the support team.</p>',
            'client_message_id' => 'ordinary-message',
        ]);

        $event = new MessagePosted($message);
        $payload = $event->broadcastWith();

        $this->assertSame('message.posted', $event->broadcastAs());
        $this->assertSame('private-rooms.'.$room->id, $event->broadcastOn()[0]->name);
        $this->assertSame($message->id, $payload['message']['id']);
        $this->assertSame('ordinary-message', $payload['message']['client_message_id']);
        $this->assertFalse($payload['message']['body']['truncated']);
        $this->assertLessThan(1000, strlen(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function test_all_five_json_events_remain_immediate_broadcasts(): void
    {
        foreach ([MessagePosted::class, MessageUpdated::class, MessageDeleted::class, BoostAdded::class, BoostRemoved::class] as $event) {
            $this->assertContains(ShouldBroadcastNow::class, class_implements($event));
        }
    }

    public function test_a_preloaded_resource_does_not_reload_its_relations(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>No mentions here.</p>']);
        $message = Message::presentation()->findOrFail($message->id);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        (new MessageResource($message))->resolve(new Request);

        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'action_text_rich_texts')
                || str_contains($sql, 'active_storage_attachments')
                || str_contains($sql, 'active_storage_blobs')
                || str_contains($sql, 'boosts')
                || str_contains($sql, 'rooms')
                || str_contains($sql, 'users'),
        )));
    }

    public function test_the_real_resource_shape_is_complete_and_the_combined_broadcast_is_bounded(): void
    {
        [$author, $room] = $this->fixture();
        $mentioned = User::create(['name' => 'Mentioned Teammate', 'role' => 0, 'status' => 0]);
        $tail = ' JSON-BROADCAST-TAIL-SENTINEL';
        $text = '';

        for ($number = 1; strlen($text) < 4096; $number++) {
            $text .= sprintf(
                'Update %d compares message %s with request %s. ',
                $number,
                rtrim(base64_encode(hash('sha256', 'message-'.$number, true)), '='),
                rtrim(base64_encode(hash('sha256', 'request-'.$number, true)), '='),
            );
        }

        $text = substr($text, 0, 4096 - strlen($tail)).$tail;
        $mention = '<action-text-attachment sgid="'.app(RailsCrypto::class)->sgid($mentioned->id).'" content-type="application/vnd.campfire.mention"></action-text-attachment>';
        $message = app(MessageWriter::class)->create($room, $author, [
            'body' => '<p>'.$text.'</p><p>'.$mention.'</p>',
            'client_message_id' => 'combined-budget-message',
        ]);
        $blob = Blob::create([
            'key' => 'jsonframebudgetattachment',
            'filename' => 'architecture-diagram.png',
            'content_type' => 'image/png',
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

        $boostIds = [];
        foreach (range(1, 10) as $number) {
            $booster = User::create(['name' => 'Budget Booster '.$number, 'role' => 0, 'status' => 0]);
            $boostIds[] = Boost::create(['message_id' => $message->id, 'booster_id' => $booster->id, 'content' => 'boost-'.$number])->id;
        }
        sort($boostIds);

        $resource = (new MessageResource($message->fresh()))->resolve(new Request);
        $eventPayload = (new MessagePosted($message->fresh()))->broadcastWith();
        $broadcastMessage = $eventPayload['message'];
        $encoded = json_encode($eventPayload, JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['id', 'client_message_id', 'created_at', 'updated_at', 'body', 'creator', 'room', 'url', 'attachment', 'boosts', 'mentions'],
            array_keys($resource),
        );
        $this->assertStringContainsString($tail, $resource['body']['plain_text']);
        $this->assertStringContainsString($tail, $resource['body']['html']);
        $this->assertStringContainsString($tail, $resource['body']['editable_html']);
        $this->assertSame($author->id, $resource['creator']['id']);
        $this->assertSame('administrator', $resource['creator']['role']);
        $this->assertNotEmpty($resource['creator']['avatar_url']);
        $this->assertSame(['id' => $room->id, 'name' => $room->name, 'type' => $room->type], $resource['room']);
        $this->assertSame('architecture-diagram.png', $resource['attachment']['filename']);
        $this->assertSame('image/png', $resource['attachment']['content_type']);
        $this->assertNotEmpty($resource['attachment']['url']);
        $this->assertNotEmpty($resource['attachment']['representation_url']);
        $this->assertSame(4096, $resource['attachment']['byte_size']);
        $this->assertCount(10, $resource['boosts']);
        $this->assertSame($boostIds, array_column($resource['boosts'], 'id'));
        $this->assertSame([['id' => $mentioned->id, 'name' => $mentioned->name]], $resource['mentions']);

        $this->assertTrue($broadcastMessage['body']['truncated']);
        $this->assertSame($resource['url'], $broadcastMessage['url']);
        $this->assertSame($resource['attachment'], $broadcastMessage['attachment']);
        $this->assertSame($resource['boosts'], $broadcastMessage['boosts']);
        $this->assertSame($resource['mentions'], $broadcastMessage['mentions']);
        $this->assertSame(7000, config('campfire.broadcast_payload_limit'));
        $this->assertLessThanOrEqual(7000, strlen($encoded));
        $this->assertStringNotContainsString('secret123456', $encoded);
        $this->assertStringNotContainsString('password_digest', $encoded);
        $this->assertStringNotContainsString('authenticity_token', $encoded);
        $this->assertStringNotContainsString('csrf', strtolower($encoded));
    }

    public function test_human_json_mutations_emit_only_the_json_events(): void
    {
        [$author, $room] = $this->fixture();
        $this->auth($author);
        $this->fakeBroadcasts();

        $this->withHeader('Accept', 'application/json')->post('/rooms/'.$room->id.'/messages', [
            'message' => ['body' => '<p>Before</p>', 'client_message_id' => 'human-message'],
        ])->assertCreated()->assertJsonPath('client_message_id', 'human-message');
        $message = Message::firstOrFail();
        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => '<p>After</p>']])->assertOk()->assertJsonPath('body.plain_text', 'After');
        $this->post('/messages/'.$message->id.'/boosts', ['boost' => ['content' => 'ship']])->assertCreated()->assertJsonPath('message_id', $message->id);
        $boost = Boost::firstOrFail();
        $this->delete('/messages/'.$message->id.'/boosts/'.$boost->id)->assertNoContent();
        $this->delete('/rooms/'.$room->id.'/messages/'.$message->id)->assertNoContent();

        $this->assertJsonMutationEvents($room->id, $message->id, $boost->id, 'human-message');
    }

    public function test_an_edit_with_sixty_boosts_commits_and_broadcasts_a_bounded_explicitly_partial_resource(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Before</p>']);
        foreach (range(1, 60) as $number) {
            $booster = User::create(['name' => 'Overflow Booster '.$number, 'role' => 0, 'status' => 0]);
            Boost::create(['message_id' => $message->id, 'booster_id' => $booster->id, 'content' => 'boost-'.$number]);
        }
        $this->auth($author);
        Event::fake([TurboStreamBroadcast::class, MessageUpdated::class]);

        $this->withHeader('Accept', 'application/json')->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => '<p>After</p>']])->assertOk();

        Event::assertNotDispatched(TurboStreamBroadcast::class);
        Event::assertDispatched(MessageUpdated::class);
        $event = Event::dispatched(MessageUpdated::class)->first()[0];
        $broadcast = $event->broadcastWith()['message'];
        $full = (new MessageResource($message->fresh()))->resolve(new Request);

        $this->assertSame('After', $message->fresh()->plainText());
        $this->assertLessThanOrEqual(7000, strlen(json_encode(['message' => $broadcast], JSON_THROW_ON_ERROR)));
        $this->assertTrue($broadcast['truncation']['fetch_required']);
        $this->assertSame(60, $broadcast['truncation']['boosts']['total']);
        $this->assertLessThan(60, $broadcast['truncation']['boosts']['included']);
        $this->assertSame($broadcast['truncation']['boosts']['included'], count($broadcast['boosts']));
        $this->assertCount(60, $full['boosts']);
    }

    public function test_bot_mutations_emit_the_same_json_events_without_turbo(): void
    {
        [, $room] = $this->fixture();
        $bot = User::create(['name' => 'Broadcast Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'broadcast-key']);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);
        $path = '/rooms/'.$room->id.'/'.$bot->id.'-broadcast-key/messages';
        $this->fakeBroadcasts();

        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Bot before')->assertCreated();
        $message = Message::firstOrFail();
        $this->call('PUT', $path.'/'.$message->id, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Bot after')->assertOk();
        $this->call('POST', $path.'/'.$message->id.'/boosts', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'ship')->assertCreated();
        $boost = Boost::firstOrFail();
        $this->delete($path.'/'.$message->id.'/boosts/'.$boost->id)->assertNoContent();
        $this->delete($path.'/'.$message->id)->assertNoContent();

        $this->assertJsonMutationEvents($room->id, $message->id, $boost->id, (string) $message->client_message_id);
    }

    public function test_default_sidebar_changes_emit_only_a_small_json_signal(): void
    {
        [$author] = $this->fixture();
        $other = User::create(['name' => 'Sidebar Member', 'role' => 0, 'status' => 0]);
        Event::fake([SidebarChanged::class, TurboStreamBroadcast::class]);
        $this->auth($author);

        $this->post('/rooms/closeds', [
            'room' => ['name' => 'Signal Room'],
            'user_ids' => [$author->id, $other->id],
        ])->assertRedirect();

        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $author->id
            && $event->broadcastAs() === 'sidebar.changed'
            && $event->broadcastWith() === ['refresh' => true]);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $other->id);
        Event::assertNotDispatched(TurboStreamBroadcast::class);
        $this->assertContains(ShouldBroadcastNow::class, class_implements(SidebarChanged::class));

        $room = Room::where('name', 'Signal Room')->firstOrFail();
        Event::fake([SidebarChanged::class, TurboStreamBroadcast::class]);
        $this->delete('/rooms/closeds/'.$room->id)->assertRedirect();
        Event::assertDispatched(SidebarChanged::class);
        Event::assertNotDispatched(TurboStreamBroadcast::class);
    }

    public function test_controller_open_room_deletion_is_json_only_on_the_message_rollback_path(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Eligible Controller Member', 'role' => 0, 'status' => 0]);
        $bot = User::create(['name' => 'Ignored Controller Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'ignored-controller-bot']);
        $inactive = User::create(['name' => 'Inactive Controller Member', 'role' => 0, 'status' => 2]);
        foreach ([$member, $bot, $inactive] as $user) {
            Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
        }
        config(['campfire.json_message_stream' => false]);
        Event::fake([SidebarChanged::class, TurboStreamBroadcast::class]);
        $this->auth($owner);

        $this->delete('/rooms/opens/'.$room->id)->assertRedirect('/');

        Event::assertDispatchedTimes(SidebarChanged::class, 2);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $owner->id);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $member->id);
        Event::assertNotDispatched(SidebarChanged::class, fn (SidebarChanged $event) => in_array($event->userId, [$bot->id, $inactive->id], true));
        Event::assertNotDispatched(TurboStreamBroadcast::class);
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    private function fakeBroadcasts(): void
    {
        Event::fake([
            TurboStreamBroadcast::class,
            MessagePosted::class,
            MessageUpdated::class,
            MessageDeleted::class,
            BoostAdded::class,
            BoostRemoved::class,
        ]);
    }

    private function assertJsonMutationEvents(int $roomId, int $messageId, int $boostId, string $clientMessageId): void
    {
        Event::assertNotDispatched(TurboStreamBroadcast::class);
        Event::assertDispatched(MessagePosted::class, fn (MessagePosted $event) => $this->isRoomEvent($event, $roomId, 'message.posted'));
        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $this->isRoomEvent($event, $roomId, 'message.updated')
            && $event->broadcastWith()['message']['body']['plain_text'] === ($event->broadcastWith()['message']['creator']['role'] === 'bot' ? 'Bot after' : 'After'));
        Event::assertDispatched(BoostAdded::class, fn (BoostAdded $event) => $this->isRoomEvent($event, $roomId, 'boost.added')
            && $event->broadcastWith()['message_id'] === $messageId
            && $event->broadcastWith()['boost']['id'] === $boostId);
        Event::assertDispatched(BoostRemoved::class, fn (BoostRemoved $event) => $this->isRoomEvent($event, $roomId, 'boost.removed')
            && $event->broadcastWith()['boost']['id'] === $boostId);
        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event) => $this->isRoomEvent($event, $roomId, 'message.deleted')
            && $event->broadcastWith()['message'] === ['id' => $messageId, 'client_message_id' => $clientMessageId]);
        $this->assertDatabaseMissing('boosts', ['id' => $boostId]);
        $this->assertDatabaseMissing('messages', ['id' => $messageId]);
    }

    public function test_every_reduced_broadcast_representation_keeps_the_client_schema_floor(): void
    {
        [$author, $room] = $this->fixture();
        $body = '<p>'.str_repeat('schema-floor-', 800).'</p>';
        $message = app(MessageWriter::class)->create($room, $author, ['body' => $body]);

        foreach ([7000, 4000, 2500] as $budget) {
            config(['campfire.broadcast_payload_limit' => $budget]);
            $resource = (new MessageResource($message->fresh()))->forBroadcast();

            $this->assertSame(
                ['id', 'client_message_id', 'created_at', 'updated_at', 'body', 'creator', 'room', 'url', 'attachment', 'boosts', 'mentions'],
                array_values(array_intersect(array_keys($resource), ['id', 'client_message_id', 'created_at', 'updated_at', 'body', 'creator', 'room', 'url', 'attachment', 'boosts', 'mentions'])),
            );
            $this->assertSame(['plain_text', 'html', 'editable_html', 'truncated'], array_keys($resource['body']));
            $this->assertNull($resource['body']['editable_html']);
            $this->assertTrue($resource['body']['truncated']);
            $this->assertLessThanOrEqual($budget, strlen(json_encode(['message' => $resource], JSON_THROW_ON_ERROR)));
        }
    }

    private function isRoomEvent(object $event, int $roomId, string $name): bool
    {
        return $event->broadcastAs() === $name
            && $event->broadcastOn()[0]->name === 'private-rooms.'.$roomId;
    }
}
