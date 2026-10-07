<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Room;
use App\Support\Broadcasting;
use App\Support\ChatEvents;
use App\Support\MessageWriter;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChatController extends Controller
{
    public function root(Request $r)
    {
        $room = $r->user()->rooms()->orderByDesc('id')->first();

        return $room ? redirect('/rooms/'.$room->id) : redirect('/rooms/opens/new');
    }

    public function room(Request $r, int $id, ?int $message = null)
    {
        $room = $this->findRoom($r, $id);
        $query = $room->messages()->presentation();
        if ($message) {
            $at = $room->messages()->findOrFail($message);
            $messages = $query->clone()->where(fn ($q) => $q->where('created_at', '<', $at->getRawOriginal('created_at'))->orWhere(fn ($tie) => $tie->where('created_at', $at->getRawOriginal('created_at'))->where('id', '<', $at->id)))->orderByDesc('created_at')->orderByDesc('id')->limit(40)->get()->reverse()->concat([$at->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob'])])->concat($query->clone()->where(fn ($q) => $q->where('created_at', '>', $at->getRawOriginal('created_at'))->orWhere(fn ($tie) => $tie->where('created_at', $at->getRawOriginal('created_at'))->where('id', '>', $at->id)))->orderBy('created_at')->orderBy('id')->limit(40)->get());
        } else {
            $messages = $query->orderByDesc('created_at')->orderByDesc('id')->limit(40)->get()->reverse()->values();
        }
        $r->session()->put('last_room_id', $room->id);
        $this->markRead($r->user()->id, $room->id);
        $memberships = $r->user()->memberships()->where('involvement', '!=', 'invisible')->with('room.users')->get();
        $directs = $memberships->filter(fn ($membership) => $membership->room->type === 'Rooms::Direct')->sortByDesc(fn ($membership) => $membership->room->updated_at);
        $shared = $memberships->reject(fn ($membership) => $membership->room->type === 'Rooms::Direct')->sortBy(fn ($membership) => mb_strtolower($membership->room->name ?? ''));

        return response()->view('rooms.show', compact('room', 'messages', 'directs', 'shared') + ['messageStream' => true])->withCookie(cookie('last_room', (string) $room->id, 60 * 24 * 365 * 20));
    }

    public function messages(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $q = $room->messages()->presentation();
        if ($r->filled('before')) {
            $at = $room->messages()->findOrFail($r->input('before'));
            $q->where(fn ($query) => $query->where('created_at', '<', $at->getRawOriginal('created_at'))->orWhere(fn ($tie) => $tie->where('created_at', $at->getRawOriginal('created_at'))->where('id', '<', $at->id)));
        }
        if ($r->filled('after')) {
            $at = $room->messages()->findOrFail($r->input('after'));
            $messages = $q->where(fn ($query) => $query->where('created_at', '>', $at->getRawOriginal('created_at'))->orWhere(fn ($tie) => $tie->where('created_at', $at->getRawOriginal('created_at'))->where('id', '>', $at->id)))->orderBy('created_at')->orderBy('id')->limit(40)->get();
        } else {
            $messages = $q->orderByDesc('created_at')->orderByDesc('id')->limit(40)->get()->reverse()->values();
        }
        if ($messages->isEmpty()) {
            return response('', 204);
        }
        if ($r->expectsJson()) {
            return response()->json($messages->map(fn (Message $message) => (new MessageResource($message))->resolve(new Request)));
        }

        return response()->view('messages.index', compact('messages'));
    }

    public function show(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->presentation()->findOrFail($id);

        return response()->json((new MessageResource($m))->resolve(new Request));
    }

    public function create(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $a = $r->validate(['message' => 'required|array', 'message.body' => 'nullable|string', 'message.client_message_id' => 'nullable|string|max:255', 'message.attachment' => 'nullable']);
        $m = app(MessageWriter::class)->create($room, $r->user(), $r->hasFile('message.attachment') ? array_merge($a['message'], ['attachment' => $r->file('message.attachment')]) : $a['message'], true);
        app(ChatEvents::class)->created($m);

        if ($r->expectsJson()) {
            return response()->json((new MessageResource($m))->resolve(new Request), 201);
        }

        return redirect('/rooms/'.$room->id, 303);
    }

    public function update(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        Gate::authorize('update', $m);
        app(MessageWriter::class)->update($m, $r->input('message', []));
        app(ChatEvents::class)->updated($m);

        return $r->expectsJson() ? response()->json((new MessageResource($m))->resolve(new Request)) : redirect('/rooms/'.$room.'/messages/'.$id);
    }

    public function destroy(Request $r, int $room, int $id)
    {
        $m = $this->findRoom($r, $room)->messages()->findOrFail($id);
        Gate::authorize('delete', $m);
        app(ChatEvents::class)->delete($m);

        if ($r->expectsJson()) {
            return response()->noContent();
        }

        return redirect('/rooms/'.$room, 303);
    }

    public function sidebar(Request $r)
    {
        $memberships = $r->user()->memberships()->where('involvement', '!=', 'invisible')->with('room.users')->get();
        $directs = $memberships->filter(fn ($m) => $m->room->type === 'Rooms::Direct')->sortByDesc(fn ($m) => $m->room->updated_at);
        $shared = $memberships->reject(fn ($m) => $m->room->type === 'Rooms::Direct')->sortBy(fn ($m) => mb_strtolower($m->room->name ?? ''));

        return view('users.sidebar', ['currentUser' => $r->user(), ...compact('directs', 'shared')]);
    }

    public function search(Request $r)
    {
        return view('searches.index');
    }

    public function recordSearch(Request $r)
    {
        $query = Search::normalize($r->input('q', ''));
        DB::table('searches')->updateOrInsert(['user_id' => $r->user()->id, 'query' => $query], ['created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('searches.index', ['q' => $query]);
    }

    public function clearSearch(Request $r)
    {
        DB::table('searches')->where('user_id', $r->user()->id)->delete();

        return redirect()->route('searches.index');
    }

    public function refresh(Request $r, int $room)
    {
        $room = $this->findRoom($r, $room);
        $since = CarbonImmutable::createFromTimestampMs((int) $r->input('since', 0));
        $new = $room->messages()->presentation()->where('created_at', '>', $since)->orderBy('created_at')->limit(40)->get();
        $updated = $room->messages()->presentation()->whereNotIn('id', $new->pluck('id'))->where('updated_at', '>', $since)->orderByDesc('created_at')->limit(40)->get()->reverse();

        return response()->json($new->concat($updated)->unique('id')->sortBy('created_at')->values()->map(
            fn (Message $message) => (new MessageResource($message))->resolve(new Request),
        ));
    }

    /**
     * Opening a room clears its unread mark and tells the member's other tabs.
     *
     * Upstream did this when the presence socket said "present", which cost a request of its own.
     * It rides the page load now, so an idle tab never has to say anything.
     */
    private function markRead(int $userId, int $roomId): void
    {
        $cleared = DB::table('memberships')->where('user_id', $userId)->where('room_id', $roomId)->whereNotNull('unread_at')->update(['unread_at' => null, 'updated_at' => now()]);

        if ($cleared > 0) {
            app(Broadcasting::class)->read($userId, $roomId);
        }
    }

    public function findRoom(Request $r, int $id): Room
    {
        return $r->user()->rooms()->findOrFail($id);
    }
}
