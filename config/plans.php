<?php

return [
    'trial' => [
        'label' => 'نسخة تجريبية',
        'limits' => [
            'card_generation_max' => 100,
            // Set to 0 to block adding networks entirely, or 1 to allow a single network.
            'networks_max' => 0,
        ],
        'features' => [
            'add_network' => false,
            'add_shop' => false,
            'assign_cards' => false,
            'active_connections' => true,
            'connected_devices' => true,
            'port_stats' => false,
        ],
    ],
    'paid' => [
        'label' => 'مشترك',
        'limits' => [
            'card_generation_max' => 1000,
            'networks_max' => null, // unlimited
        ],
        'features' => [
            'add_network' => true,
            'add_shop' => true,
            'assign_cards' => true,
            'active_connections' => true,
            'connected_devices' => true,
            'port_stats' => true,
        ],
    ],
];
