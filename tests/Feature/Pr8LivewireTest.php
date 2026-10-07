<?php

namespace Tests\Feature;

use App\Jobs\DeliverPush;
use App\Livewire\BotForm;
use App\Livewire\BotList;
use App\Livewire\LogoutButton;
use App\Livewire\PushSubscriptions;
use App\Livewire\SearchMessages;
use App\Livewire\SessionTransfer;
use App\Livewire\SignIn;
use App\Livewire\SignUp;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\SignedIdentifiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

final class Pr8LivewireTest extends TestCase
{
    public function test_search_results_and_history_stay_within_the_authenticated_users_scope(): void
    {
        [$user, $room] = $this->fixture();
        $visible = app(MessageWriter::class)->create($room, $user, ['body' => '<p>Boundary needle visible</p>']);
        $other = User::create(['name' => 'Other', 'role' => 0, 'status' => 0]);
        $secret = Room::create(['name' => 'Secret', 'type' => 'Rooms::Closed', 'creator_id' => $other->id]);
        Membership::create(['room_id' => $secret->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $hidden = app(MessageWriter::class)->create($secret, $other, ['body' => '<p>Boundary needle hidden</p>']);
        DB::table('searches')->insert(['user_id' => $other->id, 'query' => 'other-history', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::actingAs($user)->test(SearchMessages::class)
            ->set('query', 'boundary needle')
            ->call('search')
            ->assertSeeHtml('data-controller="search-results"')
            ->assertSeeHtml('data-search-results-target="messages"')
            ->assertSeeHtml('data-search-results-me-class="message--me"')
            ->assertSeeHtml('data-search-results-threaded-class="message--threaded"')
            ->assertSeeHtml('data-search-results-mentioned-class="message--mentioned"')
            ->assertSeeHtml('data-search-results-formatted-class="message--formatted"')
            ->assertSee('Boundary needle visible')
            ->assertDontSee('Boundary needle hidden')
            ->assertDontSee('other-history')
            ->call('clearHistory');

        $this->assertDatabaseMissing('searches', ['user_id' => $user->id]);
        $this->assertDatabaseHas('searches', ['user_id' => $other->id, 'query' => 'other-history']);
        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_bot_lifecycle_validates_uploads_webhooks_and_reauthorizes_each_identity(): void
    {
        [$administrator, $room] = $this->fixture();
        Storage::fake('local');

        Livewire::actingAs($administrator)->test(BotForm::class)
            ->set('name', 'Relay Bot')
            ->set('webhookUrl', 'not-a-webhook')
            ->call('save')
            ->assertHasErrors(['webhookUrl'])
            ->set('webhookUrl', 'https://93.184.216.34/hook')
            ->set('avatar', UploadedFile::fake()->image('relay.png'))
            ->call('save')
            ->assertRedirect(route('bots.index'));

        $bot = User::where('name', 'Relay Bot')->firstOrFail();
        $this->assertSame(2, $bot->role);
        $this->assertDatabaseHas('memberships', ['user_id' => $bot->id, 'room_id' => $room->id]);
        $this->assertDatabaseHas('webhooks', ['user_id' => $bot->id, 'url' => 'https://93.184.216.34/hook']);
        $this->assertDatabaseHas('active_storage_attachments', ['record_type' => 'User', 'record_id' => $bot->id, 'name' => 'avatar']);

        $oldKey = $bot->bot_token;
        Livewire::actingAs($administrator)->test(BotForm::class, ['botId' => $bot->id])->call('rotateKey');
        $this->assertNotSame($oldKey, $bot->fresh()->bot_token);

        Livewire::actingAs($administrator)->test(BotForm::class, ['botId' => $bot->id])->call('delete')->assertRedirect(route('bots.index'));
        $this->assertSame(1, $bot->fresh()->status);
    }

    public function test_bot_public_identity_is_locked_and_non_bot_or_inactive_selectors_are_rejected(): void
    {
        [$administrator] = $this->fixture();
        $first = User::create(['name' => 'First Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'first-token']);
        $second = User::create(['name' => 'Second Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'second-token']);
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        $inactive = User::create(['name' => 'Inactive Bot', 'role' => 2, 'status' => 1, 'bot_token' => 'inactive-token']);

        $error = $this->capture(fn () => Livewire::actingAs($administrator)->test(BotForm::class, ['botId' => $first->id])->set('botId', $second->id));
        $this->assertInstanceOf(CannotUpdateLockedPropertyException::class, $error);
        $this->assertSame('botId', $error->property);

        Livewire::actingAs($administrator)->test(BotList::class)->call('rotateKey', $member->id)->assertNotFound();
        Livewire::actingAs($administrator)->test(BotList::class)->call('delete', $inactive->id)->assertNotFound();
        $this->assertSame('first-token', $first->fresh()->bot_token);
        $this->assertSame('second-token', $second->fresh()->bot_token);
    }

    public function test_every_bot_mutator_rechecks_current_administrator(): void
    {
        [$administrator] = $this->fixture();
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        $bot = User::create(['name' => 'Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'unchanged-token']);

        $list = Livewire::actingAs($administrator)->test(BotList::class)->instance();
        $form = Livewire::actingAs($administrator)->test(BotForm::class, ['botId' => $bot->id])->instance();
        $this->assertInstanceOf(BotList::class, $list);
        $this->assertInstanceOf(BotForm::class, $form);
        auth()->setUser($member);

        foreach ([fn () => $list->rotateKey($bot->id), fn () => $list->delete($bot->id), fn () => $form->save(), fn () => $form->rotateKey(), fn () => $form->delete()] as $mutation) {
            $error = $this->capture($mutation);
            $this->assertInstanceOf(HttpExceptionInterface::class, $error);
            $this->assertSame(403, $error->getStatusCode());
        }

        $this->assertDatabaseHas('users', ['id' => $bot->id, 'status' => 0, 'bot_token' => 'unchanged-token']);
    }

    public function test_push_registration_list_remove_and_test_are_scoped_to_the_authenticated_user(): void
    {
        [$user] = $this->fixture();
        $other = User::create(['name' => 'Other', 'role' => 0, 'status' => 0]);
        Livewire::actingAs($user)->test(PushSubscriptions::class)
            ->call('register', 'https://fcm.googleapis.com/test-current', 'current-public-key', 'current-auth-key');
        $mine = DB::table('push_subscriptions')->where('user_id', $user->id)->first();
        $otherId = DB::table('push_subscriptions')->insertGetId([
            'user_id' => $other->id,
            'endpoint' => 'https://fcm.googleapis.com/test-other',
            'p256dh_key' => 'other-public-key',
            'auth_key' => 'other-auth-key',
            'user_agent' => 'Other browser',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        Livewire::actingAs($user)->test(PushSubscriptions::class)
            ->assertSee('data-testid="push-subscription-row"', false)
            ->assertDontSee('current-public-key')
            ->assertDontSee('current-auth-key')
            ->assertDontSee('Other browser')
            ->call('testNotification', $otherId)
            ->assertNotFound();
        Queue::assertNothingPushed();

        Livewire::actingAs($user)->test(PushSubscriptions::class)->call('remove', $otherId)->assertNotFound();
        $this->assertDatabaseHas('push_subscriptions', ['id' => $otherId, 'user_id' => $other->id]);

        Livewire::actingAs($user)->test(PushSubscriptions::class)->call('testNotification', $mine->id);
        Queue::assertPushed(DeliverPush::class, fn (DeliverPush $job) => $job->subscription['id'] === $mine->id
            && $job->subscription['user_id'] === $user->id
            && $job->payload === DeliverPush::payload('Campfire', 'Notifications are working', '/'));
        Livewire::actingAs($user)->test(PushSubscriptions::class)->call('remove', $mine->id);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $mine->id]);
    }

    public function test_login_rate_limit_and_valid_credentials_use_the_existing_session_contract(): void
    {
        [$user] = $this->fixture();
        RateLimiter::clear('login:127.0.0.1');

        Livewire::test(SignIn::class)->set('email', $user->email_address)->set('password', 'wrong')->call('login')->assertSet('failed', true);
        $this->assertSame(1, RateLimiter::attempts('login:127.0.0.1'));

        session()->setId(str_repeat('a', 40));
        $sessionId = session()->getId();
        Livewire::test(SignIn::class)->set('email', $user->email_address)->set('password', 'secret123456')->call('login')->assertRedirect(route('chat.root'));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);

        RateLimiter::clear('login:127.0.0.1');
        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('login:127.0.0.1', 180);
        }
        Livewire::test(SignIn::class)->set('email', $user->email_address)->set('password', 'secret123456')->call('login')->assertSet('failed', true);
    }

    public function test_join_and_transfer_identifiers_are_locked_and_revalidated_at_submit(): void
    {
        [$user] = $this->fixture();
        $join = Livewire::test(SignUp::class, ['firstRun' => false, 'joinCode' => 'abcd-efgh-ijkl']);
        $joinError = $this->capture(fn () => $join->set('joinCode', 'substituted'));
        $this->assertInstanceOf(CannotUpdateLockedPropertyException::class, $joinError);

        $joinInstance = $join->instance();
        $this->assertInstanceOf(SignUp::class, $joinInstance);
        $joinInstance->joinCode = 'substituted';
        $joinInstance->name = 'Injected';
        $joinInstance->email = 'injected@example.test';
        $joinInstance->password = 'password123';
        $joinSubmitError = $this->capture(fn () => $joinInstance->submit());
        $this->assertInstanceOf(HttpExceptionInterface::class, $joinSubmitError);
        $this->assertSame(404, $joinSubmitError->getStatusCode());
        $this->assertDatabaseMissing('users', ['email_address' => 'injected@example.test']);

        $transferId = app(SignedIdentifiers::class)->signedId($user->id, 'User', 'transfer', now()->addHour()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $transfer = Livewire::test(SessionTransfer::class, ['transferId' => $transferId]);
        $transferError = $this->capture(fn () => $transfer->set('transferId', 'substituted'));
        $this->assertInstanceOf(CannotUpdateLockedPropertyException::class, $transferError);

        $transferInstance = $transfer->instance();
        $this->assertInstanceOf(SessionTransfer::class, $transferInstance);
        $transferInstance->transferId = 'substituted';
        $transferSubmitError = $this->capture(fn () => $transferInstance->confirm());
        $this->assertInstanceOf(HttpExceptionInterface::class, $transferSubmitError);
        $this->assertSame(400, $transferSubmitError->getStatusCode());
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_first_run_rechecks_the_single_account_guard_at_submit(): void
    {
        $component = Livewire::test(SignUp::class, ['firstRun' => true, 'joinCode' => '']);
        DB::table('accounts')->insert(['name' => 'Won Race', 'join_code' => 'race-code', 'settings' => '{}', 'singleton_guard' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $component->set('name', 'Late User')->set('email', 'late@example.test')->set('password', 'password123')->call('submit')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email_address' => 'late@example.test']);
    }

    public function test_livewire_first_run_join_and_transfer_complete_their_session_transitions(): void
    {
        Livewire::test(SignUp::class, ['firstRun' => true, 'joinCode' => ''])
            ->set('name', 'First Administrator')
            ->set('email', 'first@example.test')
            ->set('password', 'password123')
            ->call('submit')
            ->assertRedirect(route('chat.root'));
        $administrator = User::where('email_address', 'first@example.test')->firstOrFail();
        $this->assertSame(1, $administrator->role);
        $this->assertDatabaseHas('sessions', ['user_id' => $administrator->id]);

        $joinCode = DB::table('accounts')->value('join_code');
        Livewire::test(SignUp::class, ['firstRun' => false, 'joinCode' => $joinCode])
            ->set('name', 'Joined Member')
            ->set('email', 'joined-livewire@example.test')
            ->set('password', 'password123')
            ->call('submit')
            ->assertRedirect(route('chat.root'));
        $joined = User::where('email_address', 'joined-livewire@example.test')->firstOrFail();
        $this->assertSame(0, $joined->role);
        $this->assertDatabaseHas('sessions', ['user_id' => $joined->id]);

        $transferId = app(SignedIdentifiers::class)->signedId($joined->id, 'User', 'transfer', now()->addHour()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        Livewire::test(SessionTransfer::class, ['transferId' => $transferId])->call('confirm')->assertRedirect(route('chat.root'));
        $this->assertSame(2, DB::table('sessions')->where('user_id', $joined->id)->count());
    }

    public function test_livewire_logout_removes_only_the_current_devices_push_subscription(): void
    {
        [$user] = $this->fixture();
        $other = User::create(['name' => 'Other', 'role' => 0, 'status' => 0]);
        foreach ([[$user, 'current'], [$other, 'other']] as [$owner, $suffix]) {
            DB::table('push_subscriptions')->insert([
                'user_id' => $owner->id,
                'endpoint' => 'https://fcm.googleapis.com/'.$suffix,
                'p256dh_key' => $suffix.'-public-key',
                'auth_key' => $suffix.'-auth-key',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Livewire::actingAs($user)->test(LogoutButton::class)
            ->call('logout', 'https://fcm.googleapis.com/current')
            ->assertRedirect(route('session.new'));

        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $user->id, 'endpoint' => 'https://fcm.googleapis.com/current']);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $other->id, 'endpoint' => 'https://fcm.googleapis.com/other']);
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
