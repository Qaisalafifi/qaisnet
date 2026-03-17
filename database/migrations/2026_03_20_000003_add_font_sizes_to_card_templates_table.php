<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('card_templates', 'code_font_size')) {
                $table->decimal('code_font_size', 6, 2)
                    ->default(12)
                    ->after('code_y_mm');
            }
            if (!Schema::hasColumn('card_templates', 'password_font_size')) {
                $table->decimal('password_font_size', 6, 2)
                    ->default(11)
                    ->after('password_y_mm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_templates', function (Blueprint $table) {
            if (Schema::hasColumn('card_templates', 'password_font_size')) {
                $table->dropColumn('password_font_size');
            }
            if (Schema::hasColumn('card_templates', 'code_font_size')) {
                $table->dropColumn('code_font_size');
            }
        });
    }
};
