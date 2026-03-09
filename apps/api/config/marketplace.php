<?php

declare(strict_types=1);

return [
    'enabled' => env('MARKETPLACE_ENABLED', false),
    'commission' => [
        'default_rate' => (float) env('MARKETPLACE_COMMISSION_RATE', 5.0),
    ],
    'reservation' => [
        'cart_expiry_minutes' => (int) env('MARKETPLACE_CART_RESERVATION_MINUTES', 15),
        'order_expiry_hours' => (int) env('MARKETPLACE_ORDER_RESERVATION_HOURS', 24),
    ],
    'sync' => [
        'delta_interval_minutes' => (int) env('MARKETPLACE_SYNC_INTERVAL', 15),
        'reconciliation_hour' => (int) env('MARKETPLACE_RECONCILE_HOUR', 3),
    ],
    'cache' => [
        'listing_search_ttl' => (int) env('MARKETPLACE_CACHE_TTL', 300),
    ],
    'anti_abuse' => [
        'max_re_reserves_per_listing_per_day' => 3,
    ],
];
