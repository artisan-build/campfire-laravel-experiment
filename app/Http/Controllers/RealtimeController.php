<?php

namespace App\Http\Controllers;

use App\Events\TypingNotification;
use App\Support\RoomAccess;
use Illuminate\Http\Request;

/**
 * Typing, which the browser used to send up an Action Cable socket.
 *
 * Cloud's managed Reverb is a relay: it cannot call the app, and its client-event and webhook
 * settings are not exposed by the CLI at all, so a whisper would have had no server-side effect and
 * no way to tell whether it was even enabled. This reuses Campfire's own session and membership
 * check and costs one short request per throttled keystroke — which is user activity, not a timer.
 *
 * Presence used to live here too and does not any more: see App\Support\Presence.
 */
final class RealtimeController extends Controller
{
    public function typing(Request $request, int $room)
    {
        abort_unless(RoomAccess::member($request->user(), $room), 403);

        $action = $request->validate(['action' => 'required|in:start,stop'])['action'];

        TypingNotification::dispatch($room, $request->user(), $action);

        return response()->noContent();
    }
}
