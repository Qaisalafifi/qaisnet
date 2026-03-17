<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'network_id')) {
                $table->foreignId('network_id')->nullable()->constrained('networks')->nullOnDelete()->after('card_id');
            }
            if (!Schema::hasColumn('sales', 'package_id')) {
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete()->after('network_id');
            }
            if (!Schema::hasColumn('sales', 'sold_by_user_id')) {
                $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('package_id');
            }
            if (Schema::hasColumn('sales', 'price')) {
                $table->decimal('price', 10, 2)->change();
            } elseif (Schema::hasColumn('sales', 'sold_price')) {
                $table->renameColumn('sold_price', 'price');
                $table->decimal('price', 10, 2)->change();
            }
            
            if (!Schema::hasColumn('sales', 'shop_id')) {
                // safety: only add indexes if expected columns exist
            } else {
                $table->index(['shop_id', 'sold_at']);
            }
            if (Schema::hasColumn('sales', 'network_id')) {
                $table->index(['network_id', 'sold_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'network_id')) {
                $table->dropForeign(['network_id']);
            }
            if (Schema::hasColumn('sales', 'package_id')) {
                $table->dropForeign(['package_id']);
            }
            if (Schema::hasColumn('sales', 'sold_by_user_id')) {
                $table->dropForeign(['sold_by_user_id']);
            }
            $table->dropIndex(['shop_id', 'sold_at']);
            $table->dropIndex(['network_id', 'sold_at']);
            
            $dropCols = [];
            foreach (['network_id', 'package_id', 'sold_by_user_id'] as $col) {
                if (Schema::hasColumn('sales', $col)) $dropCols[] = $col;
            }
            if (!empty($dropCols)) {
                $table->dropColumn($dropCols);
            }
            if (Schema::hasColumn('sales', 'price') && !Schema::hasColumn('sales', 'sold_price')) {
                $table->renameColumn('price', 'sold_price');
            }
        });
    }
};
