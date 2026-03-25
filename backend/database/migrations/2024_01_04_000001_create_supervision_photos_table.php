<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervision_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->timestamp('created_at')->useCurrent();

            $table->index('supervision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_photos');
    }
};
