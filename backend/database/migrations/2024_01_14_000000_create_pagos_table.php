<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->decimal('monto', 10, 2);
            $table->date('fecha_pago');
            $table->string('metodo_pago', 50);          // efectivo, yape, plin, transferencia
            $table->string('comprobante')->nullable();   // receipt number / reference
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // who registered
            $table->timestamps();

            $table->index('client_id');
            $table->index('fecha_pago');
            $table->index(['client_id', 'fecha_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
