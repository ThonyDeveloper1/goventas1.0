<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('dni', 8);
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('telefono_1', 15);
            $table->string('telefono_2', 15)->nullable();
            $table->string('direccion');
            $table->string('referencia')->nullable();
            $table->string('departamento');
            $table->string('provincia');
            $table->string('distrito');
            $table->decimal('latitud',  10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->enum('estado', ['activo', 'moroso', 'suspendido', 'baja'])->default('activo');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('dni');
            $table->index('user_id');
            $table->index('estado');
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
