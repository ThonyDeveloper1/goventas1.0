<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['pendiente', 'en_proceso', 'completado'])->default('pendiente');
            $table->text('comentario')->nullable();
            $table->timestamps();

            $table->index('supervisor_id');
            $table->index('estado');
            $table->index(['supervisor_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisions');
    }
};
