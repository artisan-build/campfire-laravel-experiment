<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageWriter;
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
                '*' => ['id', 'client_message_id', 'created_at', 'updated_at', 'body' => ['plain_text', 'html', 'truncated'], 'creator', 'room', 'url', 'attachment', 'boosts', 'mentions'],
            ]);
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
            ->assertSee('data-stream-action="lightbox"', false)
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
