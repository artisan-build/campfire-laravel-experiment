<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID as WebPushVapid;

/**
 * Web Push keys with a working default.
 *
 * Upstream kept them in storage/vapid.json, which is instance-local: a second replica signs with a
 * different key and every subscription made against the first one breaks. They live on the single
 * accounts row instead, generated on first use, so no environment variable is required and every
 * instance signs with the same identity.
 */
final class Vapid
{
    public function keys(): ?array
    {
        $public = config('campfire.vapid.public_key');
        $private = config('campfire.vapid.private_key');

        if (is_string($public) && is_string($private) && $public !== '' && $private !== '') {
            return ['publicKey' => $public, 'privateKey' => $private];
        }

        return Cache::remember('campfire:vapid', 300, fn () => $this->stored());
    }

    public function publicKey(): string
    {
        return $this->keys()['publicKey'] ?? '';
    }

    private function stored(): ?array
    {
        $account = DB::table('accounts')->orderBy('id')->first();

        if (! $account) {
            return null;
        }

        $settings = json_decode($account->settings ?? '{}', true) ?: [];

        if (isset($settings['vapid']['publicKey'], $settings['vapid']['privateKey'])) {
            return $settings['vapid'];
        }

        $keys = WebPushVapid::createVapidKeys();
        $settings['vapid'] = ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];

        // Two instances booting at once would each generate a pair; the first write wins and the
        // loser re-reads it, so a subscription is never signed with a key nobody else holds.
        $affected = DB::table('accounts')
            ->where('id', $account->id)
            ->where(fn ($query) => $query->whereNull('settings')->orWhere('settings', 'not like', '%"vapid"%'))
            ->update(['settings' => json_encode($settings), 'updated_at' => now()]);

        if ($affected === 0) {
            $settings = json_decode(DB::table('accounts')->where('id', $account->id)->value('settings') ?? '{}', true) ?: [];

            return $settings['vapid'] ?? null;
        }

        return $settings['vapid'];
    }
}
