<?php

namespace Tests\Feature;

use App\Livewire\AccountSettings;
use App\Livewire\ProfileSettings;
use App\Livewire\RoomForm;
use App\Livewire\RoomInvolvement;
use App\Livewire\RoomSettings;
use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

final class LivewirePropertyTamperTest extends TestCase
{
    public function test_room_form_rejects_client_tampering_with_direct_kind_without_mutation(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);

        $error = $this->capture(fn () => Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->set('name', 'Compromised')
            ->set('selected', [$owner->id])
            ->set('kind', 'directs'));

        $this->assertLockedProperty($error, 'kind');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler', 'type' => 'Rooms::Open']);
        $this->assertEqualsCanonicalizing([$owner->id, $member->id], $room->fresh()->users()->pluck('users.id')->all());
    }

    public function test_room_form_rejects_client_tampering_with_invalid_kind_without_mutation(): void
    {
        [$owner, $room] = $this->fixture();

        $error = $this->capture(fn () => Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->set('name', 'Compromised')
            ->set('kind', 'not-a-kind'));

        $this->assertLockedProperty($error, 'kind');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler', 'type' => 'Rooms::Open']);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_room_form_rejects_client_room_substitution_without_mutating_either_room(): void
    {
        [$owner, $room] = $this->fixture();
        $substitute = Room::create(['name' => 'Substitute', 'type' => 'Rooms::Closed', 'creator_id' => $owner->id]);
        Membership::create(['room_id' => $substitute->id, 'user_id' => $owner->id, 'involvement' => 'everything']);

        $error = $this->capture(fn () => Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->set('name', 'Compromised')
            ->set('room', $substitute));

        $this->assertLockedProperty($error, 'room');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler', 'type' => 'Rooms::Open']);
        $this->assertDatabaseHas('rooms', ['id' => $substitute->id, 'name' => 'Substitute', 'type' => 'Rooms::Closed']);
    }

    public function test_room_involvement_rejects_client_room_substitution_without_mutation(): void
    {
        [$owner, $room] = $this->fixture();
        $substitute = Room::create(['name' => 'Substitute', 'type' => 'Rooms::Closed', 'creator_id' => $owner->id]);
        Membership::create(['room_id' => $substitute->id, 'user_id' => $owner->id, 'involvement' => 'nothing']);

        $error = $this->capture(fn () => Livewire::actingAs($owner)->test(RoomInvolvement::class, ['roomId' => $room->id])
            ->set('involvement', 'everything')
            ->set('roomId', $substitute->id));

        $this->assertLockedProperty($error, 'roomId');
        $this->assertDatabaseHas('memberships', ['room_id' => $room->id, 'user_id' => $owner->id, 'involvement' => 'mentions']);
        $this->assertDatabaseHas('memberships', ['room_id' => $substitute->id, 'user_id' => $owner->id, 'involvement' => 'nothing']);
    }

    public function test_room_settings_rejects_client_substitution_to_an_unviewable_room(): void
    {
        [$owner, $room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        $secret = Room::create(['name' => 'Secret', 'type' => 'Rooms::Closed', 'creator_id' => $stranger->id]);
        Membership::create(['room_id' => $secret->id, 'user_id' => $stranger->id, 'involvement' => 'everything']);

        $error = $this->capture(fn () => Livewire::actingAs($owner)->test(RoomSettings::class, ['room' => $room])
            ->set('room', $secret));

        $this->assertLockedProperty($error, 'room');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler']);
        $this->assertDatabaseHas('rooms', ['id' => $secret->id, 'name' => 'Secret']);
    }

    public function test_room_form_save_policy_denies_server_side_direct_transition_without_mutation(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);
        $component = Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room]);
        $instance = $component->instance();
        $this->assertInstanceOf(RoomForm::class, $instance);
        $instance->kind = 'directs';
        $instance->name = 'Compromised';
        $instance->selected = [$owner->id];

        $error = $this->capture(fn () => $instance->save());

        $this->assertInstanceOf(AuthorizationException::class, $error);
        $this->assertSame(403, app(ExceptionHandler::class)->render(request(), $error)->getStatusCode());
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler', 'type' => 'Rooms::Open']);
        $this->assertEqualsCanonicalizing([$owner->id, $member->id], $room->fresh()->users()->pluck('users.id')->all());
    }

    public function test_room_form_save_policy_allows_server_side_open_to_closed_transition(): void
    {
        [$owner, $room] = $this->fixture();
        $component = Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room]);
        $instance = $component->instance();
        $this->assertInstanceOf(RoomForm::class, $instance);
        $instance->kind = 'closeds';
        $instance->name = 'Private room';

        $instance->save();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Private room', 'type' => 'Rooms::Closed']);
    }

    public function test_room_form_invalid_kind_at_server_boundary_remains_404_without_mutation(): void
    {
        [$owner, $room] = $this->fixture();
        $component = Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room]);
        $instance = $component->instance();
        $this->assertInstanceOf(RoomForm::class, $instance);
        $instance->kind = 'not-a-kind';
        $instance->name = 'Compromised';

        $error = $this->capture(fn () => $instance->save());

        $this->assertInstanceOf(HttpExceptionInterface::class, $error);
        $this->assertSame(404, $error->getStatusCode());
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Watercooler', 'type' => 'Rooms::Open']);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_non_administrator_cannot_persist_any_account_input_or_uploaded_logo(): void
    {
        [$administrator] = $this->fixture();
        Storage::fake('local');
        $existingBlob = $this->attachFixture('Account', 1, 'logo', 'existing-logo-key', 'existing logo');
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        $account = DB::table('accounts')->first();

        Livewire::actingAs($member)->test(AccountSettings::class)
            ->set('name', 'Compromised')
            ->set('restricted', true)
            ->set('logo', UploadedFile::fake()->image('replacement.png'))
            ->call('save')
            ->assertStatus(403);

        $freshAccount = DB::table('accounts')->first();
        $this->assertSame($account->name, $freshAccount->name);
        $this->assertSame($account->settings, $freshAccount->settings);
        $this->assertDatabaseCount('active_storage_attachments', 1);
        $this->assertDatabaseCount('active_storage_blobs', 1);
        $this->assertDatabaseHas('active_storage_attachments', ['record_type' => 'Account', 'record_id' => 1, 'name' => 'logo', 'blob_id' => $existingBlob->id]);
        Storage::disk('local')->assertExists(app(BlobStorage::class)->path($existingBlob));
        $this->assertSame(['blobs/existing-logo-key'], Storage::disk('local')->allFiles('blobs'));
        $this->assertSame(1, $administrator->fresh()->role);
    }

    public function test_profile_inputs_and_upload_can_only_mutate_the_authenticated_user(): void
    {
        [$user] = $this->fixture();
        Storage::fake('local');
        $victim = User::create([
            'name' => 'Victim',
            'email_address' => 'victim@example.test',
            'bio' => 'Original victim bio',
            'password_digest' => password_hash('victim-password', PASSWORD_BCRYPT),
            'role' => 0,
            'status' => 0,
        ]);
        $victimBlob = $this->attachFixture('User', $victim->id, 'avatar', 'victim-avatar-key', 'victim avatar');
        $victimPassword = $victim->password_digest;

        Livewire::actingAs($user)->test(ProfileSettings::class)
            ->set('name', 'Updated User')
            ->set('email', 'updated@example.test')
            ->set('bio', 'Updated bio')
            ->set('password', 'updated-password')
            ->set('avatar', UploadedFile::fake()->image('new-avatar.png'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated User', 'email_address' => 'updated@example.test', 'bio' => 'Updated bio']);
        $this->assertTrue(password_verify('updated-password', $user->fresh()->password_digest));
        $this->assertDatabaseHas('active_storage_attachments', ['record_type' => 'User', 'record_id' => $user->id, 'name' => 'avatar']);
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Victim', 'email_address' => 'victim@example.test', 'bio' => 'Original victim bio', 'password_digest' => $victimPassword]);
        $this->assertDatabaseHas('active_storage_attachments', ['record_type' => 'User', 'record_id' => $victim->id, 'name' => 'avatar', 'blob_id' => $victimBlob->id]);
        Storage::disk('local')->assertExists(app(BlobStorage::class)->path($victimBlob));
    }

    public function test_intended_room_inputs_remain_validation_and_membership_scoped(): void
    {
        [$owner, $room] = $this->fixture();
        $active = User::create(['name' => 'Active', 'role' => 0, 'status' => 0]);
        $inactive = User::create(['name' => 'Inactive', 'role' => 0, 'status' => 1]);
        foreach ([$active, $inactive] as $user) {
            Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
        }

        Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->call('changeKind', 'closeds')
            ->set('name', '')
            ->set('selected', ['not-an-integer'])
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'selected.0' => 'integer'])
            ->set('name', 'Scoped room')
            ->set('selected', [$owner->id, $active->id, $inactive->id, 999999])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Scoped room', 'type' => 'Rooms::Closed']);
        $this->assertEqualsCanonicalizing([$owner->id, $active->id], $room->fresh()->users()->pluck('users.id')->all());

        Livewire::actingAs($owner)->test(RoomInvolvement::class, ['roomId' => $room->id])
            ->set('involvement', 'invalid')
            ->call('save')
            ->assertHasErrors(['involvement' => 'in'])
            ->set('involvement', 'everything')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('memberships', ['room_id' => $room->id, 'user_id' => $owner->id, 'involvement' => 'everything']);
    }

    private function capture(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $error) {
            return $error;
        }

        return null;
    }

    private function assertLockedProperty(?Throwable $error, string $property): void
    {
        $this->assertInstanceOf(CannotUpdateLockedPropertyException::class, $error);
        $this->assertSame($property, $error->property);
    }

    private function attachFixture(string $recordType, int $recordId, string $name, string $key, string $contents): Blob
    {
        $blob = Blob::create([
            'key' => $key,
            'filename' => $key.'.png',
            'content_type' => 'image/png',
            'metadata' => '{}',
            'service_name' => 'campfire',
            'byte_size' => strlen($contents),
            'checksum' => base64_encode(md5($contents, true)),
            'created_at' => now(),
        ]);
        Attachment::create([
            'record_type' => $recordType,
            'record_id' => $recordId,
            'name' => $name,
            'blob_id' => $blob->id,
            'created_at' => now(),
        ]);
        Storage::disk('local')->put(app(BlobStorage::class)->path($blob), $contents);

        return $blob;
    }
}
