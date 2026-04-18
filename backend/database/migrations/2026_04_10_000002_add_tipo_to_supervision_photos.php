<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('supervision_photos', 'tipo')) {
            return;
        }

        Schema::table('supervision_photos', function (Blueprint $table) {
            $table->string('tipo', 50)->default('general')->after('photo_path');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('supervision_photos', 'tipo')) {
            Schema::table('supervision_photos', function (Blueprint $table) {
                $table->dropColumn('tipo');
            });
        }
    }
};
