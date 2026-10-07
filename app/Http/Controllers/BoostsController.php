<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Boost;
use App\Models\Message;
use App\Support\ChatEvents;
use Illuminate\Http\Request;

final class BoostsController extends Controller
{
    private function message(Request $r, int $id): Message
    {
        return Message::presentation()->whereIn('room_id', $r->user()->rooms()->select('rooms.id'))->findOrFail($id);
    }

    public function create(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        $r->validate(['boost.content' => 'required|string|max:16']);
        $boost = Boost::create(['message_id' => $id, 'booster_id' => $r->user()->id, 'content' => $r->input('boost.content')]);
        app(ChatEvents::class)->boostAdded($m, $boost);

        return $r->expectsJson()
            ? response()->json(['message_id' => (int) $m->id, 'boost' => MessageResource::boostArray($boost)], 201)
            : redirect('/messages/'.$id.'/boosts');
    }

    public function destroy(Request $r, int $id, int $boost)
    {
        $m = $this->message($r, $id);
        $b = $m->boosts()->findOrFail($boost);
        abort_unless($r->user()->id === $b->booster_id, 403);
        app(ChatEvents::class)->removeBoost($m, $b);

        if ($r->expectsJson()) {
            return response()->noContent();
        }

        return redirect('/rooms/'.$m->room_id, 303);
    }
}
