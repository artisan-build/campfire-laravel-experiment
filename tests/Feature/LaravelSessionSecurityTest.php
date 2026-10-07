<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LaravelSessionSecurityTest extends TestCase
{
    public function test_laravel_encrypted_session_token_authenticates_and_plaintext_does_not(): void
    {
        [$user, $room] = $this->fixture();
        $token = 'native-cookie-token';
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $user->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withCookie('session_token', $token)->get('/rooms/'.$room->id)->assertOk();

        $this->flushSession();
        $this->withUnencryptedCookie('session_token', $token)->get('/rooms/'.$room->id)->assertRedirect('/session/new');
    }

    public function test_login_queues_only_laravel_encrypted_cookies(): void
    {
        [$user] = $this->fixture();

        $response = $this->post('/session', ['email_address' => $user->email_address, 'password' => 'secret123456']);

        $response->assertRedirect()->assertCookie('session_token');
        $this->assertNotSame(
            DB::table('sessions')->where('user_id', $user->id)->value('token'),
            $response->getCookie('session_token', decrypt: false)->getValue(),
        );
        $response->assertCookieMissing('_campfire_session');
    }

    public function test_laravel_csrf_middleware_rejects_a_missing_token(): void
    {
        $this->fixture();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->withMiddleware(PreventRequestForgery::class)
                ->post('/session', ['email_address' => 'david@example.org', 'password' => 'secret123456'])
                ->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
