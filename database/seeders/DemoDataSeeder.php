<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Network;
use App\Models\Shop;
use App\Models\Card;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'owner@qaisnet.com')->first();
        if (!$owner) return;

        $network = Network::create([
            'name' => 'شبكة قيس',
            'owner_id' => $owner->id,
        ]);

        $shop = Shop::create([
            'name' => 'بقالة الأمل',
            'owner_id' => $owner->id,
            'network_id' => $network->id,
            'access_code' => 'SHOP123',
            'is_active' => true,
        ]);

        // Create some cards
        for ($i = 0; $i < 20; $i++) {
            $code = Str::upper(Str::random(10));
            Card::create([
                'network_id' => $network->id,
                'assigned_shop_id' => $shop->id,
                'code' => $code,
                'password' => $code,
                'serial_number' => 'SN-' . $code,
                'category' => [200, 500, 1000][rand(0, 2)],
                'data_amount' => rand(1, 10) . ' GB',
                'duration' => rand(1, 30) . ' days',
                'price' => [200, 500, 1000][rand(0, 2)],
                'status' => 'available',
            ]);
        }
    }
}
