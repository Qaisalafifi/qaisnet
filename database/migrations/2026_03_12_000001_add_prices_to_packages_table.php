<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (!Schema::hasColumn('packages', 'wholesale_price')) {
                $table->decimal('wholesale_price', 10, 2)->nullable()->after('price');
            }
            if (!Schema::hasColumn('packages', 'retail_price')) {
                $table->decimal('retail_price', 10, 2)->nullable()->after('wholesale_price');
            }
        });

        // Backfill from existing price for older data
        DB::table('packages')
            ->whereNull('retail_price')
            ->update(['retail_price' => DB::raw('price')]);
        DB::table('packages')
            ->whereNull('wholesale_price')
            ->update(['wholesale_price' => DB::raw('price')]);
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'retail_price')) {
                $table->dropColumn('retail_price');
            }
            if (Schema::hasColumn('packages', 'wholesale_price')) {
                $table->dropColumn('wholesale_price');
            }
        });
    }
};
