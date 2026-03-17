<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignId('assigned_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->string('serial_number')->unique();
            $table->decimal('category', 10, 2)->comment('Price category e.g. 200, 500, 1000');
            $table->string('data_amount')->comment('e.g. 5GB, 10GB, 500MB');
            $table->string('duration')->comment('e.g. 1 day, 7 days, 30 days');
            $table->decimal('price', 10, 2)->comment('Selling price set by network owner');
            $table->enum('status', ['available', 'sold'])->default('available');
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
