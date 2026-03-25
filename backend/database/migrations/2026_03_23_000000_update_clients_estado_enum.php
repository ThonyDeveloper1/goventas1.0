<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing constraint from enum
        DB::statement("ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_estado_check");
        
        // Convert estado from enum to varchar to allow 'pre_registro' and 'finalizada'
        DB::statement("ALTER TABLE clients ALTER COLUMN estado TYPE varchar(50)");
        
        // Add a new check constraint to validate the values
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_estado_check CHECK (estado IN ('pre_registro', 'finalizada', 'suspendido', 'baja'))");
    }

    public function down(): void
    {
        // Remove the check constraint
        DB::statement("ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_estado_check");
    }
};
