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
        DB::statement(<<<'SQL'
            UPDATE clients c
            SET cliente_estado_id = ce.id
            FROM cliente_estados ce
            WHERE c.estado = ce.nombre
              AND c.cliente_estado_id IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: no revertimos datos mapeados para evitar pérdida de referencia.
    }
};
