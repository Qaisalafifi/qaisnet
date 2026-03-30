<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_requests', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete()->after('requested_plan');
            }
            if (!Schema::hasColumn('subscription_requests', 'payment_method_id')) {
                $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete()->after('plan_id');
            }
            if (!Schema::hasColumn('subscription_requests', 'receipt_path')) {
                $table->string('receipt_path')->nullable()->after('payment_method_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_requests', 'receipt_path')) {
                $table->dropColumn('receipt_path');
            }
            if (Schema::hasColumn('subscription_requests', 'payment_method_id')) {
                $table->dropForeign(['payment_method_id']);
                $table->dropColumn('payment_method_id');
            }
            if (Schema::hasColumn('subscription_requests', 'plan_id')) {
                $table->dropForeign(['plan_id']);
                $table->dropColumn('plan_id');
            }
        });
    }
};
