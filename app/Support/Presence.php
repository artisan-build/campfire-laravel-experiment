<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Room presence, counted in the database so it is the same on every instance.
 *
 * Upstream drove this from the Action Cable process's own connection lifecycle. The socket now lives
 * in Cloud's managed Reverb, which cannot reach back into the app, so the browser reports presence
 * over HTTP and a stale entry expires on its own after TTL_SECONDS.
 *
 * The three statements are hand-written SQL rather than builder updates because each needs the same
 * bound timestamp in more than one place, and a DB::raw() expression inside update() carries no
 * bindings of its own.
 */
final class Presence
{
    public const TTL_SECONDS = 60;

    public function present(int $userId, int $roomId): void
    {
        $this->update(
            'connections = CASE WHEN connected_at >= ? THEN connections + 1 ELSE 1 END, connected_at = ?, unread_at = NULL, updated_at = ?',
            [$this->since(), $this->now(), $this->now()],
            $userId,
            $roomId,
        );
        app(Broadcasting::class)->read($userId, $roomId);
    }

    public function refresh(int $userId, int $roomId): void
    {
        $this->update(
            'connections = CASE WHEN connected_at >= ? THEN connections ELSE 1 END, connected_at = ?, updated_at = ?',
            [$this->since(), $this->now(), $this->now()],
            $userId,
            $roomId,
        );
    }

    public function absent(int $userId, int $roomId): void
    {
        $since = $this->since();

        $this->update(
            'connected_at = CASE WHEN connected_at >= ? AND connections > 1 THEN connected_at ELSE NULL END, '.
            'connections = CASE WHEN connected_at >= ? THEN GREATEST(connections - 1, 0) ELSE 0 END, updated_at = ?',
            [$since, $since, $this->now()],
            $userId,
            $roomId,
        );
    }

    private function update(string $assignments, array $bindings, int $userId, int $roomId): void
    {
        DB::update(
            'UPDATE memberships SET '.$assignments.' WHERE user_id = ? AND room_id = ?',
            [...$bindings, $userId, $roomId],
        );
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    private function since(): string
    {
        return now()->subSeconds(self::TTL_SECONDS)->format('Y-m-d H:i:s.u');
    }
}
