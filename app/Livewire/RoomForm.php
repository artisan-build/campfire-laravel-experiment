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
use Livewire\Attributes\Locked;
use Livewire\Component;

final class RoomForm extends Component
{
    #[Locked]
    public string $kind;

    #[Locked]
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
        $targetKind = RoomKind::fromRoute($kind);
        if ($this->room) {
            Gate::authorize('transitionKind', [$this->room, $targetKind]);
        } else {
            abort_if($targetKind === RoomKind::Direct, 404);
            Gate::authorize('create', [Room::class, $targetKind->value]);
        }
        $this->kind = $targetKind->value;
    }

    public function save()
    {
        $kind = RoomKind::fromRoute($this->kind);
        $this->room
            ? Gate::authorize('transitionKind', [$this->room, $kind])
            : Gate::authorize('create', [Room::class, $kind->value]);

        $validated = $this->validate([
            'name' => $kind === RoomKind::Direct ? 'nullable|string|max:255' : 'required|string|max:255',
            'selected' => 'array',
            'selected.*' => 'integer',
        ]);
        $ids = User::active()->whereIn('id', $validated['selected'])->pluck('id')->all();
        if ($kind === RoomKind::Open) {
            $ids = User::pluck('id')->all();
        } elseif ($kind === RoomKind::Direct) {
            $ids[] = $this->user()->id;
            $ids = array_values(array_unique($ids));
            sort($ids);
        }

        $previousMembers = $this->room?->users()->pluck('users.id')->all() ?? [];
        $room = DB::transaction(function () use ($ids, $kind): Room {
            if (! $this->room && $kind === RoomKind::Direct) {
                foreach (Room::where('type', 'Rooms::Direct')->with('users')->get() as $candidate) {
                    if ($candidate->users->pluck('id')->sort()->values()->all() === $ids) {
                        return $candidate;
                    }
                }
            }

            $room = $this->room ?? Room::create([
                'name' => $kind === RoomKind::Direct ? null : $this->name,
                'type' => $kind->roomType(),
                'creator_id' => $this->user()->id,
            ]);
            $room->update(['name' => $kind === RoomKind::Direct ? null : $this->name, 'type' => $kind->roomType()]);
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

        DB::transaction(function (): void {
            foreach ($this->room->messages()->get() as $message) {
                app(MessageWriter::class)->destroy($message);
            }
            $this->room->memberships()->delete();
            $this->room->delete();
        });

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
