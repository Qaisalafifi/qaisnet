<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('data_limit')->comment('e.g. 5GB, 10GB, unlimited');
            $table->integer('validity_days')->comment('Number of days the package is valid');
            $table->string('mikrotik_profile_name')->comment('Profile name in MikroTik');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            
            $table->index(['network_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
