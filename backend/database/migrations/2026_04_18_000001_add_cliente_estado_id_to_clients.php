<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Agregar columna nullable para transición gradual (no rompe datos actuales)
            $table->foreignId('cliente_estado_id')
                ->nullable()
                ->after('estado')
                ->constrained('cliente_estados')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_estado_id');
        });
    }
};
