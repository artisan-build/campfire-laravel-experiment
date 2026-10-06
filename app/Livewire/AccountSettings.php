<?php

namespace App\Livewire;

use App\Models\Attachment;
use App\Models\User;
use App\Support\BlobStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

final class AccountSettings extends Component
{
    use WithFileUploads;

    public string $name = '';

    public bool $restricted = false;

    public $logo;

    public function mount(): void
    {
        $account = DB::table('accounts')->firstOrFail();
        $this->name = $account->name;
        $this->restricted = (bool) (json_decode($account->settings ?? '{}', true)['restrict_room_creation_to_administrators'] ?? false);
    }

    public function save(): void
    {
        $this->authorizeAdministrator();
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'restricted' => 'boolean',
            'logo' => 'nullable|image|max:10240',
        ]);
        $account = DB::table('accounts')->firstOrFail();
        $settings = json_decode($account->settings ?? '{}', true);
        $settings['restrict_room_creation_to_administrators'] = $validated['restricted'];
        DB::table('accounts')->where('id', $account->id)->update([
            'name' => $validated['name'],
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);
        if ($this->logo) {
            app(BlobStorage::class)->attachTo('Account', $account->id, 'logo', $this->logo);
            $this->logo = null;
        }
        session()->flash('notice', 'Account settings saved.');
    }

    public function deleteLogo(): void
    {
        $this->authorizeAdministrator();
        Attachment::where('record_type', 'Account')->where('name', 'logo')->delete();
    }

    public function resetJoinCode(): void
    {
        $this->authorizeAdministrator();
        DB::table('accounts')->update(['join_code' => Str::random(4).'-'.Str::random(4).'-'.Str::random(4), 'updated_at' => now()]);
    }

    public function toggleAdministrator(int $userId): void
    {
        $this->authorizeAdministrator();
        $user = User::active()->where('role', '!=', 2)->findOrFail($userId);
        abort_if($user->id === $this->user()->id, 403);
        $user->update(['role' => $user->role === 1 ? 0 : 1]);
    }

    public function deleteMember(int $userId): void
    {
        $this->authorizeAdministrator();
        $user = User::active()->where('role', '!=', 2)->findOrFail($userId);
        abort_if($user->id === $this->user()->id, 403);
        $user->deactivate();
    }

    public function render()
    {
        $account = DB::table('accounts')->firstOrFail();

        return view('livewire.account-settings', [
            'account' => $account,
            'users' => User::whereIn('status', $this->user()->role === 1 ? [0, 2] : [0])->where('role', '!=', 2)->orderByRaw('LOWER(name)')->get(),
            'hasLogo' => Attachment::where('record_type', 'Account')->where('record_id', $account->id)->where('name', 'logo')->exists(),
            'currentUser' => $this->user(),
        ]);
    }

    private function authorizeAdministrator(): void
    {
        abort_unless($this->user()->role === 1, 403);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
