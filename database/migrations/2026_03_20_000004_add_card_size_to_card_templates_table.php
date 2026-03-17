<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('card_templates', 'card_width_mm')) {
                $table->decimal('card_width_mm', 8, 2)
                    ->nullable()
                    ->after('password_y_mm');
            }
            if (!Schema::hasColumn('card_templates', 'card_height_mm')) {
                $table->decimal('card_height_mm', 8, 2)
                    ->nullable()
                    ->after('card_width_mm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            if (Schema::hasColumn('card_templates', 'card_height_mm')) {
                $table->dropColumn('card_height_mm');
            }
            if (Schema::hasColumn('card_templates', 'card_width_mm')) {
                $table->dropColumn('card_width_mm');
            }
        });
    }
};
