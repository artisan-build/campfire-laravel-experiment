<?php

namespace App\Models;

use App\Support\RichTextRenderer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Message extends Record
{
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasOne<RichText, $this> */
    public function richText(): HasOne
    {
        return $this->hasOne(RichText::class, 'record_id')->where('record_type', 'Message')->where('name', 'body');
    }

    /** @return HasMany<Boost, $this> */
    public function boosts(): HasMany
    {
        return $this->hasMany(Boost::class);
    }

    /** @return HasOne<Attachment, $this> */
    public function attachment(): HasOne
    {
        return $this->hasOne(Attachment::class, 'record_id')->where('record_type', 'Message')->where('name', 'attachment');
    }

    public function scopePresentation($q)
    {
        return $q->with(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
    }

    public function plainText(): string
    {
        $plain = app(RichTextRenderer::class)->plain($this->richText?->body ?? '');

        return trim($plain) !== '' ? $plain : ($this->attachment?->blob?->filename ?? '');
    }
}
