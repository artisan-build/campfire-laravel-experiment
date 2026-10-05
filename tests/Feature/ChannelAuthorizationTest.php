<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use Tests\TestCase;

/**
 * /broadcasting/auth is the only gate in front of Reverb: a subscriber that cannot get a signature
 * here never joins the channel. Every rule in routes/channels.php is exercised from both sides.
 */
final class ChannelAuthorizationTest extends TestCase
{
    private function authorize(string $channel, string $socket = '1234.5678')
    {
        return $this->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => $socket]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The suite boots on the null broadcaster so no test posts to a real Reverb. Channel
        // callbacks register against whichever driver was current when routes/channels.php ran, so
        // switching the driver here means re-registering them.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        require base_path('routes/channels.php');
    }

    public function test_a_member_is_signed_in_to_a_room_and_a_non_member_is_refused(): void
    {
        [$member, $room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);

        $this->auth($member);
        $this->authorize('private-rooms.'.$room->id)->assertOk()->assertJsonStructure(['auth']);
        $this->authorize('private-rooms.'.$room->id.'.typing')->assertOk();
        $this->authorize('presence-rooms.'.$room->id.'.presence')->assertOk()->assertJsonStructure(['auth', 'channel_data']);

        $this->flushSession();
        $this->auth($stranger);
        $this->authorize('private-rooms.'.$room->id)->assertForbidden();
        $this->authorize('private-rooms.'.$room->id.'.typing')->assertForbidden();
        $this->authorize('presence-rooms.'.$room->id.'.presence')->assertForbidden();
    }

    public function test_a_revoked_member_loses_a_room_it_could_reach_a_moment_ago(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        $membership = Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);

        $this->auth($member);
        $this->authorize('private-rooms.'.$room->id)->assertOk();

        $membership->delete();
        $this->authorize('private-rooms.'.$room->id)->assertForbidden();
    }

    public function test_one_user_cannot_subscribe_to_another_users_channels(): void
    {
        [$owner, $room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);

        $this->auth($owner);
        $this->authorize('private-users.'.$owner->id.'.unreads')->assertOk();
        $this->authorize('private-users.'.$owner->id.'.reads')->assertOk();
        $this->authorize('private-users.'.$owner->id.'.rooms')->assertOk();

        $this->authorize('private-users.'.$other->id.'.unreads')->assertForbidden();
        $this->authorize('private-users.'.$other->id.'.reads')->assertForbidden();
        $this->authorize('private-users.'.$other->id.'.rooms')->assertForbidden();
    }

    public function test_a_channel_outside_the_allowlist_is_refused(): void
    {
        [$owner, $room] = $this->fixture();
        $this->auth($owner);

        foreach ([
            'private-rooms.'.$room->id.'.secrets',
            'private-accounts.1',
            'private-users',
            'public-rooms.'.$room->id,
            'private-rooms.'.($room->id + 99),
        ] as $channel) {
            $this->authorize($channel)->assertForbidden();
        }
    }

    public function test_a_presence_channel_claimed_as_a_private_one_still_needs_membership(): void
    {
        [$owner, $room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);

        // Laravel matches a channel rule after stripping the private-/presence- prefix, so the same
        // rule guards both spellings. Nothing is ever broadcast on the presence channel, but the
        // membership check has to hold whichever prefix the client picks.
        $this->auth($owner);
        $this->authorize('private-rooms.'.$room->id.'.presence')->assertOk();

        $this->flushSession();
        $this->auth($stranger);
        $this->authorize('private-rooms.'.$room->id.'.presence')->assertForbidden();
    }

    public function test_a_deactivated_user_and_a_bot_are_refused_the_account_wide_room_list(): void
    {
        [$owner, $room] = $this->fixture();
        $this->auth($owner);
        $this->authorize('private-rooms')->assertOk();

        $bot = User::create(['name' => 'Bender', 'bot_token' => 'BenderBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);
        $this->flushSession();
        $this->auth($bot);
        // The session middleware refuses a bot before it ever reaches a channel rule.
        $this->authorize('private-rooms')->assertForbidden();
        $this->authorize('private-rooms.'.$room->id)->assertForbidden();
    }

    public function test_an_unauthenticated_subscriber_is_sent_to_the_login_page_not_signed_in(): void
    {
        [$owner, $room] = $this->fixture();

        $this->authorize('private-rooms.'.$room->id)->assertRedirect('/session/new');
    }

    public function test_a_private_room_is_not_reachable_by_naming_it(): void
    {
        [$owner, $room] = $this->fixture();
        $closed = Room::create(['name' => 'Secret', 'type' => 'Rooms::Closed', 'creator_id' => $owner->id]);
        Membership::create(['room_id' => $closed->id, 'user_id' => $owner->id, 'involvement' => 'everything']);

        $outsider = User::create(['name' => 'Outsider', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $outsider->id, 'involvement' => 'mentions']);

        $this->auth($outsider);
        $this->authorize('private-rooms.'.$room->id)->assertOk();
        $this->authorize('private-rooms.'.$closed->id)->assertForbidden();
    }
}
