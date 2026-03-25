<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientPhoto extends Model
{
    protected $fillable = ['client_id', 'photo_path', 'photo_type'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /* ─── Accessors ─────────────────────────────────────── */

    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        return $disk->url($this->photo_path);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
