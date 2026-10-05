<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\RailsCrypto;
use Tests\TestCase;

final class JsonMessageStreamTest extends TestCase
{
    public function test_room_history_is_server_rendered_beside_the_reusable_json_template(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, [
            'body' => '<p>Initial history from the server</p>',
            'client_message_id' => 'server-history-row',
        ]);
        $this->auth($author);

        $response = $this->get('/rooms/'.$room->id)->assertOk()
            ->assertSee('data-testid="room-message-stream"', false)
            ->assertSee('data-testid="room-message-template"', false)
            ->assertSee('data-testid="room-message-history"', false)
            ->assertSee('data-testid="room-json-composer"', false)
            ->assertSee('id="message_server-history-row"', false)
            ->assertSee('data-message-id="'.$message->id.'"', false)
            ->assertSee('Initial history from the server');

        $html = $response->getContent();
        $this->assertStringNotContainsString('turbo-echo-stream-source', $html);
        $this->assertStringNotContainsString('text/vnd.turbo-stream', $html);
        $this->assertStringNotContainsString('authenticity_token" value=', $html);
    }

    public function test_removal_proof_the_active_bootstrap_wires_all_json_events_through_echo(): void
    {
        $this->fixture();
        $head = $this->get('/session/new')->assertOk()->getContent();
        $source = file_get_contents(public_path('assets/campfire/message_stream.js'));

        $this->assertStringContainsString('"campfire/message_stream"', file_get_contents(public_path('assets/campfire/application_json.js')));
        $this->assertStringContainsString('/assets/campfire/message_stream.js', $head);
        $this->assertStringContainsString('Alpine.data("messageStream"', $source);
        $this->assertStringContainsString('echo.private(`rooms.${options.roomId}`)', $source);
        foreach (['message.posted', 'message.updated', 'message.deleted', 'boost.added', 'boost.removed'] as $event) {
            $this->assertStringContainsString(".listen(\".{$event}\"", $source);
        }
        $this->assertStringContainsString('this.messagesByClientId.get(String(message.client_message_id))', $source);
        $this->assertStringContainsString('if (fetchRequired(message)', $source);
        $this->assertStringContainsString('echo.private(`users.${options.userId}.rooms`)', $source);
        $this->assertStringContainsString('.listen(".sidebar.changed"', $source);
    }

    public function test_default_sidebar_snapshot_is_static_html_and_fallback_keeps_turbo_sources(): void
    {
        [$author] = $this->fixture();
        $this->auth($author);

        $this->get('/users/me/sidebar')->assertOk()
            ->assertSee('id="user_sidebar"', false)
            ->assertDontSee('turbo-echo-stream-source', false);

        config(['campfire.json_message_stream' => false]);
        $this->get('/users/me/sidebar')->assertOk()
            ->assertSee('<turbo-frame id="user_sidebar">', false)
            ->assertSee('turbo-echo-stream-source', false);
    }

    public function test_message_history_json_is_ordered_and_uses_the_complete_http_resource(): void
    {
        [$author, $room] = $this->fixture();
        $first = app(MessageWriter::class)->create($room, $author, ['body' => '<p>First</p>']);
        $second = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Second</p>']);
        $this->auth($author);

        $this->withHeader('Accept', 'application/json')->get('/rooms/'.$room->id.'/messages')->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $first->id)
            ->assertJsonPath('1.id', $second->id)
            ->assertJsonStructure([
                '*' => ['id', 'client_message_id', 'created_at', 'updated_at', 'body' => ['plain_text', 'html', 'editable_html', 'truncated'], 'creator', 'room', 'url', 'attachment', 'boosts', 'mentions'],
            ]);
    }

    public function test_latest_snapshot_is_authoritative_after_a_delete_and_a_gap_larger_than_forty(): void
    {
        [$author, $room] = $this->fixture();
        $deleted = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Deleted while offline</p>']);
        app(MessageWriter::class)->destroy($deleted);
        $ids = [];
        foreach (range(1, 45) as $number) {
            $ids[] = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Gap '.$number.'</p>'])->id;
        }
        $this->auth($author);

        $response = $this->withHeader('Accept', 'application/json')->get('/rooms/'.$room->id.'/messages')->assertOk()->assertJsonCount(40);

        $this->assertSame(array_slice($ids, -40), $response->collect()->pluck('id')->all());
        $this->assertNotContains($deleted->id, $response->collect()->pluck('id')->all());
    }

    public function test_editable_resource_preserves_a_real_mention_through_an_edit(): void
    {
        [$author, $room] = $this->fixture();
        $mentioned = User::create(['name' => 'Mention Target', 'role' => 0, 'status' => 0]);
        $attachment = '<action-text-attachment sgid="'.app(RailsCrypto::class)->sgid($mentioned->id).'" content-type="application/vnd.campfire.mention"></action-text-attachment>';
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Hello '.$attachment.'</p>']);
        $this->auth($author);

        $resource = $this->withHeader('Accept', 'application/json')->get('/rooms/'.$room->id.'/messages/'.$message->id)
            ->assertOk()
            ->assertJsonPath('mentions.0.id', $mentioned->id);
        $editable = $resource->json('body.editable_html');
        $this->assertIsString($editable);
        $this->assertStringContainsString('action-text-attachment', $editable);

        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => $editable.'<p>Edited</p>']])
            ->assertOk()
            ->assertJsonPath('mentions.0.id', $mentioned->id)
            ->assertJsonPath('mentions.0.name', 'Mention Target');
        $this->assertStringContainsString('action-text-attachment', (string) $message->fresh()->richText?->body);
    }

    public function test_client_source_structurally_owns_convergence_draft_safety_editing_and_boost_ordering(): void
    {
        $source = file_get_contents(public_path('assets/campfire/message_stream.js'));

        $this->assertStringContainsString("if (connected) {\n      this.recover()", $source);
        $this->assertStringContainsString('await this.replaceCurrentWindow(await this.authoritativeSnapshot())', $source);
        $this->assertStringContainsString('while (startedAt !== this.mutationVersion)', $source);
        $this->assertStringContainsString('this.$refs.messages.replaceChildren(...optimistic)', $source);
        $this->assertLessThan(strpos($source, 'this.files = []'), strpos($source, 'await this.ensureLatest()'));
        $this->assertStringContainsString('message.body.editable_html', $source);
        $this->assertStringContainsString('event.key === "Escape"', $source);
        $this->assertStringContainsString('event.ctrlKey || event.metaKey', $source);
        $this->assertStringContainsString('restoreFocus?.focus()', $source);
        $this->assertStringContainsString("this.addBoost(message.dataset.messageId, payload.boost)\n      pending.remove()", $source);
        $this->assertStringContainsString('mentionIds.includes(Number(options.userId))', $source);
        $this->assertStringContainsString('highlightElement(block)', $source);
    }

    public function test_json_mutations_return_explicit_success_and_validation_statuses(): void
    {
        [$author, $room] = $this->fixture();
        $this->auth($author);

        $this->withHeader('Accept', 'application/json')->post('/rooms/'.$room->id.'/messages', ['message' => []])->assertUnprocessable()->assertJsonValidationErrors('message');

        $created = $this->post('/rooms/'.$room->id.'/messages', [
            'message' => ['body' => '<p>Status contract</p>', 'client_message_id' => 'status-contract'],
        ])->assertCreated()->assertJsonPath('body.plain_text', 'Status contract');

        $messageId = $created->json('id');
        $this->patch('/rooms/'.$room->id.'/messages/'.$messageId, ['message' => ['body' => '<p>Updated contract</p>']])
            ->assertOk()->assertJsonPath('body.plain_text', 'Updated contract');
        $this->post('/messages/'.$messageId.'/boosts', ['boost' => ['content' => 'this-content-is-too-long']])
            ->assertUnprocessable()->assertJsonValidationErrors('boost.content');
        $boost = $this->post('/messages/'.$messageId.'/boosts', ['boost' => ['content' => 'ship']])
            ->assertCreated()->assertJsonPath('message_id', $messageId);
        $this->delete('/messages/'.$messageId.'/boosts/'.$boost->json('boost.id'))->assertNoContent();
        $this->delete('/rooms/'.$room->id.'/messages/'.$messageId)->assertNoContent();
    }

    public function test_initial_history_renders_image_and_video_poster_variants(): void
    {
        [$author, $room] = $this->fixture();
        $image = app(MessageWriter::class)->create($room, $author, ['body' => '']);
        $video = app(MessageWriter::class)->create($room, $author, ['body' => '']);
        $this->attach($image, 'stream-image-key', 'diagram.png', 'image/png');
        $this->attach($video, 'stream-video-key', 'walkthrough.mp4', 'video/mp4');
        $this->auth($author);

        $this->get('/rooms/'.$room->id)->assertOk()
            ->assertSee('alt="diagram.png"', false)
            ->assertSee('data-lightbox-url="', false)
            ->assertSee('<video', false)
            ->assertSee('poster="', false);
    }

    public function test_a_non_member_cannot_use_the_json_message_endpoints(): void
    {
        [$author, $room] = $this->fixture();
        $message = app(MessageWriter::class)->create($room, $author, ['body' => '<p>Private</p>']);
        $stranger = User::create(['name' => 'Outside User', 'role' => 0, 'status' => 0]);
        $this->auth($stranger);

        $this->withHeader('Accept', 'application/json')->get('/rooms/'.$room->id.'/messages')->assertNotFound();
        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => 'No']])->assertNotFound();
        $this->delete('/rooms/'.$room->id.'/messages/'.$message->id)->assertNotFound();
        $this->post('/messages/'.$message->id.'/boosts', ['boost' => ['content' => 'No']])->assertNotFound();
    }

    private function attach(Message $message, string $key, string $filename, string $contentType): void
    {
        $blob = Blob::create([
            'key' => $key,
            'filename' => $filename,
            'content_type' => $contentType,
            'metadata' => '{}',
            'service_name' => 'campfire',
            'byte_size' => 128,
            'created_at' => now(),
        ]);
        Attachment::create([
            'name' => 'attachment',
            'record_type' => 'Message',
            'record_id' => $message->id,
            'blob_id' => $blob->id,
            'created_at' => now(),
        ]);
    }
}
