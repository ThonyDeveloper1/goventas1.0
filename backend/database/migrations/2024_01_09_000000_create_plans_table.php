<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->integer('velocidad_bajada');
            $table->integer('velocidad_subida');
            $table->decimal('precio', 8, 2);
            $table->text('condiciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('activo');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('user_id')
                  ->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
