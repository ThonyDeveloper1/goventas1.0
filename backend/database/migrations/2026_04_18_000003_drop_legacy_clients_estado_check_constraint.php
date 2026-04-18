<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Permite transición a estados dinámicos en clients.estado.
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_estado_check');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_estado_check CHECK (estado IN ('pre_registro', 'en_proceso', 'finalizada', 'suspendido', 'baja'))");
    }
};
