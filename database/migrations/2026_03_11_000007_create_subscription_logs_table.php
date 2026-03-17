<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->timestamp('old_end_at')->nullable();
            $table->timestamp('new_end_at')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamps();
            
            $table->index('network_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_logs');
    }
};
