<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use App\Support\RailsCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CampfireGuardTest extends TestCase
{
    public function test_the_native_guard_resolves_the_same_human_for_request_auth_and_blade(): void
    {
        [$user] = $this->fixture();
        Route::middleware(['web', 'campfire.auth', 'auth'])->get('/_guard_probe', function (Request $request) {
            return response()->json([
                'request' => $request->user()?->id,
                'auth' => auth()->id(),
                'check' => Auth::check(),
                'blade' => trim(Blade::render('@auth authenticated @else guest @endauth')),
            ]);
        });
        $this->auth($user);

        $this->get('/_guard_probe')->assertOk()->assertJson([
            'request' => $user->id,
            'auth' => $user->id,
            'check' => true,
            'blade' => 'authenticated',
        ]);
    }

    public function test_ip_bans_bot_keys_and_bot_sessions_are_still_rejected(): void
    {
        [$user, $room] = $this->fixture();
        $bot = User::create(['name' => 'Bot', 'role' => 2, 'status' => 0, 'bot_token' => 'published-token']);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);

        $this->auth($user);
        $this->get('/rooms/'.$room->id.'?bot_key='.$bot->id.'-'.$bot->bot_token)->assertForbidden();

        DB::table('bans')->insert(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/rooms/'.$room->id)->assertForbidden();

        DB::table('bans')->delete();
        $this->flushSession();
        $this->auth($bot);
        $this->get('/rooms/'.$room->id)->assertForbidden();
        $this->assertFalse(Auth::check());
    }

    public function test_last_active_is_throttled_to_once_an_hour(): void
    {
        [$user, $room] = $this->fixture();
        $token = 'old-session';
        $old = now()->subHours(2)->startOfSecond();
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $user->id, 'last_active_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        $this->withUnencryptedCookie('session_token', app(RailsCrypto::class)->signCookie('session_token', $token));

        $this->get('/rooms/'.$room->id, ['User-Agent' => 'guard-test'])->assertOk();
        $session = DB::table('sessions')->where('token', $token)->first();
        $this->assertTrue(now()->diffInSeconds($session->last_active_at) < 10);
        $this->assertSame('guard-test', $session->user_agent);

        $updated = $session->updated_at;
        $this->get('/rooms/'.$room->id, ['User-Agent' => 'changed'])->assertOk();
        $session = DB::table('sessions')->where('token', $token)->first();
        $this->assertSame($updated, $session->updated_at);
        $this->assertSame('guard-test', $session->user_agent);
    }

    public function test_public_join_qr_and_session_transfer_contracts_remain_available(): void
    {
        [$user] = $this->fixture();
        $this->post('/join/abcd-efgh-ijkl', ['user' => ['name' => 'Joined User', 'email_address' => 'joined@example.test', 'password' => 'long-enough-password']])
            ->assertRedirect()
            ->assertCookie('session_token');
        $this->assertDatabaseHas('users', ['email_address' => 'joined@example.test']);

        $url = 'https://campfire.test/session/transfers/example';
        $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
        $this->get('/qr_code/'.$encoded)->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $transfer = app(RailsCrypto::class)->signedId($user->id, 'User', 'transfer', now()->addHour()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $this->put('/session/transfers/'.$transfer)->assertRedirect()->assertCookie('session_token');
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    }
}
