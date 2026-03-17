<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('include_password')->default(false);
            $table->boolean('show_price')->default(true);
            $table->unsignedInteger('cards_per_page');
            $table->unsignedInteger('columns');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['network_id', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_templates');
    }
};
