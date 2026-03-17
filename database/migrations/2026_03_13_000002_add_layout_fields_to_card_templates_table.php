<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('card_templates', 'image_path')) {
                $table->string('image_path')->nullable()->after('name');
            }
            if (!Schema::hasColumn('card_templates', 'code_x_mm')) {
                $table->decimal('code_x_mm', 8, 2)->default(0)->after('image_path');
            }
            if (!Schema::hasColumn('card_templates', 'code_y_mm')) {
                $table->decimal('code_y_mm', 8, 2)->default(0)->after('code_x_mm');
            }
            if (!Schema::hasColumn('card_templates', 'password_x_mm')) {
                $table->decimal('password_x_mm', 8, 2)->nullable()->after('code_y_mm');
            }
            if (!Schema::hasColumn('card_templates', 'password_y_mm')) {
                $table->decimal('password_y_mm', 8, 2)->nullable()->after('password_x_mm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            $columns = [];
            foreach (['image_path', 'code_x_mm', 'code_y_mm', 'password_x_mm', 'password_y_mm'] as $col) {
                if (Schema::hasColumn('card_templates', $col)) {
                    $columns[] = $col;
                }
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
