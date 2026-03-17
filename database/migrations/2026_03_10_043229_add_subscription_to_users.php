<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_status')->default('active')->after('role'); // active, inactive, expired
            $table->dateTime('subscription_ends_at')->nullable()->after('subscription_status');
            $table->string('subscription_type')->nullable()->after('subscription_ends_at'); // monthly, yearly
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'subscription_ends_at', 'subscription_type']);
        });
    }
};
