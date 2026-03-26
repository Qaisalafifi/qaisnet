<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (!Schema::hasColumn('networks', 'mikrotik_mode')) {
                $table->string('mikrotik_mode', 20)
                    ->default('hotspot')
                    ->after('mikrotik_password');
            }
            if (!Schema::hasColumn('networks', 'user_manager_customer')) {
                $table->string('user_manager_customer')
                    ->nullable()
                    ->after('mikrotik_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (Schema::hasColumn('networks', 'user_manager_customer')) {
                $table->dropColumn('user_manager_customer');
            }
            if (Schema::hasColumn('networks', 'mikrotik_mode')) {
                $table->dropColumn('mikrotik_mode');
            }
        });
    }
};
