<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value with optional default.
     * Values are cached 10 minutes to avoid repeated DB hits.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return cache()->remember("setting:{$key}", 600, function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    /**
     * Set a setting value and clear its cache.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        cache()->forget("setting:{$key}");
    }
}
