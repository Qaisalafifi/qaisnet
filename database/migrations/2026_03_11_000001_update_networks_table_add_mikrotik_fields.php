<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->after('name');
            $table->integer('api_port')->default(8728)->after('ip_address');
            $table->string('mikrotik_user')->nullable()->after('api_port');
            $table->text('mikrotik_password')->nullable()->after('mikrotik_user');
            $table->enum('subscription_type', ['monthly', 'yearly'])->nullable()->after('mikrotik_password');
            $table->timestamp('subscription_start_at')->nullable()->after('subscription_type');
            $table->timestamp('subscription_end_at')->nullable()->after('subscription_start_at');
            $table->enum('status', ['active', 'expired', 'suspended'])->default('active')->after('subscription_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn([
                'ip_address',
                'api_port',
                'mikrotik_user',
                'mikrotik_password',
                'subscription_type',
                'subscription_start_at',
                'subscription_end_at',
                'status',
            ]);
        });
    }
};
