<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->integer('count')->comment('Number of cards generated');
            $table->integer('card_length')->comment('Length of card code');
            $table->string('prefix')->nullable()->comment('Code prefix');
            $table->string('suffix')->nullable()->comment('Code suffix');
            $table->string('first_code')->nullable();
            $table->string('last_code')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            
            $table->index(['network_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_batches');
    }
};
