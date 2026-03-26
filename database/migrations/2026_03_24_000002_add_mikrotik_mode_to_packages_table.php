<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (!Schema::hasColumn('packages', 'mikrotik_mode')) {
                $table->string('mikrotik_mode', 20)
                    ->default('hotspot')
                    ->after('mikrotik_profile_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'mikrotik_mode')) {
                $table->dropColumn('mikrotik_mode');
            }
        });
    }
};
