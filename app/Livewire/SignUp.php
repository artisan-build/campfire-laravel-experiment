<?php

namespace App\Livewire;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\SessionAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

final class SignUp extends Component
{
    use WithFileUploads;

    #[Locked]
    public bool $firstRun = false;

    #[Locked]
    public string $joinCode = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public $avatar;

    public function mount(bool $firstRun = false, string $joinCode = ''): void
    {
        $this->firstRun = $firstRun;
        $this->joinCode = $joinCode;
        if ($firstRun) {
            abort_if(DB::table('accounts')->exists(), 403);
        } else {
            $this->authorizeJoinCode();
        }
    }

    public function submit()
    {
        if ($this->firstRun) {
            abort_if(DB::table('accounts')->exists(), 403);
        } else {
            $this->authorizeJoinCode();
        }

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:72',
            'avatar' => 'nullable|image|max:10240',
        ]);

        $user = DB::transaction(function () use ($validated): User {
            if ($this->firstRun) {
                abort_if(DB::table('accounts')->exists(), 403);
                DB::table('accounts')->insert([
                    'name' => 'Campfire',
                    'join_code' => Str::random(4).'-'.Str::random(4).'-'.Str::random(4),
                    'settings' => '{}',
                    'singleton_guard' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $this->authorizeJoinCode();
            }

            $user = User::create([
                'name' => $validated['name'],
                'email_address' => $validated['email'],
                'password_digest' => password_hash($validated['password'], PASSWORD_BCRYPT),
                'role' => $this->firstRun ? 1 : 0,
                'status' => 0,
            ]);

            if ($this->firstRun) {
                $room = Room::create(['name' => 'All Talk', 'type' => 'Rooms::Open', 'creator_id' => $user->id]);
                Membership::create(['room_id' => $room->id, 'user_id' => $user->id, 'involvement' => 'mentions']);
            } else {
                foreach (Room::where('type', 'Rooms::Open')->pluck('id') as $roomId) {
                    Membership::create(['room_id' => $roomId, 'user_id' => $user->id, 'involvement' => 'mentions']);
                }
            }

            return $user;
        });

        if ($this->avatar) {
            app(BlobStorage::class)->attachTo('User', $user->id, 'avatar', $this->avatar);
        }

        return $this->redirect(app(SessionAuthentication::class)->start(request(), $user));
    }

    public function render()
    {
        return view('livewire.sign-up');
    }

    private function authorizeJoinCode(): void
    {
        abort_unless(hash_equals((string) (DB::table('accounts')->value('join_code') ?? ''), $this->joinCode), 404);
    }
}
