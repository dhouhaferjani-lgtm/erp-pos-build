<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Mechanic (Otospex)
    |--------------------------------------------------------------------------
    */

    'mechanic' => [
        'name' => 'mechanic',
        'label' => 'Mechanic',
        'description' => 'Automotive repair and maintenance services',
        'product' => 'otospex',
        'compatible_extras' => ['Appointments', 'Fleet'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Vehicle',
            'Partner',
            'Workshop',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pharmacy (IziPOS)
    |--------------------------------------------------------------------------
    */

    'pharmacy' => [
        'name' => 'pharmacy',
        'label' => 'Pharmacy',
        'description' => 'Pharmaceutical retail with prescription management',
        'product' => 'izipos',
        'compatible_extras' => ['BatchExpiry', 'Prescription'],
        'product_defaults' => [
            'requires_batch_tracking' => true,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'BatchExpiry',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Restaurant (IziPOS)
    |--------------------------------------------------------------------------
    */

    'restaurant' => [
        'name' => 'restaurant',
        'label' => 'Restaurant',
        'description' => 'Full-service dining with table management',
        'product' => 'izipos',
        'compatible_extras' => ['Tables', 'Reservation', 'Inventory'],
        'product_defaults' => [
            'requires_batch_tracking' => true,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Menu',
            'Partner',
            'Sales',
            'Treasury',
            'Accounting',
            'Tables',
            'CompositeItems',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coffee Shop (IziPOS)
    |--------------------------------------------------------------------------
    */

    'coffee_shop' => [
        'name' => 'coffee_shop',
        'label' => 'Coffee Shop',
        'description' => 'Coffee shop and quick-service cafe',
        'product' => 'izipos',
        'compatible_extras' => ['Tables', 'Loyalty', 'Inventory'],
        'product_defaults' => [
            'requires_batch_tracking' => true,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Menu',
            'Partner',
            'Sales',
            'Treasury',
            'Accounting',
            'CompositeItems',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retail (IziPOS)
    |--------------------------------------------------------------------------
    */

    'retail' => [
        'name' => 'retail',
        'label' => 'Retail',
        'description' => 'General retail and merchandise',
        'product' => 'izipos',
        'compatible_extras' => ['Loyalty', 'Ecommerce'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fashion (IziPOS)
    |--------------------------------------------------------------------------
    */

    'fashion' => [
        'name' => 'fashion',
        'label' => 'Fashion',
        'description' => 'Fashion retail and boutiques',
        'product' => 'izipos',
        'compatible_extras' => ['Loyalty', 'Ecommerce'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Body Shop (Otospex)
    |--------------------------------------------------------------------------
    */

    'body_shop' => [
        'name' => 'body_shop',
        'label' => 'Body Shop',
        'description' => 'Automotive body repair and painting',
        'product' => 'otospex',
        'compatible_extras' => ['Appointments', 'Fleet'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Vehicle',
            'Partner',
            'Workshop',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Parts Retailer (Otospex)
    |--------------------------------------------------------------------------
    */

    'parts_retailer' => [
        'name' => 'parts_retailer',
        'label' => 'Parts Retailer',
        'description' => 'Automotive parts retail and wholesale',
        'product' => 'otospex',
        'compatible_extras' => ['Ecommerce'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Vehicle',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Car Glass (Otospex)
    |--------------------------------------------------------------------------
    */

    'car_glass' => [
        'name' => 'car_glass',
        'label' => 'Car Glass',
        'description' => 'Automotive glass replacement and repair',
        'product' => 'otospex',
        'compatible_extras' => ['Appointments', 'Fleet'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Vehicle',
            'Partner',
            'Workshop',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tire Shop (Otospex)
    |--------------------------------------------------------------------------
    */

    'tire_shop' => [
        'name' => 'tire_shop',
        'label' => 'Tire Shop',
        'description' => 'Tire sales and services',
        'product' => 'otospex',
        'compatible_extras' => ['Appointments'],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Vehicle',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Station (Otospex)
    |--------------------------------------------------------------------------
    */

    'service_station' => [
        'name' => 'service_station',
        'label' => 'Service Station',
        'description' => 'Fuel station and quick services',
        'product' => 'otospex',
        'compatible_extras' => [],
        'product_defaults' => [
            'requires_batch_tracking' => false,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
            'PlatformIntegration',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Parapharmacy (IziPOS)
    |--------------------------------------------------------------------------
    */

    'parapharmacy' => [
        'name' => 'parapharmacy',
        'label' => 'Parapharmacy',
        'description' => 'Health and wellness retail',
        'product' => 'izipos',
        'compatible_extras' => ['BatchExpiry', 'Loyalty'],
        'product_defaults' => [
            'requires_batch_tracking' => true,
        ],
        'default_modules' => [
            'Identity',
            'Tenant',
            'Catalog',
            'Partner',
            'Sales',
            'Inventory',
            'Treasury',
            'Accounting',
        ],
    ],
];
