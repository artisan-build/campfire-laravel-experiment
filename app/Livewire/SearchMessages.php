<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\User;
use App\Support\Search;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class SearchMessages extends Component
{
    public string $query = '';

    public function mount(): void
    {
        $this->query = Search::normalize((string) request()->query('q', ''));
    }

    public function search(): void
    {
        $this->query = Search::normalize($this->query);
        if ($this->query !== '') {
            DB::table('searches')->updateOrInsert(
                ['user_id' => $this->user()->id, 'query' => $this->query],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function clearHistory(): void
    {
        DB::table('searches')->where('user_id', $this->user()->id)->delete();
    }

    public function render()
    {
        $messages = collect();
        if ($this->query !== '') {
            $messages = Message::presentation()
                ->join('message_search_index as idx', 'messages.id', '=', 'idx.message_id')
                ->whereFullText('idx.body', $this->query)
                ->whereIn('room_id', $this->user()->rooms()->select('rooms.id'))
                ->select('messages.*')
                ->orderByDesc('messages.created_at')
                ->limit(100)
                ->get()
                ->reverse();
        }

        return view('livewire.search-messages', [
            'messages' => $messages,
            'history' => DB::table('searches')->where('user_id', $this->user()->id)->latest('updated_at')->pluck('query'),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
