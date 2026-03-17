<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class FixSubStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->update([
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
            'subscription_type' => 'monthly'
        ]);
        
        $this->command->info('All users have been set to ACTIVE status.');
    }
}
