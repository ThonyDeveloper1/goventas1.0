<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default keys
        DB::table('settings')->insert([
            ['key' => 'reniec_token',    'value' => '',  'created_at' => now(), 'updated_at' => now()],
            ['key' => 'reniec_enabled',  'value' => '0', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'google_maps_key', 'value' => '',  'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
