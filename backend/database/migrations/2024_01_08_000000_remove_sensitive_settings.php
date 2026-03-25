<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove sensitive credential rows that were mistakenly seeded into the
 * settings table.  Tokens and API keys must live in .env / config only.
 *
 * The remaining setting (reniec_enabled) is a non-sensitive boolean toggle
 * and is safe to store in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->whereIn('key', ['reniec_token', 'google_maps_key'])
            ->delete();
    }

    public function down(): void
    {
        // Re-insert empty placeholders if rolling back
        DB::table('settings')->insertOrIgnore([
            ['key' => 'reniec_token',    'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'google_maps_key', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
};
