<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supervision_estados')) {
            return;
        }

        Schema::create('supervision_estados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('color', 7)->default('#6B7280');
            $table->text('descripcion')->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('supervision_estados')->insert([
            ['nombre' => 'Pendiente',  'color' => '#EAB308', 'descripcion' => 'Supervisión asignada, pendiente de inicio', 'orden' => 1, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => 'En Proceso', 'color' => '#3B82F6', 'descripcion' => 'Supervisión en curso',                       'orden' => 2, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => 'Finalizado', 'color' => '#86EFAC', 'descripcion' => 'Supervisión finalizada, pendiente revisión',  'orden' => 3, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => 'Aprobado',   'color' => '#16A34A', 'descripcion' => 'Supervisión aprobada por el administrador',  'orden' => 4, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => 'Observado',  'color' => '#F97316', 'descripcion' => 'Requiere correcciones del técnico',          'orden' => 5, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => 'Rechazado',  'color' => '#EF4444', 'descripcion' => 'Supervisión rechazada',                      'orden' => 6, 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_estados');
    }
};
