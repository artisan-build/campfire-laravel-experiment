<?php

namespace Tests\Feature;

use App\Events\SidebarChanged;
use App\Livewire\ProfileSettings;
use App\Livewire\RoomForm;
use App\Livewire\Sidebar;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\SidebarEvents;
use Illuminate\Support\Facades\Event;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

final class LivewireSidebarTest extends TestCase
{
    public function test_it_renders_only_the_signed_in_users_visible_rooms_in_sidebar_order(): void
    {
        [$viewer, $initial] = $this->fixture();
        $initial->update(['name' => 'Zulu Shared']);
        $alpha = $this->room($viewer, 'alpha shared');
        $hidden = $this->room($viewer, 'Hidden Shared', 'invisible');

        $olderPeer = User::create(['name' => 'Older Ping', 'role' => 0, 'status' => 0]);
        $older = $this->direct($viewer, $olderPeer, now()->subHour());
        $newerPeer = User::create(['name' => 'Newer Ping', 'role' => 0, 'status' => 0]);
        $newer = $this->direct($viewer, $newerPeer, now());

        $bot = User::create(['name' => 'Sidebar Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'sidebar-bot-token']);
        $botRoom = Room::create(['name' => 'Bot Only Room', 'type' => 'Rooms::Open', 'creator_id' => $bot->id]);
        Membership::create(['room_id' => $botRoom->id, 'user_id' => $bot->id, 'involvement' => 'everything']);

        $component = Livewire::actingAs($viewer)->test(Sidebar::class)
            ->assertSeeHtml('data-testid="sidebar-rooms"')
            ->assertSeeHtml('href="/rooms/'.$older->id.'"')
            ->assertSeeHtml('href="/rooms/'.$newer->id.'"')
            ->assertSeeHtml('href="/rooms/'.$alpha->id.'"')
            ->assertSeeHtml('href="/rooms/'.$initial->id.'"')
            ->assertDontSeeHtml('href="/rooms/'.$hidden->id.'"')
            ->assertDontSee('Bot Only Room');

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'Older Ping'), strpos($html, 'Newer Ping'));
        $this->assertLessThan(strpos($html, 'Zulu Shared'), strpos($html, 'alpha shared'));
        Livewire::actingAs($bot)->test(Sidebar::class)->assertStatus(403);
    }

