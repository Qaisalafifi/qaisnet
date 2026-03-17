<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // System Admin
        User::firstOrCreate(
            ['email' => 'admin@qaisnet.com'],
            [
                'name'     => 'System Admin',
                'password' => Hash::make('admin123'),
                'role'     => 'admin',
            ]
        );

        // Demo Network Owner
        User::firstOrCreate(
            ['email' => 'owner@qaisnet.com'],
            [
                'name'     => 'صاحب الشبكة',
                'password' => Hash::make('owner123'),
                'role'     => 'network_owner',
            ]
        );
    }
}
