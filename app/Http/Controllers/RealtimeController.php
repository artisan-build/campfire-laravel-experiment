<?php

namespace App\Http\Controllers;

use App\Events\TypingNotification;
use App\Support\Presence;
use App\Support\RoomAccess;
use Illuminate\Http\Request;

/**
 * The two things the browser used to send *up* an Action Cable socket.
 *
 * Cloud's managed Reverb is a relay: it cannot call the app, and its client-event and webhook
 * settings are not exposed by the CLI at all, so a whisper would have had no server-side effect and
 * no way to tell whether it was even enabled. These reuse Campfire's own session and membership
 * check, cost one short request each, and keep presence in the database where every instance and the
 * unread logic can see it.
 */
final class RealtimeController extends Controller
{
    public function presence(Request $request, int $room)
    {
        abort_unless(RoomAccess::member($request->user(), $room), 403);

        $action = $request->validate(['action' => 'required|in:present,absent,refresh'])['action'];

        match ($action) {
            'present' => app(Presence::class)->present($request->user()->id, $room),
            'absent' => app(Presence::class)->absent($request->user()->id, $room),
            'refresh' => app(Presence::class)->refresh($request->user()->id, $room),
        };

        return response()->noContent();
    }

    public function typing(Request $request, int $room)
    {
        abort_unless(RoomAccess::member($request->user(), $room), 403);

        $action = $request->validate(['action' => 'required|in:start,stop'])['action'];

        TypingNotification::dispatch($room, $request->user(), $action);

        return response()->noContent();
    }
}