    public function test_listener_identity_is_server_derived_and_all_three_events_rerender_authoritative_state(): void
    {
        [$viewer] = $this->fixture();
        $other = User::create(['name' => 'Other Listener', 'role' => 0, 'status' => 0]);
        $component = Livewire::actingAs($viewer)->test(Sidebar::class);
        $sidebar = $component->instance();
        $this->assertInstanceOf(Sidebar::class, $sidebar);

        $this->assertSame([
            'echo-private:users.'.$viewer->id.'.rooms,.sidebar.changed' => '$refresh',
            'echo-private:users.'.$viewer->id.'.unreads,.unread' => '$refresh',
            'echo-private:users.'.$viewer->id.'.reads,.read' => '$refresh',
        ], $this->listeners($sidebar));

        $error = $this->capture(fn () => $component->set('userId', $other->id));
        $this->assertInstanceOf(PublicPropertyNotFoundException::class, $error);
        $this->assertSame($viewer->id, auth()->id());
        $freshSidebar = Livewire::actingAs($viewer)->test(Sidebar::class)->instance();
        $this->assertInstanceOf(Sidebar::class, $freshSidebar);
        $this->assertSame([
            'echo-private:users.'.$viewer->id.'.rooms,.sidebar.changed',
            'echo-private:users.'.$viewer->id.'.unreads,.unread',
            'echo-private:users.'.$viewer->id.'.reads,.read',
        ], array_keys($this->listeners($freshSidebar)));

        $added = $this->room($viewer, 'Listener Added Room');
        $component = Livewire::actingAs($viewer)->test(Sidebar::class)
            ->dispatch('echo-private:users.'.$viewer->id.'.rooms,.sidebar.changed', ['refresh' => true])
            ->assertSee('Listener Added Room');

        $membership = Membership::where(['room_id' => $added->id, 'user_id' => $viewer->id])->firstOrFail();
        $membership->update(['unread_at' => now()]);
        $component->dispatch('echo-private:users.'.$viewer->id.'.unreads,.unread', ['roomId' => $added->id])
            ->assertSeeHtml('data-room-id="'.$added->id.'"')
            ->assertSeeHtml('unread');

        $membership->update(['unread_at' => null]);
        $component->dispatch('echo-private:users.'.$viewer->id.'.reads,.read', ['room_id' => $added->id]);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*unread[^"]*" href="\/rooms\/'.$added->id.'"/', $component->html());
    }

    public function test_sidebar_refresh_is_json_only_even_on_the_message_rollback_path(): void
    {
        [$viewer] = $this->fixture();
        $bot = User::create(['name' => 'Ignored Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'ignored-bot-token']);
        Event::fake([SidebarChanged::class]);

        app(SidebarEvents::class)->refresh([$viewer->id, $viewer->id, $bot->id]);

        Event::assertDispatchedTimes(SidebarChanged::class, 1);
        Event::assertDispatched(SidebarChanged::class, function (SidebarChanged $event) use ($viewer): bool {
            $payload = $event->broadcastWith();

            return $event->userId === $viewer->id
                && $event->broadcastOn()[0]->name === 'private-users.'.$viewer->id.'.rooms'
                && $event->broadcastAs() === 'sidebar.changed'
                && $payload === ['refresh' => true]
                && ! str_contains(json_encode($payload, JSON_THROW_ON_ERROR), '<');
        });
    }

    public function test_livewire_open_room_deletion_is_json_only_on_the_message_rollback_path(): void
    {
        [$owner, $room] = $this->fixture();
        $member = User::create(['name' => 'Eligible Member', 'role' => 0, 'status' => 0]);
        $bot = User::create(['name' => 'Ignored Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'ignored-delete-bot']);
        $inactive = User::create(['name' => 'Inactive Member', 'role' => 0, 'status' => 2]);
        foreach ([$member, $bot, $inactive] as $user) {
            Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
        }
        Event::fake([SidebarChanged::class]);

        Livewire::actingAs($owner)->test(RoomForm::class, ['kind' => 'opens', 'room' => $room])
            ->call('delete')
            ->assertRedirect('/');

        Event::assertDispatchedTimes(SidebarChanged::class, 2);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $owner->id);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $member->id);
        Event::assertNotDispatched(SidebarChanged::class, fn (SidebarChanged $event) => in_array($event->userId, [$bot->id, $inactive->id], true));
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_a_profile_name_change_refreshes_direct_participants_but_not_unrelated_users(): void
    {
        [$viewer] = $this->fixture();
        $peer = User::create(['name' => 'Direct Peer', 'role' => 0, 'status' => 0]);
        $unrelated = User::create(['name' => 'Unrelated Person', 'role' => 0, 'status' => 0]);
        $this->direct($viewer, $peer, now());
        Event::fake([SidebarChanged::class]);

        Livewire::actingAs($viewer)->test(ProfileSettings::class)
            ->set('name', 'Renamed Viewer')
            ->call('save')
            ->assertHasNoErrors();

        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $viewer->id);
        Event::assertDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $peer->id);
        Event::assertNotDispatched(SidebarChanged::class, fn (SidebarChanged $event) => $event->userId === $unrelated->id);
    }

    private function room(User $viewer, string $name, string $involvement = 'mentions'): Room
    {
        $room = Room::create(['name' => $name, 'type' => 'Rooms::Open', 'creator_id' => $viewer->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $viewer->id, 'involvement' => $involvement]);

        return $room;
    }

    private function direct(User $viewer, User $peer, $updatedAt): Room
    {
        $room = Room::create(['name' => null, 'type' => 'Rooms::Direct', 'creator_id' => $viewer->id]);
        foreach ([$viewer, $peer] as $user) {
            Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'everything']);
        }
        $room->update(['updated_at' => $updatedAt]);

        return $room;
    }

    /** @return array<string, string> */
    private function listeners(Sidebar $sidebar): array
    {
        return (new ReflectionMethod($sidebar, 'getListeners'))->invoke($sidebar);
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
}
