<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FixCredentialsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Force fix Network Owner
        $owner = User::updateOrCreate(
            ['email' => 'owner@qaisnet.com'],
            [
                'name'      => 'صاحب الشبكة التجريبية',
                'username'  => 'owner',
                'password'  => Hash::make('owner123'),
                'role'      => 'network_owner',
            ]
        );

        // 2. Force fix Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@qaisnet.com'],
            [
                'name'      => 'System Admin',
                'username'  => 'admin',
                'password'  => Hash::make('admin123'),
                'role'      => 'admin',
            ]
        );
    }
}
