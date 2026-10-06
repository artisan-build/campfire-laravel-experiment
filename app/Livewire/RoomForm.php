<?php

namespace App\Livewire;

use App\Enums\RoomKind;
use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\MessageWriter;
use App\Support\SidebarEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class RoomForm extends Component
{
    public string $kind;

    public ?Room $room = null;

    public string $name = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    public function mount(string $kind, ?Room $room = null): void
    {
        $this->kind = RoomKind::fromRoute($kind)->value;
        $this->room = $room;

        if ($room) {
            abort_unless($this->kindFor($room) === $kind, 404);
            Gate::authorize($kind === 'directs' ? 'view' : 'update', $room);
            $this->name = (string) $room->name;
            $this->selected = $room->users()->pluck('users.id')->all();
        } else {
            Gate::authorize('create', [Room::class, $kind]);
            $this->selected = [$this->user()->id];
        }
    }

    public function changeKind(string $kind): void
    {
        abort_unless(in_array($kind, ['opens', 'closeds'], true), 404);
        $this->room
            ? Gate::authorize('update', $this->room)
            : Gate::authorize('create', [Room::class, $kind]);
        $this->kind = $kind;
    }

    public function save()
    {
        $this->room
            ? Gate::authorize('update', $this->room)
            : Gate::authorize('create', [Room::class, $this->kind]);

        $validated = $this->validate([
            'name' => $this->kind === 'directs' ? 'nullable|string|max:255' : 'required|string|max:255',
            'selected' => 'array',
            'selected.*' => 'integer',
        ]);
        $ids = User::active()->whereIn('id', $validated['selected'])->pluck('id')->all();
        if ($this->kind === 'opens') {
            $ids = User::pluck('id')->all();
        } elseif ($this->kind === 'directs') {
            $ids[] = $this->user()->id;
            $ids = array_values(array_unique($ids));
            sort($ids);
        }

        $previousMembers = $this->room?->users()->pluck('users.id')->all() ?? [];
        $room = DB::transaction(function () use ($ids): Room {
            if (! $this->room && $this->kind === 'directs') {
                foreach (Room::where('type', 'Rooms::Direct')->with('users')->get() as $candidate) {
                    if ($candidate->users->pluck('id')->sort()->values()->all() === $ids) {
                        return $candidate;
                    }
                }
            }

            $room = $this->room ?? Room::create([
                'name' => $this->kind === 'directs' ? null : $this->name,
                'type' => $this->type(),
                'creator_id' => $this->user()->id,
            ]);
            $room->update(['name' => $this->kind === 'directs' ? null : $this->name, 'type' => $this->type()]);
            $room->memberships()->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                Membership::firstOrCreate(
                    ['room_id' => $room->id, 'user_id' => $id],
                    ['involvement' => $room->type === 'Rooms::Direct' ? 'everything' : 'mentions'],
                );
            }

            return $room;
        });

        app(SidebarEvents::class)->refresh(array_values(array_unique(array_merge($previousMembers, $room->users()->pluck('users.id')->all()))));

        return $this->redirect('/rooms/'.$room->id);
    }

    public function delete()
    {
        if ($this->room === null) {
            abort(404);
        }

        Gate::authorize('delete', $this->room);
        $previousMembers = $this->room->users()->pluck('users.id')->all();
        $roomId = $this->room->id;
        $open = $this->room->type === 'Rooms::Open';

        DB::transaction(function (): void {
            foreach ($this->room->messages()->get() as $message) {
                app(MessageWriter::class)->destroy($message);
            }
            $this->room->memberships()->delete();
            $this->room->delete();
        });

        if ($open) {
            app(SidebarEvents::class)->globalRemove($roomId);
        }
        app(SidebarEvents::class)->refresh($previousMembers);

        return $this->redirect('/');
    }

    public function render()
    {
        return view('livewire.room-form', [
            'users' => User::active()->where('role', '!=', 2)->orderByRaw('LOWER(name)')->get(),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function type(): string
    {
        return RoomKind::fromRoute($this->kind)->roomType();
    }

    private function kindFor(Room $room): string
    {
        return match ($room->type) {
            'Rooms::Open' => 'opens',
            'Rooms::Closed' => 'closeds',
            'Rooms::Direct' => 'directs',
            default => abort(404),
        };
    }
}
