<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_suspicious')->default(false)->after('service_status');
            $table->integer('risk_score')->default(0)->after('is_suspicious');

            $table->index('is_suspicious');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['is_suspicious']);
            $table->dropColumn(['is_suspicious', 'risk_score']);
        });
    }
};
