<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('mikrotik_user')->nullable()->after('user_id');
            $table->string('mikrotik_password')->nullable()->after('mikrotik_user');
            $table->string('mikrotik_profile')->default('default')->after('mikrotik_password');
            $table->enum('service_status', ['activo', 'suspendido', 'cortado'])
                  ->default('suspendido')
                  ->after('mikrotik_profile');

            $table->index('service_status');
            $table->index('mikrotik_user');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['service_status']);
            $table->dropIndex(['mikrotik_user']);
            $table->dropColumn([
                'mikrotik_user',
                'mikrotik_password',
                'mikrotik_profile',
                'service_status',
            ]);
        });
    }
};
