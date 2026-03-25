<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupervisionPhoto extends Model
{
    public $timestamps = false;

    protected $fillable = ['supervision_id', 'photo_path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function supervision(): BelongsTo
    {
        return $this->belongsTo(Supervision::class);
    }

    /* ─── Accessors ─────────────────────────────────────── */

    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        return $disk->url($this->photo_path);
    }
}
