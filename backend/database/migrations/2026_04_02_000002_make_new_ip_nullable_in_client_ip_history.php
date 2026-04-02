<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_ip_history', function (Blueprint $table) {
            $table->string('new_ip', 45)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_ip_history', function (Blueprint $table) {
            $table->string('new_ip', 45)->nullable(false)->change();
        });
    }
};
