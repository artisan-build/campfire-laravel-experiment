<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Attachment extends Record
{
    protected $table = 'active_storage_attachments';

    public $timestamps = false;

    /** @return BelongsTo<Blob, $this> */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(Blob::class);
    }
}
