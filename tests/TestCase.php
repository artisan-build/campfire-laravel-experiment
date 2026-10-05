<?php

namespace Tests;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\RailsCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Room}
     */
    protected function fixture(): array
    {
        $user = User::create(['name' => 'David', 'email_address' => 'david@example.org', 'password_digest' => password_hash('secret123456', PASSWORD_BCRYPT), 'role' => 1, 'status' => 0]);
        $room = Room::create(['name' => 'Watercooler', 'type' => 'Rooms::Open', 'creator_id' => $user->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
        DB::table('accounts')->insert(['name' => 'Campfire', 'join_code' => 'abcd-efgh-ijkl', 'settings' => '{}', 'singleton_guard' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $room];
    }

    protected function auth(User $user): void
    {
        $token = 'local-fixture-session-'.$user->id;
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $user->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withUnencryptedCookie('session_token', app(RailsCrypto::class)->signCookie('session_token', $token));
    }

    /** The message ids Postgres full text search returns for a query, newest first. */
    protected function searchIds(string $query): array
    {
        return DB::table('message_search_index')->whereFullText('body', $query)->pluck('message_id')->all();
    }
}
