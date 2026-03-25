<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            if (!Schema::hasColumn('installations', 'duracion')) {
                $table->unsignedSmallInteger('duracion')->default(1)->after('hora_fin')->comment('Duración en horas (1 o 2)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            if (Schema::hasColumn('installations', 'duracion')) {
                $table->dropColumn('duracion');
            }
        });
    }
};
