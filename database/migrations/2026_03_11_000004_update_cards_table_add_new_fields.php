<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // إزالة الحقول القديمة التي لا نحتاجها
            $columnsToDrop = [];
            foreach (['serial_number', 'category', 'data_amount', 'duration', 'price'] as $col) {
                if (Schema::hasColumn('cards', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
            
            // إضافة الحقول الجديدة
            if (!Schema::hasColumn('cards', 'package_id')) {
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete()->after('network_id');
            }
            if (!Schema::hasColumn('cards', 'code')) {
                $table->string('code')->unique()->after('package_id')->comment('Card username/code');
            }
            if (!Schema::hasColumn('cards', 'password')) {
                $table->string('password')->after('code')->comment('Card password');
            }
            if (!Schema::hasColumn('cards', 'generated_batch_id')) {
                $table->foreignId('generated_batch_id')->nullable()->constrained('card_batches')->nullOnDelete()->after('password');
            }
            if (Schema::hasColumn('cards', 'status')) {
                $table->enum('status', ['available', 'sold', 'expired', 'disabled'])->default('available')->change();
            }
        });

        // إضافة فهرس مركب لمنع تكرار الكروت في نفس الشبكة (إذا لم يكن موجوداً)
        $indexExists = DB::selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND INDEX_NAME = 'cards_network_code_unique' LIMIT 1"
        );
        if (!$indexExists) {
            $duplicateCount = DB::table('cards')
                ->select('network_id', 'code', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull('code')
                ->groupBy('network_id', 'code')
                ->having('cnt', '>', 1)
                ->count();

            if ($duplicateCount === 0) {
                Schema::table('cards', function (Blueprint $table) {
                    $table->unique(['network_id', 'code'], 'cards_network_code_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropForeign(['generated_batch_id']);
            $table->dropUnique('cards_network_code_unique');
            
            $table->dropColumn(['package_id', 'code', 'password', 'generated_batch_id']);
            
            // استعادة الحقول القديمة
            $table->string('serial_number')->unique();
            $table->decimal('category', 10, 2);
            $table->string('data_amount');
            $table->string('duration');
            $table->decimal('price', 10, 2);
        });
    }
};
