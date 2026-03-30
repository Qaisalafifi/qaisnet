<?php

return [
    'trial' => [
        'label' => 'نسخة تجريبية',
        'limits' => [
            'card_generation_max' => 100,
            // Allow a single network during trial.
            'networks_max' => 1,
        ],
        'features' => [
            'add_network' => true,
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
