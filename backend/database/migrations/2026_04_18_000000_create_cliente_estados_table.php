<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cliente_estados', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('color')->comment('Formato hexadecimal: #RRGGBB');
            $table->text('descripcion')->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->boolean('sistema_protegido')->default(false)->comment('Si es true, no se puede editar ni eliminar');
            $table->timestamps();
        });

        DB::table('cliente_estados')->insert([
            [
                'nombre' => 'pre_registro',
                'color' => '#9CA3AF',
                'descripcion' => 'Cliente registrado pero sin instalación planificada',
                'orden' => 1,
                'activo' => true,
                'sistema_protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'en_proceso',
                'color' => '#3B82F6',
                'descripcion' => 'Instalación en proceso',
                'orden' => 2,
                'activo' => true,
                'sistema_protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'finalizada',
                'color' => '#10B981',
                'descripcion' => 'Instalación completada',
                'orden' => 3,
                'activo' => true,
                'sistema_protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'suspendido',
                'color' => '#F59E0B',
                'descripcion' => 'Cliente suspendido temporalmente',
                'orden' => 4,
                'activo' => true,
                'sistema_protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'baja',
                'color' => '#EF4444',
                'descripcion' => 'Cliente dado de baja',
                'orden' => 5,
                'activo' => true,
                'sistema_protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cliente_estados');
    }
};
