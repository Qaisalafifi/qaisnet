<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            if (!Schema::hasColumn('cards', 'serial_number')) {
                $table->string('serial_number')->nullable()->after('assigned_shop_id');
            }
            if (!Schema::hasColumn('cards', 'category')) {
                $table->decimal('category', 10, 2)->nullable()->after('serial_number');
            }
            if (!Schema::hasColumn('cards', 'data_amount')) {
                $table->string('data_amount')->nullable()->after('category');
            }
            if (!Schema::hasColumn('cards', 'duration')) {
                $table->string('duration')->nullable()->after('data_amount');
            }
            if (!Schema::hasColumn('cards', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('duration');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            if (Schema::hasColumn('cards', 'price')) {
                $table->dropColumn('price');
            }
            if (Schema::hasColumn('cards', 'duration')) {
                $table->dropColumn('duration');
            }
            if (Schema::hasColumn('cards', 'data_amount')) {
                $table->dropColumn('data_amount');
            }
            if (Schema::hasColumn('cards', 'category')) {
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('cards', 'serial_number')) {
                $table->dropColumn('serial_number');
            }
        });
    }
};
