<?php

namespace App\Models;

use App\Support\RailsCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class User extends Record
{
    protected $hidden = ['password_digest', 'bot_token'];

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'memberships');
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Laravel's presence-channel response asks the user model for its identifier. Campfire has its
     * own session table rather than an auth guard, so this is the only piece of the Authenticatable
     * contract it needs.
     */
    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function scopeActive($q)
    {
        return $q->where('status', 0);
    }

    public function canAdminister($record = null): bool
    {
        return $this->role === 1 || ($record && $record->creator_id === $this->id);
    }

    public function avatarToken(): string
    {
        return app(RailsCrypto::class)->signedId($this->id, 'User', 'avatar');
    }

    public function avatarUrl(): string
    {
        return '/users/'.$this->avatarToken().'/avatar?v='.$this->updated_at->format('YmdHis');
    }

    public function deactivate(): void
    {
        DB::transaction(function () {
            $this->memberships()->whereHas('room', fn ($query) => $query->where('type', '!=', 'Rooms::Direct'))->delete();
            foreach (['sessions', 'push_subscriptions', 'searches'] as $table) {
                DB::table($table)->where('user_id', $this->id)->delete();
            }
            $values = ['status' => 1];
            if ($this->email_address) {
                $values['email_address'] = str_replace('@', '-deactivated-'.Str::uuid().'@', $this->email_address);
            }
            $this->update($values);
        });
    }

    protected function casts(): array
    {
        return ['role' => 'integer', 'status' => 'integer'];
    }
}
