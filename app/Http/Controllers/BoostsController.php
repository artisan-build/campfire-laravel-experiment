<?php

namespace App\Http\Controllers;

use App\Models\Boost;
use App\Models\Message;
use App\Support\Broadcasting;
use Illuminate\Http\Request;

final class BoostsController extends Controller
{
    private function message(Request $r, int $id): Message
    {
        return Message::presentation()->whereIn('room_id', $r->user()->rooms()->select('rooms.id'))->findOrFail($id);
    }

    public function index(Request $r, int $id)
    {
        $message = $this->message($r, $id);

        return view('boosts.index', compact('message'));
    }

    public function new(Request $r, int $id)
    {
        $message = $this->message($r, $id);

        return view('boosts.new', compact('message'));
    }

    public function create(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        $r->validate(['boost.content' => 'required|string|max:16']);
        $boost = Boost::create(['message_id' => $id, 'booster_id' => $r->user()->id, 'content' => $r->input('boost.content')]);
        $html = view('boosts.boost', ['boost' => $boost->load('booster')])->render();
        $s = app(ChatController::class)->stream('append', 'boosts_message_'.$m->client_message_id, $html);
        app(Broadcasting::class)->room($m->room_id, $s);

        return redirect('/messages/'.$id.'/boosts');
    }

    public function destroy(Request $r, int $id, int $boost)
    {
        $m = $this->message($r, $id);
        $b = $m->boosts()->findOrFail($boost);
        abort_unless($r->user()->id === $b->booster_id, 403);
        $b->delete();
        $s = app(ChatController::class)->stream('remove', 'boost_'.$boost, '');
        app(Broadcasting::class)->room($m->room_id, $s);

        return response($s)->header('Content-Type', 'text/vnd.turbo-stream.html');
    }
}
