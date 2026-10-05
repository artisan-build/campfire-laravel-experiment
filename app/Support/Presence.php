<?php

namespace App\Support;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/**
 * Who is looking at a room right now, asked of Reverb rather than tracked by the app.
 *
 * Upstream drove this from its own Action Cable process's connection lifecycle. The first pass at
 * Cloud replaced that with a browser that posted `present` / `refresh` / `absent` over HTTP, and the
 * fifty-second refresh that implies was measured to be the only thing keeping compute awake: a single
 * idle tab stopped the app ever scaling to zero.
 *
 * Reverb already holds the answer — it maintains the presence channel's member list — and exposes it
 * over the Pusher HTTP API the app already uses to broadcast. So the browser now sends nothing on a
 * timer, and the two places that care (marking a room unread, and deciding whether to push) ask
 * Reverb at the moment they need to know.
 *
 * With no Reverb configured, or with Reverb unreachable, this reports nobody present. That is the
 * safe direction: unread marks and push notifications still go out, and the worst case is a
 * notification for a message somebody was already looking at.
 */
final class Presence
{
    /**
     * The user ids currently subscribed to a room's presence channel.
     *
     * @return array<int, int>
     */
    public function inRoom(int $roomId): array
    {
        $broadcaster = $this->broadcaster();

        if (! $broadcaster) {
            return [];
        }

        try {
            $response = $broadcaster->getPusher()->getPresenceUsers('presence-rooms.'.$roomId.'.presence');
        } catch (\Throwable $error) {
            // Fail open: nobody is present, so everyone is notified.
            Log::warning('Presence lookup failed, treating the room as empty', ['room' => $roomId, 'error' => $error->getMessage()]);

            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($user) => (int) (is_array($user) ? $user['id'] : $user->id),
            (array) ($response->users ?? []),
        )));
    }

    private function broadcaster(): ?PusherBroadcaster
    {
        $broadcaster = Broadcast::driver();

        return $broadcaster instanceof PusherBroadcaster ? $broadcaster : null;
    }
}
