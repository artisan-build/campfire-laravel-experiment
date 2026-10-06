<?php

namespace App\Livewire;

use App\Models\Attachment;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\SidebarEvents;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

final class ProfileSettings extends Component
{
    use WithFileUploads;

    public string $name = '';

    public ?string $email = null;

    public ?string $bio = null;

    public string $password = '';

    public $avatar;

    public function mount(): void
    {
        $user = $this->user();
        $this->name = $user->name;
        $this->email = $user->email_address;
        $this->bio = $user->bio;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'bio' => 'nullable|string|max:200',
            'password' => 'nullable|string|max:72',
            'avatar' => 'nullable|image|max:10240',
        ]);
        $user = $this->user();
        $values = ['name' => $validated['name'], 'email_address' => $validated['email'], 'bio' => $validated['bio']];
        if ($validated['password'] !== '') {
            $values['password_digest'] = password_hash($validated['password'], PASSWORD_BCRYPT);
        }
        $user->update($values);
        if ($this->avatar) {
            app(BlobStorage::class)->attachTo('User', $user->id, 'avatar', $this->avatar);
            $this->avatar = null;
        }
        $this->password = '';
        session()->flash('notice', 'Profile saved.');
    }

    public function deleteAvatar(): void
    {
        $user = $this->user();
        Attachment::where(['record_type' => 'User', 'record_id' => $user->id, 'name' => 'avatar'])->delete();
        $user->touch();
    }

    public function setInvolvement(int $roomId, string $involvement): void
    {
        abort_unless(in_array($involvement, ['invisible', 'nothing', 'mentions', 'everything'], true), 422);
        $membership = $this->user()->memberships()->where('room_id', $roomId)->firstOrFail();
        abort_if($membership->room->type === 'Rooms::Direct' && ! in_array($involvement, ['everything', 'nothing'], true), 422);
        $membership->update(['involvement' => $involvement]);
        app(SidebarEvents::class)->refresh([$this->user()->id]);
    }

    public function render()
    {
        $user = $this->user();

        return view('livewire.profile-settings', [
            'user' => $user,
            'memberships' => $user->memberships()->with('room.users')->get(),
            'hasAvatar' => Attachment::where('record_type', 'User')->where('record_id', $user->id)->where('name', 'avatar')->exists(),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
