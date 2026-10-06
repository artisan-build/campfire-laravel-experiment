<?php

namespace Tests\Feature;

use App\Livewire\AccountSettings;
use App\Livewire\ProfileSettings;
use App\Livewire\RoomForm;
use App\Livewire\RoomInvolvement;
use App\Livewire\RoomSettings;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireSettingsTest extends TestCase
{
    public function test_room_form_authorizes_validates_creates_and_converts_a_room(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);

        Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'closeds'])
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->set('name', 'Launch Room')
            ->set('selected', [$owner->id, $member->id])
            ->call('save')
            ->assertRedirect();

        $created = $owner->rooms()->where('name', 'Launch Room')->firstOrFail();
        $this->assertSame('Rooms::Closed', $created->type);
        $this->assertEqualsCanonicalizing([$owner->id, $member->id], $created->users()->pluck('users.id')->all());

        Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->call('changeKind', 'closeds')
            ->set('name', 'Renamed room')
            ->set('selected', [$owner->id])
            ->call('save');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Renamed room', 'type' => 'Rooms::Closed']);

        Livewire::actingAs($member)->test(RoomForm::class, ['kind' => 'closeds', 'room' => $room->fresh()])->assertStatus(403);
    }

    public function test_room_form_delete_without_a_room_aborts_without_mutating_data(): void
    {
        [$owner, $room] = $this->fixture();

        Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens'])
            ->call('delete')
            ->assertStatus(404);

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseCount('rooms', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_room_settings_component_uses_room_view_policy(): void
    {
        [$owner, $room] = $this->fixture();
        Livewire::actingAs($owner)->test(RoomSettings::class, ['room' => $room])->assertSeeHtml('data-testid="room-settings"');

        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        Livewire::actingAs($stranger)->test(RoomSettings::class, ['room' => $room])->assertStatus(403);
    }

    public function test_room_involvement_authorizes_validates_and_persists(): void
    {
        [$owner, $room] = $this->fixture();
        Livewire::actingAs($owner)->test(RoomInvolvement::class, ['roomId' => $room->id])
            ->set('involvement', 'invalid')
            ->call('save')
            ->assertHasErrors(['involvement' => 'in'])
            ->set('involvement', 'everything')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('memberships', ['room_id' => $room->id, 'user_id' => $owner->id, 'involvement' => 'everything']);

        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        Livewire::actingAs($stranger)->test(RoomInvolvement::class, ['roomId' => $room->id])->assertStatus(404);
    }

    public function test_profile_component_requires_authentication_validates_and_persists_current_user(): void
    {
        [$owner] = $this->fixture();
        Livewire::actingAs($owner)->test(ProfileSettings::class)
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email' => 'email'])
            ->set('email', 'new@example.test')
            ->set('name', 'Updated Person')
            ->set('bio', 'Still chatting')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => 'Updated Person', 'email_address' => 'new@example.test', 'bio' => 'Still chatting']);

        auth()->forgetGuards();
        Livewire::test(ProfileSettings::class)->assertStatus(403);
    }

    public function test_account_component_authorizes_validates_and_persists(): void
    {
        [$administrator] = $this->fixture();
        Livewire::actingAs($administrator)->test(AccountSettings::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->set('name', 'Campfire HQ')
            ->set('restricted', true)
            ->call('save')
            ->assertHasNoErrors();
        $account = DB::table('accounts')->first();
        $this->assertSame('Campfire HQ', $account->name);
        $this->assertTrue(json_decode($account->settings, true)['restrict_room_creation_to_administrators']);

        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Livewire::actingAs($member)->test(AccountSettings::class)->set('name', 'Stolen')->call('save')->assertStatus(403);
    }
}
