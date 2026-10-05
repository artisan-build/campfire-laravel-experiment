<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Boost extends Record
{
    /** @return BelongsTo<User, $this> */
    public function booster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booster_id');
    }
}
