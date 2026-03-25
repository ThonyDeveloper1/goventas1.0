<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_estado_check");

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_estado_check CHECK (estado IN ('pre_registro', 'finalizada', 'suspendido', 'baja', 'activo', 'moroso'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_estado_check");

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_estado_check CHECK (estado IN ('pre_registro', 'finalizada', 'suspendido', 'baja'))");
    }
};
