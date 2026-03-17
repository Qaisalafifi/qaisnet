<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('link_code')->unique()->nullable()->after('access_code')->comment('Code for shop owner to link network');
            $table->foreignId('network_owner_id')->nullable()->constrained('users')->nullOnDelete()->after('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign(['network_owner_id']);
            $table->dropColumn(['link_code', 'network_owner_id']);
        });
    }
};
