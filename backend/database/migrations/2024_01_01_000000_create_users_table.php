<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'vendedora', 'supervisor'])->default('vendedora');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
