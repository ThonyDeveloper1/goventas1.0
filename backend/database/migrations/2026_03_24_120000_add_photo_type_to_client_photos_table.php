<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_photos', function (Blueprint $table) {
            $table->string('photo_type', 30)->default('fachada')->after('photo_path');
            $table->index('photo_type');
        });

        DB::table('client_photos')
            ->whereNull('photo_type')
            ->update(['photo_type' => 'fachada']);
    }

    public function down(): void
    {
        Schema::table('client_photos', function (Blueprint $table) {
            $table->dropIndex(['photo_type']);
            $table->dropColumn('photo_type');
        });
    }
};