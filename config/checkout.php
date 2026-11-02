<?php

return [
    'simulation' => [
        'enabled' => (bool) env('ORDER_SIMULATION_ENABLED', false),
        'shipping_fee' => (int) env('ORDER_SIMULATION_SHIPPING_FEE', 0),
    ],
];
