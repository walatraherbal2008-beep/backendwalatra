<?php

return [
    'mode' => env('ORDER_PROCESSING_MODE', 'manual'),
    'free_shipping' => (bool) env('FREE_SHIPPING_ENABLED', true),
    'manual' => [
        'shipping_fee' => (int) env('MANUAL_ORDER_SHIPPING_FEE', 0),
        'payment_instructions' => env('MANUAL_PAYMENT_INSTRUCTIONS', 'Hubungi admin untuk petunjuk pembayaran. Pembayaran akan diverifikasi secara manual.'),
    ],
    'simulation' => [
        'enabled' => (bool) env('ORDER_SIMULATION_ENABLED', false),
        'shipping_fee' => (int) env('ORDER_SIMULATION_SHIPPING_FEE', 0),
    ],
];
