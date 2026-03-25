<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->restrictOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->restrictOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->enum('estado', ['pendiente', 'en_proceso', 'completado'])->default('pendiente');
            $table->text('notas')->nullable();
            $table->timestamps();

            // Performance indexes
            $table->index('fecha');
            $table->index('client_id');
            $table->index('user_id');
            $table->index(['fecha', 'hora_inicio', 'hora_fin']); // conflict queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
