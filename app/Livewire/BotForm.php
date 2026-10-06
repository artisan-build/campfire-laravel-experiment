<?php

namespace App\Livewire;

use App\Models\Membership;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\WebhookDestinations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

final class BotForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $botId = null;

    public string $name = '';

    public string $bio = '';

    public string $webhookUrl = '';

    public $avatar;

    public function mount(?int $botId = null): void
    {
        $this->authorizeAdministrator();
        $this->botId = $botId;
        if ($botId !== null) {
            $bot = $this->bot();
            $this->name = $bot->name;
            $this->bio = (string) $bot->bio;
            $this->webhookUrl = (string) DB::table('webhooks')->where('user_id', $bot->id)->value('url');
        }
    }

    public function save()
    {
        $this->authorizeAdministrator();
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:10000',
            'webhookUrl' => ['nullable', 'url:http,https', 'max:2048', app(WebhookDestinations::class)->validationRule()],
            'avatar' => 'nullable|image|max:10240',
        ]);

        $bot = DB::transaction(function () use ($validated): User {
            $bot = $this->botId === null
                ? User::create(['name' => $validated['name'], 'bio' => $validated['bio'], 'role' => 2, 'status' => 0, 'bot_token' => Str::random(12)])
                : $this->bot();

            if ($this->botId !== null) {
                $bot->update(['name' => $validated['name'], 'bio' => $validated['bio']]);
            } else {
                foreach (Room::where('type', 'Rooms::Open')->pluck('id') as $roomId) {
                    Membership::create(['room_id' => $roomId, 'user_id' => $bot->id, 'involvement' => 'mentions']);
                }
            }

            if ($validated['webhookUrl'] !== '') {
                DB::table('webhooks')->updateOrInsert(
                    ['user_id' => $bot->id],
                    ['url' => $validated['webhookUrl'], 'created_at' => now(), 'updated_at' => now()],
                );
            } else {
                DB::table('webhooks')->where('user_id', $bot->id)->delete();
            }

            return $bot;
        });

        if ($this->avatar) {
            app(BlobStorage::class)->attachTo('User', $bot->id, 'avatar', $this->avatar);
        }

        return $this->redirectRoute('bots.index');
    }

    public function rotateKey(): void
    {
        $this->authorizeAdministrator();
        $this->bot()->update(['bot_token' => Str::random(12)]);
    }

    public function delete()
    {
        $this->authorizeAdministrator();
        $this->bot()->deactivate();

        return $this->redirectRoute('bots.index');
    }

    public function render()
    {
        $this->authorizeAdministrator();

        return view('livewire.bot-form', ['bot' => $this->botId === null ? null : $this->bot()]);
    }

    private function bot(): User
    {
        abort_if($this->botId === null, 404);

        return User::active()->where('role', 2)->findOrFail($this->botId);
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->role === 1, 403);
    }
}
