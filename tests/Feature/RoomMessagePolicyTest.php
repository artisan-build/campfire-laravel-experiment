<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use App\Support\MessageWriter;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class RoomMessagePolicyTest extends TestCase
{
    public function test_room_policy_is_shared_by_gate_http_and_blade(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $room));
        $this->assertFalse(Gate::forUser($member)->allows('update', $room));
        $this->auth($member);
        $this->get('/rooms/'.$room->id.'/settings')->assertOk()->assertDontSee('/rooms/opens/'.$room->id.'/edit', false);
        $this->patch('/rooms/opens/'.$room->id, ['room' => ['name' => 'Stolen']])->assertForbidden();
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler']);
    }

    public function test_message_policy_allows_creator_and_administrator_but_denies_another_member(): void
    {
        [$administrator, $room] = $this->fixture();
        $creator = User::create(['name' => 'Creator', 'role' => 0, 'status' => 0]);
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        foreach ([$creator, $member] as $user) {
            Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
        }
        $message = app(MessageWriter::class)->create($room, $creator, ['body' => 'Owned']);

        $this->assertTrue(Gate::forUser($creator)->allows('update', $message));
        $this->assertTrue(Gate::forUser($administrator)->allows('delete', $message));
        $this->assertFalse(Gate::forUser($member)->allows('update', $message));

        $this->auth($member);
        $this->patch('/rooms/'.$room->id.'/messages/'.$message->id, ['message' => ['body' => 'Stolen']])->assertForbidden();
        $this->assertSame('Owned', $message->fresh()->plainText());
    }
}
