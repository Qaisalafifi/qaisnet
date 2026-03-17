<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Network;
use App\Models\Card;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NetworkSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('role', 'network_owner')->first();
        
        if (!$owner) {
            $owner = User::create([
                'name' => 'صاحب الشبكة التجريبية',
                'username' => 'owner',
                'email' => 'owner@qaisnet.com',
                'password' => \Illuminate\Support\Facades\Hash::make('owner123'),
                'role' => 'network_owner',
            ]);
        }

        // 1. Create Network 1
        $n1 = Network::updateOrCreate(
            ['linking_code' => 'AMAL2026'],
            [
                'name' => 'شبكة الأمل الجديدة',
                'owner_id' => $owner->id,
            ]
        );

        // 2. Create Network 2
        $n2 = Network::updateOrCreate(
            ['linking_code' => 'QAIS777'],
            [
                'name' => 'شبكة قيس الذهبية',
                'owner_id' => $owner->id,
            ]
        );

        // Add some cards to both
        foreach ([$n1, $n2] as $net) {
            for ($i = 0; $i < 10; $i++) {
                Card::create([
                    'network_id' => $net->id,
                    'serial_number' => Str::upper(Str::random(12)),
                    'category' => [200, 500, 1000][rand(0, 2)],
                    'data_amount' => [1, 2, 5][rand(0, 2)] . ' GB',
                    'duration' => ['يوم', 'أسبوع', 'شهر'][rand(0, 2)],
                    'price' => [200, 500, 1000][rand(0, 2)],
                    'status' => 'available',
                ]);
            }
        }
    }
}
