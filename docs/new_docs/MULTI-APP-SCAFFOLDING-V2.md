# Multi-App Architecture & Vertical System - Complete Specification

**Version:** 2.0  
**Last Updated:** December 30, 2025  
**Status:** Ready for Implementation  
**Estimated Effort:** 3-4 weeks

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [Product Configuration](#3-product-configuration)
4. [Vertical System](#4-vertical-system)
5. [Vertical Configurations (Complete)](#5-vertical-configurations-complete)
6. [Vertical Inheritance](#6-vertical-inheritance)
7. [Feature Override System](#7-feature-override-system)
8. [Database Schema](#8-database-schema)
9. [Backend Implementation](#9-backend-implementation)
10. [Frontend Implementation](#10-frontend-implementation)
11. [Signup & Deep-Link System](#11-signup--deep-link-system)
12. [Marketing & Campaign System](#12-marketing--campaign-system)
13. [Security](#13-security)
14. [Performance & Caching](#14-performance--caching)
15. [Migration Strategy](#15-migration-strategy)
16. [Testing Requirements](#16-testing-requirements)
17. [Implementation Guide for Claude Code](#17-implementation-guide-for-claude-code)
18. [Reference Documentation Template](#18-reference-documentation-template)

---

## 1. Executive Summary

### What We're Building

A multi-product, multi-vertical ERP platform from a single codebase:

| Product | Domain | Target Market | Verticals |
|---------|--------|---------------|-----------|
| **Otospex** | app.otospex.com | Automotive businesses | 6 verticals |
| **IziPOS** | app.izipos.com | Retail & F&B businesses | 6 verticals |

### Key Principles

1. **Single codebase** - One Docker image serves both products
2. **Vertical-first** - Business type determines entire experience
3. **Customized POS** - Each vertical has tailored POS interface
4. **Specialized databases** - Verticals connect to relevant catalogs (TecDoc, pharmacy DB, etc.)
5. **Deep-link signup** - Marketing can link directly to any vertical
6. **Extensible** - New verticals can inherit from existing ones

### What Each Vertical Controls

- Product branding (Otospex vs IziPOS)
- Core modules enabled by default
- POS interface variant
- Specialized database connections
- Onboarding flow
- Default settings
- Compatible feature extras

---

## 2. Architecture Overview

### Deployment Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         MARKETING LAYER                                  │
├─────────────────────────────────────────────────────────────────────────┤
│  Landing Pages & Mini-Sites                                              │
│                                                                          │
│  otospex.com/garage     →  app.otospex.com/signup?vertical=mechanic     │
│  izipos.com/pharmacie   →  app.izipos.com/signup?vertical=pharmacy      │
│  garagiste.tn           →  app.otospex.com/signup?vertical=mechanic     │
│                              &locale=fr-TN&utm_source=minisite          │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                       APPLICATION LAYER                                  │
├──────────────────────────────┬──────────────────────────────────────────┤
│      app.izipos.com          │         app.otospex.com                  │
│      ─────────────           │         ───────────────                  │
│      APP_PRODUCT=izipos      │         APP_PRODUCT=otospex              │
│      Blue theme              │         Orange theme                     │
│                              │                                          │
│      Verticals:              │         Verticals:                       │
│      • pharmacy              │         • mechanic                       │
│      • parapharmacy          │         • body_shop                      │
│      • restaurant            │         • parts_retailer                 │
│      • coffee_shop           │         • car_glass                      │
│      • retail                │         • tire_shop                      │
│      • fashion               │         • service_station                │
└──────────────────────────────┴──────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                          API LAYER                                       │
├──────────────────────────────┬──────────────────────────────────────────┤
│      api.izipos.com          │         api.otospex.com                  │
│                              │                                          │
│      Same Docker image       │         Same Docker image                │
│      Same codebase           │         Same codebase                    │
│      Tenant isolation (RLS)  │         Tenant isolation (RLS)           │
└──────────────────────────────┴──────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        DATABASE LAYER                                    │
├─────────────────────────────────────────────────────────────────────────┤
│  PostgreSQL Cluster (Multi-tenant with RLS)                             │
│                                                                          │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐         │
│  │  Core Tables    │  │ Specialized DBs │  │  Tenant Data    │         │
│  │  (shared)       │  │  (read-only)    │  │  (isolated)     │         │
│  │                 │  │                 │  │                 │         │
│  │  • products     │  │  • techdoc      │  │  • companies    │         │
│  │  • partners     │  │  • vin_decoder  │  │  • documents    │         │
│  │  • documents    │  │  • parapharmacy │  │  • inventory    │         │
│  │  • inventory    │  │  • cosmetics    │  │  • transactions │         │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Module Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          MODULE LAYERS                                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  UNIVERSAL MODULES (All verticals)                                       │
│  ─────────────────────────────────                                       │
│  Identity │ Company │ Product │ Partner │ Document │ Inventory          │
│  Treasury │ Accounting │ Communication │ Media │ Settings               │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  POS MODULE (Different variants per vertical)                            │
│  ────────────────────────────────────────────                            │
│  • Standard POS (retail)                                                 │
│  • Pharmacy POS (batch selection, expiry)                               │
│  • Restaurant POS (tables, courses, split bill)                         │
│  • Quick Service POS (modifiers, combos)                                │
│  • Workshop POS (vehicle lookup, work orders)                           │
│  • Parts Counter POS (compatibility check)                              │
│  • Glass Specialist POS (ADAS calibration)                              │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  VERTICAL-SPECIFIC MODULES                                               │
│  ─────────────────────────────                                           │
│                                                                          │
│  Otospex Only:              │  IziPOS Only:                             │
│  • Vehicle                  │  • Menu                                   │
│  • Workshop                 │  • Recipe                                 │
│                             │  • Tables                                 │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  OPTIONAL MODULES (Compatible extras per vertical)                       │
│  ─────────────────────────────────────────────────                       │
│  BatchExpiry │ Appointments │ Reservations │ Loyalty │ Fleet            │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  COMPLIANCE MODULES (Country-specific)                                   │
│  ─────────────────────────────────────                                   │
│  TN_EInvoice │ TN_Withholding │ FR_NF525 │ DE_TSE │ SA_ZATCA            │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Product Configuration

### Environment Variables

```env
# .env for IziPOS deployment
APP_PRODUCT=izipos
APP_NAME="IziPOS"
APP_URL=https://app.izipos.com
API_URL=https://api.izipos.com

# .env for Otospex deployment
APP_PRODUCT=otospex
APP_NAME="Otospex"
APP_URL=https://app.otospex.com
API_URL=https://api.otospex.com
```

### Product Configuration File

```php
// config/products.php

return [
    'izipos' => [
        'name' => 'IziPOS',
        'tagline' => 'Smart POS & ERP for Modern Business',
        'legal_name' => 'IziPOS SAS',
        
        // Branding
        'theme' => 'blue',
        'primary_color' => '#2563eb',
        'logo' => 'izipos-logo.svg',
        'favicon' => 'izipos-favicon.ico',
        
        // Domains
        'app_domain' => 'app.izipos.com',
        'api_domain' => 'api.izipos.com',
        'landing_domain' => 'izipos.com',
        
        // Contact
        'support_email' => 'support@izipos.com',
        'sales_email' => 'sales@izipos.com',
        
        // Defaults
        'default_vertical' => 'retail',
        'default_locale' => 'fr',
        'default_currency' => 'EUR',
        'default_timezone' => 'Europe/Paris',
        
        // Available verticals for this product
        'verticals' => [
            'pharmacy',
            'parapharmacy', 
            'restaurant',
            'coffee_shop',
            'retail',
            'fashion',
        ],
        
        // Modules hidden for this product
        'hidden_modules' => [
            'Vehicle',
            'Workshop',
        ],
    ],
    
    'otospex' => [
        'name' => 'Otospex',
        'tagline' => 'Complete Automotive Business Solution',
        'legal_name' => 'Otospex SARL',
        
        // Branding
        'theme' => 'orange',
        'primary_color' => '#ea580c',
        'logo' => 'otospex-logo.svg',
        'favicon' => 'otospex-favicon.ico',
        
        // Domains
        'app_domain' => 'app.otospex.com',
        'api_domain' => 'api.otospex.com',
        'landing_domain' => 'otospex.com',
        
        // Contact
        'support_email' => 'support@otospex.com',
        'sales_email' => 'sales@otospex.com',
        
        // Defaults
        'default_vertical' => 'mechanic',
        'default_locale' => 'fr',
        'default_currency' => 'TND',
        'default_timezone' => 'Africa/Tunis',
        
        // Available verticals for this product
        'verticals' => [
            'mechanic',
            'body_shop',
            'parts_retailer',
            'car_glass',
            'tire_shop',
            'service_station',
        ],
        
        // Modules hidden for this product
        'hidden_modules' => [
            'Menu',
            'Recipe',
            'Tables',
            'Reservations',
        ],
    ],
];
```

### Product Service

```php
// app/Services/ProductService.php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ProductService
{
    private ?string $currentProduct = null;
    
    public function current(): string
    {
        if ($this->currentProduct === null) {
            $this->currentProduct = config('app.product', 'izipos');
        }
        return $this->currentProduct;
    }
    
    public function config(?string $key = null): mixed
    {
        $product = $this->current();
        $config = config("products.{$product}");
        
        if ($key === null) {
            return $config;
        }
        
        return data_get($config, $key);
    }
    
    public function isIziPOS(): bool
    {
        return $this->current() === 'izipos';
    }
    
    public function isOtospex(): bool
    {
        return $this->current() === 'otospex';
    }
    
    public function availableVerticals(): array
    {
        return $this->config('verticals');
    }
    
    public function isVerticalAvailable(string $vertical): bool
    {
        return in_array($vertical, $this->availableVerticals());
    }
    
    public function isModuleHidden(string $module): bool
    {
        return in_array($module, $this->config('hidden_modules', []));
    }
    
    public function getOtherProductUrl(string $path = ''): string
    {
        $otherProduct = $this->isIziPOS() ? 'otospex' : 'izipos';
        $domain = config("products.{$otherProduct}.app_domain");
        return "https://{$domain}{$path}";
    }
}
```

---

## 4. Vertical System

### Vertical Enum

```php
// app/Enums/Vertical.php

namespace App\Enums;

enum Vertical: string
{
    // ═══════════════════════════════════════════
    // OTOSPEX VERTICALS
    // ═══════════════════════════════════════════
    case MECHANIC = 'mechanic';
    case BODY_SHOP = 'body_shop';
    case PARTS_RETAILER = 'parts_retailer';
    case CAR_GLASS = 'car_glass';
    case TIRE_SHOP = 'tire_shop';
    case SERVICE_STATION = 'service_station';
    
    // ═══════════════════════════════════════════
    // IZIPOS VERTICALS
    // ═══════════════════════════════════════════
    case PHARMACY = 'pharmacy';
    case PARAPHARMACY = 'parapharmacy';
    case RESTAURANT = 'restaurant';
    case COFFEE_SHOP = 'coffee_shop';
    case RETAIL = 'retail';
    case FASHION = 'fashion';
    
    /**
     * Get the product this vertical belongs to
     */
    public function product(): Product
    {
        return match($this) {
            self::MECHANIC,
            self::BODY_SHOP,
            self::PARTS_RETAILER,
            self::CAR_GLASS,
            self::TIRE_SHOP,
            self::SERVICE_STATION => Product::OTOSPEX,
            
            self::PHARMACY,
            self::PARAPHARMACY,
            self::RESTAURANT,
            self::COFFEE_SHOP,
            self::RETAIL,
            self::FASHION => Product::IZIPOS,
        };
    }
    
    /**
     * Human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::MECHANIC => 'Mechanic / Garage',
            self::BODY_SHOP => 'Body Shop',
            self::PARTS_RETAILER => 'Auto Parts Retailer',
            self::CAR_GLASS => 'Car Glass Specialist',
            self::TIRE_SHOP => 'Tire Shop',
            self::SERVICE_STATION => 'Service Station',
            self::PHARMACY => 'Pharmacy',
            self::PARAPHARMACY => 'Parapharmacy / Cosmetics',
            self::RESTAURANT => 'Restaurant',
            self::COFFEE_SHOP => 'Coffee Shop / Café',
            self::RETAIL => 'General Retail',
            self::FASHION => 'Fashion / Boutique',
        };
    }
    
    /**
     * Translated label
     */
    public function translatedLabel(): string
    {
        return __("verticals.{$this->value}");
    }
    
    /**
     * Icon name (Lucide icons)
     */
    public function icon(): string
    {
        return match($this) {
            self::MECHANIC => 'wrench',
            self::BODY_SHOP => 'spray-can',
            self::PARTS_RETAILER => 'cog',
            self::CAR_GLASS => 'square',
            self::TIRE_SHOP => 'circle',
            self::SERVICE_STATION => 'fuel',
            self::PHARMACY => 'pill',
            self::PARAPHARMACY => 'sparkles',
            self::RESTAURANT => 'utensils',
            self::COFFEE_SHOP => 'coffee',
            self::RETAIL => 'shopping-bag',
            self::FASHION => 'shirt',
        };
    }
    
    /**
     * Short description for signup
     */
    public function description(): string
    {
        return match($this) {
            self::MECHANIC => 'Repairs, maintenance, and service',
            self::BODY_SHOP => 'Collision repair and painting',
            self::PARTS_RETAILER => 'Auto parts sales and distribution',
            self::CAR_GLASS => 'Windshield and window replacement',
            self::TIRE_SHOP => 'Tire sales, mounting, and storage',
            self::SERVICE_STATION => 'Quick service and car wash',
            self::PHARMACY => 'Prescription and OTC medications',
            self::PARAPHARMACY => 'Beauty, cosmetics, and wellness',
            self::RESTAURANT => 'Full-service dining',
            self::COFFEE_SHOP => 'Coffee, beverages, and quick bites',
            self::RETAIL => 'General merchandise',
            self::FASHION => 'Clothing and accessories',
        };
    }
    
    /**
     * Get all verticals for a product
     */
    public static function forProduct(Product $product): array
    {
        return array_filter(
            self::cases(),
            fn(self $v) => $v->product() === $product
        );
    }
}
```

### Product Enum

```php
// app/Enums/Product.php

namespace App\Enums;

enum Product: string
{
    case IZIPOS = 'izipos';
    case OTOSPEX = 'otospex';
    
    public function displayName(): string
    {
        return match($this) {
            self::IZIPOS => 'IziPOS',
            self::OTOSPEX => 'Otospex',
        };
    }
    
    public function theme(): string
    {
        return match($this) {
            self::IZIPOS => 'blue',
            self::OTOSPEX => 'orange',
        };
    }
}
```

---

## 5. Vertical Configurations (Complete)

```php
// config/verticals.php

return [
    
    // ═══════════════════════════════════════════════════════════════════════
    //                          OTOSPEX VERTICALS
    // ═══════════════════════════════════════════════════════════════════════
    
    'mechanic' => [
        'product' => 'otospex',
        'label' => 'Mechanic / Garage',
        'description' => 'Repairs, maintenance, and service',
        'icon' => 'wrench',
        
        // ─────────────────────────────────────────────────────────────────
        // MODULES
        // ─────────────────────────────────────────────────────────────────
        'core_modules' => [
            'Identity',
            'Company',
            'Settings',
            'Product',
            'Partner',
            'Document',
            'Inventory',
            'Treasury',
            'Accounting',
            'Communication',
            'Media',
            'Vehicle',
            'Workshop',
            'POS',
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // POS CONFIGURATION
        // ─────────────────────────────────────────────────────────────────
        'pos_variant' => 'workshop',
        'pos_features' => [
            'vehicle_lookup' => true,
            'work_order_integration' => true,
            'labor_tracking' => true,
            'parts_from_work_order' => true,
            'customer_vehicle_history' => true,
            'quick_service_items' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'select_vehicle',
            'show_work_order_panel' => true,
            'show_vehicle_info' => true,
            'show_service_history' => true,
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // SPECIALIZED DATABASES
        // ─────────────────────────────────────────────────────────────────
        'databases' => [
            'techdoc' => [
                'enabled' => true,
                'connection' => 'techdoc_readonly',
                'description' => 'Auto parts catalog with compatibility',
            ],
            'vin_decoder' => [
                'enabled' => true,
                'provider' => 'tunisia_vin',  // or 'nhtsa' for US
                'description' => 'Vehicle identification decoder',
            ],
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // COMPATIBLE EXTRAS (Can be enabled)
        // ─────────────────────────────────────────────────────────────────
        'compatible_extras' => [
            'appointments' => [
                'label' => 'Appointment Scheduling',
                'description' => 'Online booking and calendar management',
                'module' => 'Appointments',
            ],
            'batch_tracking' => [
                'label' => 'Batch & Expiry Tracking',
                'description' => 'Track oils, fluids, and consumables with expiry dates',
                'module' => 'BatchExpiry',
            ],
            'loyalty_program' => [
                'label' => 'Loyalty Program',
                'description' => 'Points, rewards, and customer retention',
                'module' => 'Loyalty',
            ],
            'multi_location' => [
                'label' => 'Multi-Location',
                'description' => 'Manage multiple branches',
                'module' => null,  // Core feature, not a module
            ],
            'fleet_management' => [
                'label' => 'Fleet Management',
                'description' => 'Manage commercial fleet accounts',
                'module' => 'Fleet',
            ],
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // INCOMPATIBLE (Cannot be enabled - makes no sense)
        // ─────────────────────────────────────────────────────────────────
        'incompatible' => ['menu', 'recipe', 'tables', 'reservations'],
        
        // ─────────────────────────────────────────────────────────────────
        // ONBOARDING
        // ─────────────────────────────────────────────────────────────────
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'setup_workshop_bays', 'title' => 'Set Up Workshop Bays', 'required' => false],
            ['key' => 'import_labor_rates', 'title' => 'Import Labor Rates', 'required' => false],
            ['key' => 'configure_work_order_templates', 'title' => 'Work Order Templates', 'required' => false],
            ['key' => 'import_products', 'title' => 'Import Parts Catalog', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS Terminal', 'required' => false],
            ['key' => 'invite_team', 'title' => 'Invite Team Members', 'required' => false],
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // DEFAULT SETTINGS
        // ─────────────────────────────────────────────────────────────────
        'defaults' => [
            'tax_included_in_price' => false,  // B2B typically tax-exclusive
            'require_vehicle_for_sale' => false,
            'auto_create_work_order' => true,
            'default_payment_terms' => 30,  // Days
            'invoice_prefix' => 'FAC',
            'work_order_prefix' => 'OT',
        ],
        
        // ─────────────────────────────────────────────────────────────────
        // DOCUMENT TYPES ENABLED
        // ─────────────────────────────────────────────────────────────────
        'document_types' => [
            'quote',
            'work_order',
            'delivery_note',
            'invoice',
            'credit_note',
            'receipt',
        ],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'body_shop' => [
        'product' => 'otospex',
        'label' => 'Body Shop',
        'description' => 'Collision repair and painting',
        'icon' => 'spray-can',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Vehicle', 'Workshop', 'POS',
        ],
        
        'pos_variant' => 'body_shop',
        'pos_features' => [
            'vehicle_lookup' => true,
            'estimate_workflow' => true,
            'paint_mixing' => true,
            'photo_documentation' => true,
            'parts_ordering' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'create_estimate',
            'show_photo_gallery' => true,
            'show_paint_codes' => true,
        ],
        
        'databases' => [
            'techdoc' => ['enabled' => true, 'connection' => 'techdoc_readonly'],
            'vin_decoder' => ['enabled' => true, 'provider' => 'tunisia_vin'],
            'paint_codes' => ['enabled' => true, 'connection' => 'paint_codes_readonly'],
        ],
        
        'compatible_extras' => [
            'appointments' => ['label' => 'Appointment Scheduling', 'module' => 'Appointments'],
            'insurance_claims' => ['label' => 'Insurance Integration', 'module' => 'Insurance'],
            'rental_car_coordination' => ['label' => 'Rental Car Coordination', 'module' => null],
            'batch_tracking' => ['label' => 'Batch Tracking (Paint)', 'module' => 'BatchExpiry'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
        ],
        
        'incompatible' => ['menu', 'recipe', 'tables', 'reservations'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'setup_estimate_templates', 'title' => 'Estimate Templates', 'required' => false],
            ['key' => 'configure_paint_inventory', 'title' => 'Paint Inventory', 'required' => false],
            ['key' => 'setup_photo_workflow', 'title' => 'Photo Documentation', 'required' => false],
            ['key' => 'invite_team', 'title' => 'Invite Team Members', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => false,
            'require_estimate_approval' => true,
            'auto_capture_photos' => true,
            'estimate_validity_days' => 30,
        ],
        
        'document_types' => ['estimate', 'work_order', 'delivery_note', 'invoice', 'credit_note'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'parts_retailer' => [
        'product' => 'otospex',
        'label' => 'Auto Parts Retailer',
        'description' => 'Auto parts sales and distribution',
        'icon' => 'cog',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Vehicle',  // For compatibility lookup, NOT Workshop
            'POS',
        ],
        
        'pos_variant' => 'parts_counter',
        'pos_features' => [
            'vehicle_lookup' => true,
            'compatibility_check' => true,
            'cross_reference_lookup' => true,
            'quick_search' => true,
            'b2b_pricing' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'search_part',
            'show_compatibility_panel' => true,
            'show_alternatives' => true,
            'show_stock_locations' => true,
        ],
        
        'databases' => [
            'techdoc' => ['enabled' => true, 'connection' => 'techdoc_readonly'],
            'vin_decoder' => ['enabled' => true, 'provider' => 'tunisia_vin'],
            'cross_reference' => ['enabled' => true, 'connection' => 'xref_readonly'],
        ],
        
        'compatible_extras' => [
            'batch_tracking' => ['label' => 'Batch Tracking', 'module' => 'BatchExpiry'],
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'b2b_pricing_tiers' => ['label' => 'B2B Pricing Tiers', 'module' => null],
            'core_returns' => ['label' => 'Core/Consignment Returns', 'module' => 'CoreReturns'],
            'ecommerce' => ['label' => 'E-Commerce', 'module' => 'ECommerce'],
        ],
        
        'incompatible' => ['workshop', 'menu', 'recipe', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_parts_catalog', 'title' => 'Import Parts Catalog', 'required' => false],
            ['key' => 'setup_pricing_tiers', 'title' => 'Set Up Pricing Tiers', 'required' => false],
            ['key' => 'configure_core_returns', 'title' => 'Core Returns Policy', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => false,
            'require_vehicle_for_sale' => false,
            'show_compatibility_warning' => true,
            'enable_b2b_pricing' => true,
        ],
        
        'document_types' => ['quote', 'delivery_note', 'invoice', 'credit_note', 'receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'car_glass' => [
        'product' => 'otospex',
        'label' => 'Car Glass Specialist',
        'description' => 'Windshield and window replacement',
        'icon' => 'square',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Vehicle', 'Workshop', 'POS',
        ],
        
        'pos_variant' => 'glass_specialist',
        'pos_features' => [
            'vehicle_lookup' => true,
            'glass_catalog' => true,
            'adas_calibration' => true,
            'mobile_service' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'select_vehicle',
            'show_glass_diagram' => true,
            'show_adas_checklist' => true,
        ],
        
        'databases' => [
            'glass_catalog' => ['enabled' => true, 'connection' => 'glass_readonly'],
            'vin_decoder' => ['enabled' => true, 'provider' => 'tunisia_vin'],
        ],
        
        'compatible_extras' => [
            'appointments' => ['label' => 'Appointment Scheduling', 'module' => 'Appointments'],
            'mobile_service_tracking' => ['label' => 'Mobile Service', 'module' => 'MobileService'],
            'insurance_claims' => ['label' => 'Insurance Integration', 'module' => 'Insurance'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
        ],
        
        'incompatible' => ['menu', 'recipe', 'tables', 'batch_tracking'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_glass_catalog', 'title' => 'Import Glass Catalog', 'required' => false],
            ['key' => 'setup_adas_calibration', 'title' => 'ADAS Calibration Setup', 'required' => false],
            ['key' => 'configure_insurance_partners', 'title' => 'Insurance Partners', 'required' => false],
        ],
        
        'defaults' => [
            'require_vehicle_for_sale' => true,
            'create_calibration_record' => true,
            'default_warranty_days' => 365,
        ],
        
        'document_types' => ['quote', 'work_order', 'invoice', 'credit_note', 'warranty_certificate'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'tire_shop' => [
        'product' => 'otospex',
        'label' => 'Tire Shop',
        'description' => 'Tire sales, mounting, and storage',
        'icon' => 'circle',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Vehicle', 'Workshop', 'POS',
        ],
        
        'pos_variant' => 'tire_shop',
        'pos_features' => [
            'vehicle_lookup' => true,
            'tire_sizing_lookup' => true,
            'seasonal_swap' => true,
            'tire_hotel' => true,
            'wheel_balancing' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'select_vehicle',
            'show_tire_sizes' => true,
            'show_storage_status' => true,
        ],
        
        'databases' => [
            'tire_catalog' => ['enabled' => true, 'connection' => 'tire_readonly'],
            'vin_decoder' => ['enabled' => true, 'provider' => 'tunisia_vin'],
        ],
        
        'compatible_extras' => [
            'appointments' => ['label' => 'Appointment Scheduling', 'module' => 'Appointments'],
            'tire_hotel_storage' => ['label' => 'Tire Hotel Management', 'module' => 'TireHotel'],
            'seasonal_reminders' => ['label' => 'Seasonal Reminders', 'module' => null],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
        ],
        
        'incompatible' => ['menu', 'recipe', 'tables', 'batch_tracking'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_tire_catalog', 'title' => 'Import Tire Catalog', 'required' => false],
            ['key' => 'setup_tire_hotel', 'title' => 'Set Up Tire Hotel', 'required' => false],
            ['key' => 'configure_seasonal_campaigns', 'title' => 'Seasonal Campaigns', 'required' => false],
        ],
        
        'defaults' => [
            'require_vehicle_for_sale' => true,
            'suggest_tire_size' => true,
            'seasonal_reminder_days' => 30,
        ],
        
        'document_types' => ['quote', 'work_order', 'invoice', 'credit_note', 'storage_contract'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'service_station' => [
        'product' => 'otospex',
        'label' => 'Service Station',
        'description' => 'Quick service and car wash',
        'icon' => 'fuel',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Vehicle',  // Optional tracking, no Workshop
            'POS',
        ],
        
        'pos_variant' => 'express',
        'pos_features' => [
            'quick_service_mode' => true,
            'car_wash_packages' => true,
            'subscription_management' => true,
            'express_checkout' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'quick_sale',
            'show_packages' => true,
            'show_subscriptions' => true,
        ],
        
        'databases' => [],  // No specialized databases
        
        'compatible_extras' => [
            'car_wash_subscriptions' => ['label' => 'Car Wash Subscriptions', 'module' => 'Subscriptions'],
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
        ],
        
        'incompatible' => ['workshop', 'menu', 'recipe', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'setup_quick_service_items', 'title' => 'Quick Service Items', 'required' => false],
            ['key' => 'configure_car_wash_packages', 'title' => 'Car Wash Packages', 'required' => false],
        ],
        
        'defaults' => [
            'require_vehicle_for_sale' => false,
            'quick_checkout_mode' => true,
            'default_receipt_type' => 'thermal',
        ],
        
        'document_types' => ['invoice', 'receipt'],
    ],
    
    // ═══════════════════════════════════════════════════════════════════════
    //                           IZIPOS VERTICALS
    // ═══════════════════════════════════════════════════════════════════════
    
    'pharmacy' => [
        'product' => 'izipos',
        'label' => 'Pharmacy',
        'description' => 'Prescription and OTC medications',
        'icon' => 'pill',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'BatchExpiry',  // Required for pharmacy
            'POS',
        ],
        
        'pos_variant' => 'pharmacy',
        'pos_features' => [
            'batch_selection' => true,
            'expiry_warning' => true,
            'fefo_enforcement' => true,
            'prescription_tracking' => false,  // Future
            'drug_interactions' => false,  // Future
        ],
        'pos_layout' => [
            'primary_action' => 'search_product',
            'show_batch_selector' => true,
            'show_expiry_info' => true,
            'highlight_near_expiry' => true,
        ],
        
        'databases' => [
            'drug_database' => ['enabled' => false, 'connection' => null],  // Future
        ],
        
        'compatible_extras' => [
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'controlled_substances' => ['label' => 'Controlled Substances', 'module' => 'ControlledSubstances'],
        ],
        
        'incompatible' => ['vehicle', 'workshop', 'menu', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_products_with_batches', 'title' => 'Import Products', 'required' => false],
            ['key' => 'configure_expiry_alerts', 'title' => 'Expiry Alert Settings', 'required' => true],
            ['key' => 'setup_fefo_rules', 'title' => 'FEFO Configuration', 'required' => true],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'require_batch_on_sale' => true,
            'expiry_warning_days' => 90,
            'block_expired_sales' => true,
            'fefo_enabled' => true,
        ],
        
        'document_types' => ['invoice', 'credit_note', 'receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'parapharmacy' => [
        'product' => 'izipos',
        'label' => 'Parapharmacy / Cosmetics',
        'description' => 'Beauty, cosmetics, and wellness',
        'icon' => 'sparkles',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'BatchExpiry',  // Optional but included by default
            'POS',
        ],
        
        'pos_variant' => 'parapharmacy',
        'pos_features' => [
            'batch_selection' => true,
            'expiry_warning' => true,
            'beauty_advisor_mode' => true,
            'product_recommendations' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'search_product',
            'show_product_images' => true,
            'show_recommendations' => true,
        ],
        
        'databases' => [
            'parapharmacy_catalog' => ['enabled' => true, 'connection' => 'parapharmacy_readonly'],
            'cosmetics_catalog' => ['enabled' => true, 'connection' => 'cosmetics_readonly'],
        ],
        
        'compatible_extras' => [
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'customer_skin_profiles' => ['label' => 'Customer Skin Profiles', 'module' => 'SkinProfiles'],
        ],
        
        'incompatible' => ['vehicle', 'workshop', 'menu', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_parapharmacy_catalog', 'title' => 'Import Catalog', 'required' => false],
            ['key' => 'setup_beauty_categories', 'title' => 'Beauty Categories', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'require_batch_on_sale' => false,
            'expiry_warning_days' => 180,
            'show_product_images' => true,
        ],
        
        'document_types' => ['invoice', 'credit_note', 'receipt', 'gift_receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'restaurant' => [
        'product' => 'izipos',
        'label' => 'Restaurant',
        'description' => 'Full-service dining',
        'icon' => 'utensils',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Menu',
            'Recipe',
            'Tables',
            'POS',
        ],
        
        'pos_variant' => 'restaurant',
        'pos_features' => [
            'table_assignment' => true,
            'course_management' => true,
            'split_bill' => true,
            'kitchen_printing' => true,
            'order_modifiers' => true,
            'covers_tracking' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'select_table',
            'show_floor_plan' => true,
            'show_course_buttons' => true,
            'show_kitchen_status' => true,
        ],
        
        'databases' => [],
        
        'compatible_extras' => [
            'reservations' => ['label' => 'Reservations', 'module' => 'Reservations'],
            'kitchen_display' => ['label' => 'Kitchen Display System', 'module' => 'KitchenDisplay'],
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'batch_tracking' => ['label' => 'Ingredient Batch Tracking', 'module' => 'BatchExpiry'],
            'delivery_integration' => ['label' => 'Delivery Integration', 'module' => 'Delivery'],
        ],
        
        'incompatible' => ['vehicle', 'workshop'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'create_menu', 'title' => 'Create Your Menu', 'required' => true],
            ['key' => 'setup_floor_plan', 'title' => 'Design Floor Plan', 'required' => true],
            ['key' => 'configure_courses', 'title' => 'Configure Courses', 'required' => false],
            ['key' => 'setup_kitchen_printers', 'title' => 'Kitchen Printers', 'required' => false],
            ['key' => 'invite_team', 'title' => 'Invite Staff', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'require_table_for_dine_in' => true,
            'auto_fire_courses' => false,
            'service_charge_percent' => 0,
            'default_covers' => 2,
        ],
        
        'document_types' => ['invoice', 'receipt', 'split_receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'coffee_shop' => [
        'product' => 'izipos',
        'label' => 'Coffee Shop / Café',
        'description' => 'Coffee, beverages, and quick bites',
        'icon' => 'coffee',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'Menu',
            'POS',
        ],
        
        'pos_variant' => 'quick_service',
        'pos_features' => [
            'modifier_groups' => true,
            'combo_deals' => true,
            'quick_checkout' => true,
            'order_names' => true,
            'pickup_numbers' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'quick_sale',
            'show_popular_items' => true,
            'show_modifiers_inline' => true,
            'large_product_buttons' => true,
        ],
        
        'databases' => [],
        
        'compatible_extras' => [
            'recipe' => ['label' => 'Recipe Management', 'module' => 'Recipe'],
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'mobile_ordering' => ['label' => 'Mobile Ordering', 'module' => 'MobileOrdering'],
        ],
        
        'incompatible' => ['vehicle', 'workshop', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'create_menu', 'title' => 'Create Your Menu', 'required' => true],
            ['key' => 'setup_modifiers', 'title' => 'Set Up Modifiers', 'required' => false],
            ['key' => 'configure_combos', 'title' => 'Configure Combos', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'default_order_type' => 'takeaway',
            'show_order_name_prompt' => true,
            'auto_print_receipt' => true,
        ],
        
        'document_types' => ['invoice', 'receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'retail' => [
        'product' => 'izipos',
        'label' => 'General Retail',
        'description' => 'General merchandise',
        'icon' => 'shopping-bag',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'POS',
        ],
        
        'pos_variant' => 'standard',
        'pos_features' => [
            'barcode_first' => true,
            'quick_search' => true,
            'customer_display' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'scan_barcode',
            'show_numpad' => true,
            'show_quick_buttons' => true,
        ],
        
        'databases' => [],
        
        'compatible_extras' => [
            'batch_tracking' => ['label' => 'Batch Tracking', 'module' => 'BatchExpiry'],
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'ecommerce' => ['label' => 'E-Commerce', 'module' => 'ECommerce'],
        ],
        
        'incompatible' => ['vehicle', 'workshop', 'menu', 'tables'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_products', 'title' => 'Import Products', 'required' => false],
            ['key' => 'print_barcodes', 'title' => 'Print Barcodes', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'barcode_required' => false,
        ],
        
        'document_types' => ['quote', 'delivery_note', 'invoice', 'credit_note', 'receipt'],
    ],
    
    // ───────────────────────────────────────────────────────────────────────
    
    'fashion' => [
        'product' => 'izipos',
        'label' => 'Fashion / Boutique',
        'description' => 'Clothing and accessories',
        'icon' => 'shirt',
        
        'core_modules' => [
            'Identity', 'Company', 'Settings', 'Product', 'Partner',
            'Document', 'Inventory', 'Treasury', 'Accounting',
            'Communication', 'Media',
            'POS',
        ],
        
        'pos_variant' => 'boutique',
        'pos_features' => [
            'variant_matrix' => true,
            'style_suggestions' => true,
            'client_book' => true,
            'size_guide' => true,
        ],
        'pos_layout' => [
            'primary_action' => 'search_product',
            'show_variant_matrix' => true,
            'show_product_images' => true,
            'show_client_history' => true,
        ],
        
        'databases' => [],
        
        'compatible_extras' => [
            'loyalty_program' => ['label' => 'Loyalty Program', 'module' => 'Loyalty'],
            'multi_location' => ['label' => 'Multi-Location', 'module' => null],
            'season_management' => ['label' => 'Season Management', 'module' => 'Seasons'],
            'consignment' => ['label' => 'Consignment', 'module' => 'Consignment'],
            'ecommerce' => ['label' => 'E-Commerce', 'module' => 'ECommerce'],
        ],
        
        'incompatible' => ['vehicle', 'workshop', 'menu', 'tables', 'batch_tracking'],
        
        'onboarding_steps' => [
            ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
            ['key' => 'import_products_with_variants', 'title' => 'Import Products', 'required' => false],
            ['key' => 'setup_size_charts', 'title' => 'Size Charts', 'required' => false],
            ['key' => 'configure_seasons', 'title' => 'Configure Seasons', 'required' => false],
            ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
        ],
        
        'defaults' => [
            'tax_included_in_price' => true,
            'show_variant_matrix' => true,
            'track_by_variant' => true,
            'enable_size_guide' => true,
        ],
        
        'document_types' => ['quote', 'invoice', 'credit_note', 'receipt', 'gift_receipt'],
    ],
];
```

---

## 6. Vertical Inheritance

For future extensibility, new verticals can inherit from base verticals:

```php
// config/verticals_extended.php

return [
    // ═══════════════════════════════════════════════════════════════════════
    // INHERITED VERTICALS (Future)
    // Define new verticals that extend existing ones
    // ═══════════════════════════════════════════════════════════════════════
    
    'motorcycle_shop' => [
        'extends' => 'mechanic',
        'label' => 'Motorcycle Shop',
        'icon' => 'bike',
        'description' => 'Motorcycle sales and service',
        
        'overrides' => [
            'databases' => [
                'motorcycle_catalog' => ['enabled' => true, 'connection' => 'motorcycle_readonly'],
                'vin_decoder' => ['enabled' => true, 'provider' => 'motorcycle_vin'],
            ],
            'pos_features' => [
                'motorcycle_specific_fields' => true,
            ],
        ],
        
        'additional_extras' => [
            'gear_accessories' => ['label' => 'Gear & Accessories', 'module' => null],
            'rider_profiles' => ['label' => 'Rider Profiles', 'module' => null],
        ],
    ],
    
    'veterinary_pharmacy' => [
        'extends' => 'pharmacy',
        'label' => 'Veterinary Pharmacy',
        'icon' => 'paw',
        'description' => 'Veterinary medications',
        
        'overrides' => [
            'databases' => [
                'veterinary_drugs' => ['enabled' => true, 'connection' => 'vet_drugs_readonly'],
            ],
        ],
        
        'additional_extras' => [
            'animal_profiles' => ['label' => 'Animal Profiles', 'module' => 'AnimalProfiles'],
            'weight_based_dosing' => ['label' => 'Weight-Based Dosing', 'module' => null],
        ],
    ],
    
    'dark_kitchen' => [
        'extends' => 'restaurant',
        'label' => 'Dark Kitchen / Ghost Kitchen',
        'icon' => 'truck',
        'description' => 'Delivery-only restaurant',
        
        'overrides' => [
            'core_modules' => [
                'Identity', 'Company', 'Settings', 'Product', 'Partner',
                'Document', 'Inventory', 'Treasury', 'Accounting',
                'Communication', 'Media',
                'Menu', 'Recipe',
                // NO Tables
                'POS',
            ],
            'pos_variant' => 'delivery_kitchen',
            'pos_features' => [
                'table_assignment' => false,
                'delivery_integration' => true,
                'multi_brand' => true,
            ],
        ],
        
        'additional_extras' => [
            'multi_brand' => ['label' => 'Multiple Virtual Brands', 'module' => 'MultiBrand'],
            'delivery_aggregators' => ['label' => 'Delivery Aggregators', 'module' => 'DeliveryAggregators'],
        ],
        
        'removed_extras' => ['tables', 'reservations'],
    ],
    
    'food_truck' => [
        'extends' => 'coffee_shop',
        'label' => 'Food Truck',
        'icon' => 'truck',
        'description' => 'Mobile food service',
        
        'overrides' => [
            'pos_features' => [
                'offline_mode' => true,
                'location_tracking' => true,
                'mobile_optimized' => true,
            ],
        ],
        
        'additional_extras' => [
            'route_planning' => ['label' => 'Route Planning', 'module' => null],
            'event_scheduling' => ['label' => 'Event Scheduling', 'module' => null],
        ],
    ],
];
```

### Vertical Config Service (with Inheritance)

```php
// app/Services/VerticalConfigService.php

namespace App\Services;

use App\Enums\Vertical;
use App\Enums\Product;
use Illuminate\Support\Facades\Cache;

class VerticalConfigService
{
    /**
     * Get full configuration for a vertical
     */
    public function getConfig(string $vertical): array
    {
        return Cache::rememberForever(
            "vertical_config:{$vertical}",
            fn() => $this->computeConfig($vertical)
        );
    }
    
    /**
     * Compute configuration (handles inheritance)
     */
    private function computeConfig(string $vertical): array
    {
        // Check base verticals first
        $baseConfig = config("verticals.{$vertical}");
        
        if ($baseConfig) {
            return $this->normalizeConfig($vertical, $baseConfig);
        }
        
        // Check extended/inherited verticals
        $extendedConfig = config("verticals_extended.{$vertical}");
        
        if ($extendedConfig) {
            return $this->mergeInheritedConfig($vertical, $extendedConfig);
        }
        
        throw new \InvalidArgumentException("Unknown vertical: {$vertical}");
    }
    
    /**
     * Merge inherited configuration with parent
     */
    private function mergeInheritedConfig(string $vertical, array $extendedConfig): array
    {
        $parentVertical = $extendedConfig['extends'];
        $parentConfig = config("verticals.{$parentVertical}");
        
        if (!$parentConfig) {
            throw new \InvalidArgumentException("Parent vertical not found: {$parentVertical}");
        }
        
        // Deep merge parent with overrides
        $config = array_replace_recursive(
            $parentConfig,
            $extendedConfig['overrides'] ?? []
        );
        
        // Copy metadata from extended config
        $config['label'] = $extendedConfig['label'] ?? $parentConfig['label'];
        $config['icon'] = $extendedConfig['icon'] ?? $parentConfig['icon'];
        $config['description'] = $extendedConfig['description'] ?? $parentConfig['description'];
        $config['extends'] = $parentVertical;
        
        // Merge additional extras
        if (isset($extendedConfig['additional_extras'])) {
            $config['compatible_extras'] = array_merge(
                $config['compatible_extras'] ?? [],
                $extendedConfig['additional_extras']
            );
        }
        
        // Remove specified extras
        if (isset($extendedConfig['removed_extras'])) {
            foreach ($extendedConfig['removed_extras'] as $extra) {
                unset($config['compatible_extras'][$extra]);
            }
            $config['incompatible'] = array_merge(
                $config['incompatible'] ?? [],
                $extendedConfig['removed_extras']
            );
        }
        
        return $this->normalizeConfig($vertical, $config);
    }
    
    /**
     * Normalize config structure
     */
    private function normalizeConfig(string $vertical, array $config): array
    {
        return array_merge([
            'vertical' => $vertical,
            'core_modules' => [],
            'pos_variant' => 'standard',
            'pos_features' => [],
            'pos_layout' => [],
            'databases' => [],
            'compatible_extras' => [],
            'incompatible' => [],
            'onboarding_steps' => [],
            'defaults' => [],
            'document_types' => [],
        ], $config);
    }
    
    /**
     * Get all available verticals
     */
    public function getAllVerticals(): array
    {
        $base = array_keys(config('verticals', []));
        $extended = array_keys(config('verticals_extended', []));
        
        return array_merge($base, $extended);
    }
    
    /**
     * Get verticals for a specific product
     */
    public function getVerticalsForProduct(string|Product $product): array
    {
        if ($product instanceof Product) {
            $product = $product->value;
        }
        
        return collect($this->getAllVerticals())
            ->filter(fn($v) => $this->getConfig($v)['product'] === $product)
            ->values()
            ->toArray();
    }
    
    /**
     * Check if a feature is compatible with a vertical
     */
    public function isFeatureCompatible(string $vertical, string $feature): bool
    {
        $config = $this->getConfig($vertical);
        
        // Check if explicitly incompatible
        if (in_array($feature, $config['incompatible'])) {
            return false;
        }
        
        // Check if in compatible extras
        return isset($config['compatible_extras'][$feature]);
    }
    
    /**
     * Get all enabled modules for a vertical + extras
     */
    public function getEnabledModules(string $vertical, array $enabledExtras = []): array
    {
        $config = $this->getConfig($vertical);
        
        $modules = $config['core_modules'];
        
        foreach ($enabledExtras as $extra) {
            if ($this->isFeatureCompatible($vertical, $extra)) {
                $extraConfig = $config['compatible_extras'][$extra] ?? null;
                if ($extraConfig && isset($extraConfig['module']) && $extraConfig['module']) {
                    $modules[] = $extraConfig['module'];
                }
            }
        }
        
        return array_unique($modules);
    }
    
    /**
     * Clear config cache (call when config files change)
     */
    public function clearCache(): void
    {
        foreach ($this->getAllVerticals() as $vertical) {
            Cache::forget("vertical_config:{$vertical}");
        }
    }
}
```

---

## 7. Feature Override System

### Company-Level Feature Overrides

Allows enabling compatible extras per company:

```php
// app/Services/CompanyConfigService.php

namespace App\Services;

use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

class CompanyConfigService
{
    public function __construct(
        private VerticalConfigService $verticalService,
        private ProductService $productService,
    ) {}
    
    /**
     * Get effective configuration for a company
     */
    public function getEffectiveConfig(Company $company): CompanyConfig
    {
        return Cache::remember(
            "company_config:{$company->id}",
            now()->addHours(24),
            fn() => $this->computeEffectiveConfig($company)
        );
    }
    
    /**
     * Compute effective configuration
     */
    private function computeEffectiveConfig(Company $company): CompanyConfig
    {
        $tenant = $company->tenant;
        
        // Company can override tenant's vertical (rare)
        $vertical = $company->vertical_override ?? $tenant->vertical;
        
        // Get base vertical config
        $verticalConfig = $this->verticalService->getConfig($vertical);
        
        // Merge enabled extras from tenant + company overrides
        $enabledExtras = array_merge(
            $tenant->enabled_extras ?? [],
            $company->feature_overrides ?? []
        );
        
        // Filter to only compatible extras
        $validExtras = array_filter(
            $enabledExtras,
            fn($extra) => $this->verticalService->isFeatureCompatible($vertical, $extra)
        );
        
        // Get all enabled modules
        $modules = $this->verticalService->getEnabledModules($vertical, $validExtras);
        
        return new CompanyConfig(
            vertical: $vertical,
            product: $verticalConfig['product'],
            modules: $modules,
            posVariant: $verticalConfig['pos_variant'],
            posFeatures: $verticalConfig['pos_features'],
            posLayout: $verticalConfig['pos_layout'],
            databases: $verticalConfig['databases'],
            enabledExtras: $validExtras,
            defaults: $verticalConfig['defaults'],
            documentTypes: $verticalConfig['document_types'],
        );
    }
    
    /**
     * Enable a feature for a company
     */
    public function enableFeature(Company $company, string $feature): void
    {
        $vertical = $company->vertical_override ?? $company->tenant->vertical;
        
        if (!$this->verticalService->isFeatureCompatible($vertical, $feature)) {
            throw new IncompatibleFeatureException(
                "Feature '{$feature}' is not compatible with vertical '{$vertical}'"
            );
        }
        
        $overrides = $company->feature_overrides ?? [];
        
        if (!in_array($feature, $overrides)) {
            $company->feature_overrides = [...$overrides, $feature];
            $company->save();
        }
        
        $this->clearCache($company);
    }
    
    /**
     * Disable a feature for a company
     */
    public function disableFeature(Company $company, string $feature): void
    {
        $overrides = $company->feature_overrides ?? [];
        
        $company->feature_overrides = array_values(
            array_filter($overrides, fn($f) => $f !== $feature)
        );
        $company->save();
        
        $this->clearCache($company);
    }
    
    /**
     * Clear cache for a company
     */
    public function clearCache(Company $company): void
    {
        Cache::forget("company_config:{$company->id}");
    }
}
```

### Company Config DTO

```php
// app/DTOs/CompanyConfig.php

namespace App\DTOs;

use Spatie\LaravelData\Data;

class CompanyConfig extends Data
{
    public function __construct(
        public string $vertical,
        public string $product,
        public array $modules,
        public string $posVariant,
        public array $posFeatures,
        public array $posLayout,
        public array $databases,
        public array $enabledExtras,
        public array $defaults,
        public array $documentTypes,
    ) {}
    
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules);
    }
    
    public function hasPosFeature(string $feature): bool
    {
        return $this->posFeatures[$feature] ?? false;
    }
    
    public function hasExtra(string $extra): bool
    {
        return in_array($extra, $this->enabledExtras);
    }
    
    public function getDefault(string $key, mixed $fallback = null): mixed
    {
        return $this->defaults[$key] ?? $fallback;
    }
    
    public function canCreateDocument(string $type): bool
    {
        return in_array($type, $this->documentTypes);
    }
}
```

---

## 8. Database Schema

### Migration: Add Vertical to Tenants

```php
// database/migrations/2025_01_02_000001_add_vertical_to_tenants_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('vertical', 50)
                ->default('retail')
                ->after('name');
            
            $table->jsonb('enabled_extras')
                ->default('[]')
                ->after('vertical');
            
            $table->string('signup_source', 100)
                ->nullable()
                ->after('enabled_extras');
            
            $table->jsonb('signup_tracking')
                ->nullable()
                ->after('signup_source');
            
            $table->index('vertical');
        });
    }
    
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['vertical']);
            $table->dropColumn([
                'vertical',
                'enabled_extras',
                'signup_source',
                'signup_tracking',
            ]);
        });
    }
};
```

### Migration: Add Feature Overrides to Companies

```php
// database/migrations/2025_01_02_000002_add_feature_overrides_to_companies_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('feature_overrides')
                ->default('[]')
                ->after('settings');
            
            $table->string('vertical_override', 50)
                ->nullable()
                ->after('feature_overrides');
            
            $table->index('vertical_override');
        });
    }
    
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['vertical_override']);
            $table->dropColumn(['feature_overrides', 'vertical_override']);
        });
    }
};
```

### Migration: Signup Tracking

```php
// database/migrations/2025_01_02_000003_create_signup_tracking_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('vertical', 50);
            
            // UTM tracking
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 200)->nullable();
            $table->string('utm_content', 200)->nullable();
            $table->string('utm_term', 200)->nullable();
            
            // Referral
            $table->string('referral_code', 50)->nullable();
            $table->text('referrer_url')->nullable();
            $table->string('landing_page', 500)->nullable();
            
            // Device info
            $table->string('device_type', 20)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            
            // Conversion tracking
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('first_sale_at')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            
            // Indexes
            $table->index(['utm_campaign', 'created_at']);
            $table->index(['vertical', 'created_at']);
            $table->index(['utm_source', 'created_at']);
            $table->index('referral_code');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('signup_tracking');
    }
};
```

### Migration: Onboarding Checklist

```php
// database/migrations/2025_01_02_000004_create_onboarding_checklists_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('step_key', 50);
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
            
            $table->unique(['tenant_id', 'step_key']);
            $table->index(['tenant_id', 'is_completed']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('onboarding_checklists');
    }
};
```

---

## 9. Backend Implementation

### Tenant Model Updates

```php
// app/Modules/Tenant/Domain/Tenant.php

namespace App\Modules\Tenant\Domain;

use App\Enums\Vertical;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'vertical',
        'enabled_extras',
        'signup_source',
        'signup_tracking',
    ];
    
    protected $casts = [
        'vertical' => Vertical::class,
        'enabled_extras' => 'array',
        'signup_tracking' => 'array',
    ];
    
    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────
    
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
    
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
    
    public function onboardingChecklist(): HasMany
    {
        return $this->hasMany(OnboardingChecklist::class);
    }
    
    public function signupTracking(): HasOne
    {
        return $this->hasOne(SignupTracking::class);
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────
    
    public function hasExtra(string $extra): bool
    {
        return in_array($extra, $this->enabled_extras ?? []);
    }
    
    public function enableExtra(string $extra): void
    {
        if (!$this->hasExtra($extra)) {
            $this->enabled_extras = [...($this->enabled_extras ?? []), $extra];
            $this->save();
        }
    }
    
    public function disableExtra(string $extra): void
    {
        $this->enabled_extras = array_values(
            array_filter($this->enabled_extras ?? [], fn($e) => $e !== $extra)
        );
        $this->save();
    }
    
    public function getProduct(): string
    {
        return $this->vertical->product()->value;
    }
    
    public function isOnboardingComplete(): bool
    {
        return $this->onboardingChecklist()
            ->where('is_required', true)
            ->where('is_completed', false)
            ->doesntExist();
    }
}
```

### Company Model Updates

```php
// app/Modules/Company/Domain/Company.php

// Add to existing Company model:

protected $casts = [
    // ... existing casts
    'feature_overrides' => 'array',
    'vertical_override' => Vertical::class,
];

public function getEffectiveVertical(): Vertical
{
    return $this->vertical_override ?? $this->tenant->vertical;
}

public function hasFeature(string $feature): bool
{
    return in_array($feature, $this->feature_overrides ?? [])
        || $this->tenant->hasExtra($feature);
}
```

### Module Middleware

```php
// app/Http/Middleware/RequireModule.php

namespace App\Http\Middleware;

use App\Services\CompanyConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModule
{
    public function __construct(
        private CompanyConfigService $configService
    ) {}
    
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $company = $request->attributes->get('current_company');
        
        if (!$company) {
            abort(403, 'No company context');
        }
        
        $config = $this->configService->getEffectiveConfig($company);
        
        if (!$config->hasModule($module)) {
            abort(403, "Module '{$module}' is not enabled for this business type");
        }
        
        return $next($request);
    }
}
```

Register in Kernel:

```php
// app/Http/Kernel.php

protected $middlewareAliases = [
    // ... existing
    'module' => \App\Http\Middleware\RequireModule::class,
];
```

### Apply to Routes

```php
// app/Modules/Vehicle/Presentation/routes.php

Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:Vehicle'])
    ->prefix('api/v1')
    ->group(function () {
        Route::apiResource('vehicles', VehicleController::class);
    });

// app/Modules/Workshop/Presentation/routes.php

Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:Workshop'])
    ->prefix('api/v1')
    ->group(function () {
        Route::apiResource('work-orders', WorkOrderController::class);
    });

// app/Modules/Menu/Presentation/routes.php

Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:Menu'])
    ->prefix('api/v1')
    ->group(function () {
        Route::apiResource('menu-items', MenuItemController::class);
        Route::apiResource('menu-categories', MenuCategoryController::class);
    });
```

---

## 10. Frontend Implementation

### Company Config Context

```typescript
// apps/web/src/contexts/CompanyConfigContext.tsx

import { createContext, useContext, ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

interface CompanyConfig {
    vertical: string;
    product: 'izipos' | 'otospex';
    modules: string[];
    posVariant: string;
    posFeatures: Record<string, boolean>;
    posLayout: Record<string, any>;
    databases: Record<string, { enabled: boolean }>;
    enabledExtras: string[];
    defaults: Record<string, any>;
    documentTypes: string[];
}

interface CompanyConfigContextType {
    config: CompanyConfig | null;
    isLoading: boolean;
    hasModule: (module: string) => boolean;
    hasPosFeature: (feature: string) => boolean;
    hasExtra: (extra: string) => boolean;
    getDefault: <T>(key: string, fallback?: T) => T;
    canCreateDocument: (type: string) => boolean;
}

const CompanyConfigContext = createContext<CompanyConfigContextType | null>(null);

export function CompanyConfigProvider({ children }: { children: ReactNode }) {
    const { data: config, isLoading } = useQuery({
        queryKey: ['company-config'],
        queryFn: () => api.get<CompanyConfig>('/api/v1/company/config'),
        staleTime: 1000 * 60 * 60, // 1 hour
    });
    
    const hasModule = (module: string) => config?.modules.includes(module) ?? false;
    const hasPosFeature = (feature: string) => config?.posFeatures[feature] ?? false;
    const hasExtra = (extra: string) => config?.enabledExtras.includes(extra) ?? false;
    const getDefault = <T,>(key: string, fallback?: T) => (config?.defaults[key] ?? fallback) as T;
    const canCreateDocument = (type: string) => config?.documentTypes.includes(type) ?? false;
    
    return (
        <CompanyConfigContext.Provider value={{
            config: config ?? null,
            isLoading,
            hasModule,
            hasPosFeature,
            hasExtra,
            getDefault,
            canCreateDocument,
        }}>
            {children}
        </CompanyConfigContext.Provider>
    );
}

export function useCompanyConfig() {
    const context = useContext(CompanyConfigContext);
    if (!context) {
        throw new Error('useCompanyConfig must be used within CompanyConfigProvider');
    }
    return context;
}
```

### Dynamic Navigation

```typescript
// apps/web/src/components/organisms/Sidebar/Sidebar.tsx

import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import { useProductConfig } from '@/contexts/ProductConfigContext';
import {
    Home, Package, Users, FileText, Warehouse, CreditCard, 
    Calculator, Car, Wrench, Coffee, Utensils, Pill, ShoppingBag,
    Shirt, Settings, BarChart
} from 'lucide-react';

interface NavItem {
    name: string;
    href: string;
    icon: React.ComponentType;
    module?: string;
    extra?: string;
}

export function Sidebar() {
    const { hasModule, hasExtra, config } = useCompanyConfig();
    const { product } = useProductConfig();
    
    const navigationItems: NavItem[] = useMemo(() => {
        const items: NavItem[] = [
            // Always visible
            { name: 'Dashboard', href: '/', icon: Home },
            { name: 'Products', href: '/products', icon: Package },
            { name: 'Partners', href: '/partners', icon: Users },
            { name: 'Documents', href: '/documents', icon: FileText },
            { name: 'Inventory', href: '/inventory', icon: Warehouse },
            
            // POS - always visible if POS module
            { name: 'Point of Sale', href: '/pos', icon: CreditCard, module: 'POS' },
            
            // Financial
            { name: 'Treasury', href: '/treasury', icon: Calculator, module: 'Treasury' },
            { name: 'Accounting', href: '/accounting', icon: BarChart, module: 'Accounting' },
            
            // Otospex modules
            { name: 'Vehicles', href: '/vehicles', icon: Car, module: 'Vehicle' },
            { name: 'Workshop', href: '/workshop', icon: Wrench, module: 'Workshop' },
            
            // IziPOS modules
            { name: 'Menu', href: '/menu', icon: Coffee, module: 'Menu' },
            { name: 'Recipes', href: '/recipes', icon: Utensils, module: 'Recipe' },
            { name: 'Tables', href: '/tables', icon: Grid, module: 'Tables' },
            
            // Optional modules
            { name: 'Batches & Expiry', href: '/batches', icon: Layers, module: 'BatchExpiry' },
            { name: 'Appointments', href: '/appointments', icon: Calendar, module: 'Appointments' },
            { name: 'Reservations', href: '/reservations', icon: BookOpen, module: 'Reservations' },
            { name: 'Loyalty', href: '/loyalty', icon: Heart, module: 'Loyalty' },
            
            // Settings always visible
            { name: 'Settings', href: '/settings', icon: Settings },
        ];
        
        // Filter by module availability
        return items.filter(item => {
            if (item.module) {
                return hasModule(item.module);
            }
            if (item.extra) {
                return hasExtra(item.extra);
            }
            return true;
        });
    }, [hasModule, hasExtra]);
    
    return (
        <nav className="flex flex-col gap-1 p-4">
            {navigationItems.map((item) => (
                <NavLink
                    key={item.href}
                    to={item.href}
                    className={({ isActive }) =>
                        cn(
                            'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                            isActive
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        )
                    }
                >
                    <item.icon className="h-5 w-5" />
                    {item.name}
                </NavLink>
            ))}
        </nav>
    );
}
```

### POS Variant Loader

```typescript
// apps/web/src/pages/POS/POSPage.tsx

import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import { Suspense, lazy } from 'react';
import { LoadingScreen } from '@/components/atoms/LoadingScreen';

// Lazy load POS variants
const StandardPOS = lazy(() => import('./variants/StandardPOS'));
const PharmacyPOS = lazy(() => import('./variants/PharmacyPOS'));
const RestaurantPOS = lazy(() => import('./variants/RestaurantPOS'));
const QuickServicePOS = lazy(() => import('./variants/QuickServicePOS'));
const WorkshopPOS = lazy(() => import('./variants/WorkshopPOS'));
const PartsCounterPOS = lazy(() => import('./variants/PartsCounterPOS'));
const GlassSpecialistPOS = lazy(() => import('./variants/GlassSpecialistPOS'));
const TireShopPOS = lazy(() => import('./variants/TireShopPOS'));
const BodyShopPOS = lazy(() => import('./variants/BodyShopPOS'));
const ExpressPOS = lazy(() => import('./variants/ExpressPOS'));
const BoutiquePOS = lazy(() => import('./variants/BoutiquePOS'));
const ParapharmacyPOS = lazy(() => import('./variants/ParapharmacyPOS'));

const POS_VARIANTS: Record<string, React.LazyExoticComponent<React.ComponentType>> = {
    standard: StandardPOS,
    pharmacy: PharmacyPOS,
    parapharmacy: ParapharmacyPOS,
    restaurant: RestaurantPOS,
    quick_service: QuickServicePOS,
    workshop: WorkshopPOS,
    parts_counter: PartsCounterPOS,
    glass_specialist: GlassSpecialistPOS,
    tire_shop: TireShopPOS,
    body_shop: BodyShopPOS,
    express: ExpressPOS,
    boutique: BoutiquePOS,
};

export function POSPage() {
    const { config, isLoading } = useCompanyConfig();
    
    if (isLoading || !config) {
        return <LoadingScreen />;
    }
    
    const POSComponent = POS_VARIANTS[config.posVariant] ?? StandardPOS;
    
    return (
        <Suspense fallback={<LoadingScreen />}>
            <POSComponent />
        </Suspense>
    );
}
```

### TypeScript Types

```typescript
// apps/web/src/types/vertical.ts

export type Product = 'izipos' | 'otospex';

export type Vertical =
    // Otospex
    | 'mechanic'
    | 'body_shop'
    | 'parts_retailer'
    | 'car_glass'
    | 'tire_shop'
    | 'service_station'
    // IziPOS
    | 'pharmacy'
    | 'parapharmacy'
    | 'restaurant'
    | 'coffee_shop'
    | 'retail'
    | 'fashion';

export type POSVariant =
    | 'standard'
    | 'pharmacy'
    | 'parapharmacy'
    | 'restaurant'
    | 'quick_service'
    | 'workshop'
    | 'parts_counter'
    | 'glass_specialist'
    | 'tire_shop'
    | 'body_shop'
    | 'express'
    | 'boutique';

export interface VerticalOption {
    value: Vertical;
    label: string;
    icon: string;
    description: string;
    posVariant: POSVariant;
}

export interface CompanyConfig {
    vertical: Vertical;
    product: Product;
    modules: string[];
    posVariant: POSVariant;
    posFeatures: Record<string, boolean>;
    posLayout: Record<string, any>;
    databases: Record<string, { enabled: boolean; connection?: string }>;
    enabledExtras: string[];
    defaults: Record<string, any>;
    documentTypes: string[];
}

export interface ProductConfig {
    name: string;
    tagline: string;
    theme: 'blue' | 'orange';
    primaryColor: string;
    logo: string;
    favicon: string;
    appDomain: string;
    apiDomain: string;
    supportEmail: string;
}
```

---

## 11. Signup & Deep-Link System

### Signup Controller

```php
// app/Http/Controllers/Auth/SignupController.php

namespace App\Http\Controllers\Auth;

use App\DTOs\SignupData;
use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Http\Requests\SignupRequest;
use App\Services\ProductService;
use App\Services\SignupService;
use App\Services\VerticalConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignupController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private VerticalConfigService $verticalService,
        private SignupService $signupService,
    ) {}
    
    /**
     * GET /signup
     * Get signup page configuration
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vertical' => ['nullable', 'string'],
            'features' => ['nullable', 'string'],
            'ref' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'string', 'max:10'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:200'],
            'utm_content' => ['nullable', 'string', 'max:200'],
            'utm_term' => ['nullable', 'string', 'max:200'],
        ]);
        
        $currentProduct = $this->productService->current();
        $presetVertical = $validated['vertical'] ?? null;
        
        // Validate vertical if specified
        if ($presetVertical) {
            try {
                $verticalConfig = $this->verticalService->getConfig($presetVertical);
                
                // Check if vertical belongs to current product
                if ($verticalConfig['product'] !== $currentProduct) {
                    // Redirect to correct product domain
                    $correctDomain = config("products.{$verticalConfig['product']}.app_domain");
                    return response()->json([
                        'redirect' => "https://{$correctDomain}/signup?" . http_build_query($validated),
                    ]);
                }
            } catch (\InvalidArgumentException $e) {
                $presetVertical = null; // Invalid vertical, ignore
            }
        }
        
        // Parse preset features
        $presetFeatures = [];
        if (!empty($validated['features'])) {
            $presetFeatures = array_filter(explode(',', $validated['features']));
        }
        
        return response()->json([
            'product' => $currentProduct,
            'product_config' => $this->productService->config(),
            'preset_vertical' => $presetVertical,
            'preset_features' => $presetFeatures,
            'available_verticals' => $this->getAvailableVerticals($currentProduct),
            'referral_code' => $validated['ref'] ?? null,
            'tracking' => [
                'utm_source' => $validated['utm_source'] ?? null,
                'utm_medium' => $validated['utm_medium'] ?? null,
                'utm_campaign' => $validated['utm_campaign'] ?? null,
                'utm_content' => $validated['utm_content'] ?? null,
                'utm_term' => $validated['utm_term'] ?? null,
            ],
        ]);
    }
    
    /**
     * POST /signup
     * Create new account
     */
    public function store(SignupRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        // Validate vertical belongs to current product
        $verticalConfig = $this->verticalService->getConfig($validated['vertical']);
        
        if ($verticalConfig['product'] !== $this->productService->current()) {
            throw ValidationException::withMessages([
                'vertical' => ["This business type is not available on {$this->productService->config('name')}"],
            ]);
        }
        
        // Validate requested features are compatible
        $requestedFeatures = $validated['features'] ?? [];
        foreach ($requestedFeatures as $feature) {
            if (!$this->verticalService->isFeatureCompatible($validated['vertical'], $feature)) {
                throw ValidationException::withMessages([
                    'features' => ["Feature '{$feature}' is not compatible with your business type"],
                ]);
            }
        }
        
        // Create account
        $result = $this->signupService->createAccount(
            new SignupData(
                email: $validated['email'],
                password: $validated['password'],
                businessName: $validated['business_name'],
                vertical: $validated['vertical'],
                features: $requestedFeatures,
                country: $validated['country'],
                locale: $validated['locale'] ?? app()->getLocale(),
                referralCode: $validated['referral_code'] ?? null,
                tracking: $validated['tracking'] ?? [],
            )
        );
        
        return response()->json([
            'message' => 'Account created successfully',
            'token' => $result->token,
            'redirect' => '/onboarding',
            'tenant_id' => $result->tenant->id,
        ], 201);
    }
    
    /**
     * GET /signup/verticals
     * Get available verticals for current product
     */
    public function verticals(): JsonResponse
    {
        $product = $this->productService->current();
        
        return response()->json([
            'verticals' => $this->getAvailableVerticals($product),
        ]);
    }
    
    /**
     * GET /signup/verticals/{vertical}/features
     * Get available features for a vertical
     */
    public function verticalFeatures(string $vertical): JsonResponse
    {
        try {
            $config = $this->verticalService->getConfig($vertical);
        } catch (\InvalidArgumentException $e) {
            abort(404, 'Vertical not found');
        }
        
        return response()->json([
            'vertical' => $vertical,
            'core_modules' => $config['core_modules'],
            'compatible_extras' => $config['compatible_extras'],
            'pos_variant' => $config['pos_variant'],
            'pos_features' => $config['pos_features'],
        ]);
    }
    
    private function getAvailableVerticals(string $product): array
    {
        $verticals = $this->verticalService->getVerticalsForProduct($product);
        
        return array_map(function ($vertical) {
            $config = $this->verticalService->getConfig($vertical);
            return [
                'value' => $vertical,
                'label' => $config['label'],
                'icon' => $config['icon'] ?? 'building',
                'description' => $config['description'] ?? '',
                'pos_variant' => $config['pos_variant'],
            ];
        }, $verticals);
    }
}
```

### Signup Service

```php
// app/Services/SignupService.php

namespace App\Services;

use App\DTOs\SignupData;
use App\DTOs\SignupResult;
use App\Events\TenantSignedUp;
use App\Models\Company;
use App\Models\OnboardingChecklist;
use App\Models\SignupTracking;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SignupService
{
    public function __construct(
        private VerticalConfigService $verticalService,
    ) {}
    
    public function createAccount(SignupData $data): SignupResult
    {
        return DB::transaction(function () use ($data) {
            $verticalConfig = $this->verticalService->getConfig($data->vertical);
            
            // 1. Create Tenant
            $tenant = Tenant::create([
                'name' => $data->businessName,
                'vertical' => $data->vertical,
                'enabled_extras' => $data->features,
                'signup_source' => $data->tracking['utm_source'] ?? 'direct',
            ]);
            
            // 2. Create User
            $user = User::create([
                'tenant_id' => $tenant->id,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'locale' => $data->locale,
            ]);
            
            // 3. Create Default Company
            $company = Company::create([
                'tenant_id' => $tenant->id,
                'name' => $data->businessName,
                'country_code' => $data->country,
                'settings' => $verticalConfig['defaults'],
            ]);
            
            // 4. Assign Owner Role
            $user->assignRole('owner');
            $user->companies()->attach($company->id, ['role' => 'owner']);
            
            // 5. Create Signup Tracking
            SignupTracking::create([
                'tenant_id' => $tenant->id,
                'vertical' => $data->vertical,
                'utm_source' => $data->tracking['utm_source'] ?? null,
                'utm_medium' => $data->tracking['utm_medium'] ?? null,
                'utm_campaign' => $data->tracking['utm_campaign'] ?? null,
                'utm_content' => $data->tracking['utm_content'] ?? null,
                'utm_term' => $data->tracking['utm_term'] ?? null,
                'referral_code' => $data->referralCode,
                'country_code' => $data->country,
            ]);
            
            // 6. Create Onboarding Checklist
            $this->createOnboardingChecklist($tenant, $verticalConfig);
            
            // 7. Seed Default Data
            $this->seedDefaultData($company, $verticalConfig);
            
            // 8. Fire Event
            event(new TenantSignedUp($tenant, $data->tracking));
            
            // 9. Generate Token
            $token = $user->createToken('auth')->plainTextToken;
            
            return new SignupResult(
                tenant: $tenant,
                user: $user,
                company: $company,
                token: $token,
            );
        });
    }
    
    private function createOnboardingChecklist(Tenant $tenant, array $verticalConfig): void
    {
        $steps = $verticalConfig['onboarding_steps'] ?? [];
        
        foreach ($steps as $index => $step) {
            OnboardingChecklist::create([
                'tenant_id' => $tenant->id,
                'step_key' => $step['key'],
                'title' => $step['title'],
                'description' => $step['description'] ?? null,
                'is_required' => $step['required'] ?? false,
                'order' => $index,
            ]);
        }
    }
    
    private function seedDefaultData(Company $company, array $verticalConfig): void
    {
        // Seed chart of accounts based on country
        // Seed tax rates based on country
        // Seed vertical-specific defaults (categories, payment methods, etc.)
        
        // This will be implemented per vertical as needed
        // For now, use events to allow modules to hook in
        event(new CompanyCreated($company, $verticalConfig));
    }
}
```

### Signup Request Validation

```php
// app/Http/Requests/SignupRequest.php

namespace App\Http\Requests;

use App\Services\VerticalConfigService;
use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
{
    public function rules(): array
    {
        $verticals = app(VerticalConfigService::class)->getAllVerticals();
        
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'vertical' => ['required', 'string', 'in:' . implode(',', $verticals)],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:50'],
            'country' => ['required', 'string', 'size:2'],
            'locale' => ['nullable', 'string', 'max:10'],
            'referral_code' => ['nullable', 'string', 'max:50'],
            'tracking' => ['nullable', 'array'],
            'tracking.utm_source' => ['nullable', 'string', 'max:100'],
            'tracking.utm_medium' => ['nullable', 'string', 'max:100'],
            'tracking.utm_campaign' => ['nullable', 'string', 'max:200'],
            'tracking.utm_content' => ['nullable', 'string', 'max:200'],
            'tracking.utm_term' => ['nullable', 'string', 'max:200'],
        ];
    }
}
```

---

## 12. Marketing & Campaign System

### Campaign Short Links

```php
// routes/web.php

// Campaign short links
Route::get('/go/{campaign}', [CampaignController::class, 'redirect']);
```

```php
// app/Http/Controllers/CampaignController.php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class CampaignController extends Controller
{
    public function redirect(string $campaign): RedirectResponse
    {
        $preset = config("marketing.campaign_presets.{$campaign}");
        
        if (!$preset) {
            abort(404, 'Campaign not found');
        }
        
        return redirect('/signup?' . http_build_query($preset));
    }
}
```

### Marketing Configuration

```php
// config/marketing.php

return [
    // ═══════════════════════════════════════════════════════════════════════
    // LANDING PAGE PATHS
    // Map landing page paths to signup parameters
    // ═══════════════════════════════════════════════════════════════════════
    
    'landing_pages' => [
        'otospex.com' => [
            '/garage' => ['vertical' => 'mechanic'],
            '/carrosserie' => ['vertical' => 'body_shop'],
            '/pieces-auto' => ['vertical' => 'parts_retailer'],
            '/vitrage-auto' => ['vertical' => 'car_glass'],
            '/pneus' => ['vertical' => 'tire_shop'],
            '/station-service' => ['vertical' => 'service_station'],
        ],
        'izipos.com' => [
            '/pharmacie' => ['vertical' => 'pharmacy'],
            '/parapharmacie' => ['vertical' => 'parapharmacy'],
            '/restaurant' => ['vertical' => 'restaurant'],
            '/cafe' => ['vertical' => 'coffee_shop'],
            '/boutique' => ['vertical' => 'fashion'],
            '/commerce' => ['vertical' => 'retail'],
        ],
    ],
    
    // ═══════════════════════════════════════════════════════════════════════
    // CAMPAIGN PRESETS
    // Short codes for marketing campaigns
    // Usage: /go/{preset_name}
    // ═══════════════════════════════════════════════════════════════════════
    
    'campaign_presets' => [
        // Tunisia Campaigns
        'garage_tn_fb' => [
            'vertical' => 'mechanic',
            'locale' => 'fr-TN',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid',
            'utm_campaign' => 'garage_tunisia_2025',
        ],
        'pharmacy_tn_google' => [
            'vertical' => 'pharmacy',
            'locale' => 'fr-TN',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'pharmacy_tunisia_2025',
        ],
        
        // France Campaigns
        'garage_fr_fb' => [
            'vertical' => 'mechanic',
            'locale' => 'fr-FR',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid',
            'utm_campaign' => 'garage_france_2025',
        ],
        'restaurant_fr_ig' => [
            'vertical' => 'restaurant',
            'locale' => 'fr-FR',
            'utm_source' => 'instagram',
            'utm_medium' => 'paid',
            'utm_campaign' => 'restaurant_france_2025',
        ],
        
        // Partner Referrals
        'partner_autoparts_tn' => [
            'vertical' => 'parts_retailer',
            'locale' => 'fr-TN',
            'utm_source' => 'partner',
            'utm_medium' => 'referral',
            'utm_campaign' => 'autoparts_partner_2025',
            'ref' => 'AUTOPARTS',
        ],
    ],
    
    // ═══════════════════════════════════════════════════════════════════════
    // MINI-SITE DOMAINS
    // Custom domains that redirect to signup
    // ═══════════════════════════════════════════════════════════════════════
    
    'mini_sites' => [
        'garagiste.tn' => [
            'redirect_to' => 'https://app.otospex.com/signup',
            'params' => [
                'vertical' => 'mechanic',
                'locale' => 'fr-TN',
                'utm_source' => 'minisite',
                'utm_campaign' => 'garagiste_tn',
            ],
        ],
        'pharmacie-pos.tn' => [
            'redirect_to' => 'https://app.izipos.com/signup',
            'params' => [
                'vertical' => 'pharmacy',
                'locale' => 'fr-TN',
                'utm_source' => 'minisite',
                'utm_campaign' => 'pharmacie_tn',
            ],
        ],
        'logiciel-garage.fr' => [
            'redirect_to' => 'https://app.otospex.com/signup',
            'params' => [
                'vertical' => 'mechanic',
                'locale' => 'fr-FR',
                'utm_source' => 'minisite',
                'utm_campaign' => 'logiciel_garage_fr',
            ],
        ],
    ],
];
```

---

## 13. Security

### Module Access Protection

**CRITICAL:** All module routes MUST be protected with the `module:` middleware.

```php
// ❌ WRONG - Module accessible even if not enabled
Route::middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::apiResource('vehicles', VehicleController::class);
    });

// ✅ CORRECT - Module access enforced
Route::middleware(['api', 'auth:sanctum', 'module:Vehicle'])
    ->group(function () {
        Route::apiResource('vehicles', VehicleController::class);
    });
```

### Route Protection Checklist

| Module | Route File | Middleware Required |
|--------|------------|---------------------|
| Vehicle | `Vehicle/Presentation/routes.php` | `module:Vehicle` |
| Workshop | `Workshop/Presentation/routes.php` | `module:Workshop` |
| Menu | `Menu/Presentation/routes.php` | `module:Menu` |
| Recipe | `Recipe/Presentation/routes.php` | `module:Recipe` |
| Tables | `Tables/Presentation/routes.php` | `module:Tables` |
| BatchExpiry | `BatchExpiry/Presentation/routes.php` | `module:BatchExpiry` |
| Appointments | `Appointments/Presentation/routes.php` | `module:Appointments` |
| Reservations | `Reservations/Presentation/routes.php` | `module:Reservations` |
| Loyalty | `Loyalty/Presentation/routes.php` | `module:Loyalty` |

### Frontend Route Guards

```typescript
// apps/web/src/components/guards/ModuleGuard.tsx

import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import { Navigate } from 'react-router-dom';

interface ModuleGuardProps {
    module: string;
    children: React.ReactNode;
    fallback?: string;
}

export function ModuleGuard({ module, children, fallback = '/' }: ModuleGuardProps) {
    const { hasModule, isLoading } = useCompanyConfig();
    
    if (isLoading) {
        return <LoadingScreen />;
    }
    
    if (!hasModule(module)) {
        return <Navigate to={fallback} replace />;
    }
    
    return <>{children}</>;
}

// Usage in routes:
<Route
    path="/vehicles/*"
    element={
        <ModuleGuard module="Vehicle">
            <VehiclesRoutes />
        </ModuleGuard>
    }
/>
```

---

## 14. Performance & Caching

### Caching Strategy

```php
// Company config is cached for 24 hours
Cache::remember(
    "company_config:{$company->id}",
    now()->addHours(24),
    fn() => $this->computeEffectiveConfig($company)
);

// Vertical config is cached forever (config files don't change at runtime)
Cache::rememberForever(
    "vertical_config:{$vertical}",
    fn() => $this->computeConfig($vertical)
);
```

### Cache Invalidation

```php
// app/Observers/TenantObserver.php

class TenantObserver
{
    public function updated(Tenant $tenant): void
    {
        if ($tenant->wasChanged(['vertical', 'enabled_extras'])) {
            // Clear config cache for all companies in tenant
            $tenant->companies->each(function ($company) {
                Cache::forget("company_config:{$company->id}");
            });
        }
    }
}

// app/Observers/CompanyObserver.php

class CompanyObserver
{
    public function updated(Company $company): void
    {
        if ($company->wasChanged(['feature_overrides', 'vertical_override'])) {
            Cache::forget("company_config:{$company->id}");
        }
    }
}
```

### Database Indexes

```sql
-- Already included in migrations, but for reference:
CREATE INDEX idx_tenants_vertical ON tenants(vertical);
CREATE INDEX idx_companies_vertical_override ON companies(vertical_override) 
    WHERE vertical_override IS NOT NULL;
CREATE INDEX idx_signup_tracking_campaign ON signup_tracking(utm_campaign, created_at);
CREATE INDEX idx_signup_tracking_vertical ON signup_tracking(vertical, created_at);
```

---

## 15. Migration Strategy

### For Existing Production Data

```php
// database/migrations/2025_01_02_000010_backfill_existing_tenants_vertical.php

use App\Enums\Vertical;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // All existing tenants are automotive (Otospex)
        // Set them to 'mechanic' as default
        DB::table('tenants')
            ->whereNull('vertical')
            ->orWhere('vertical', '')
            ->update([
                'vertical' => 'mechanic',
                'enabled_extras' => json_encode([]),
            ]);
        
        // Clear any cached configs
        Cache::flush();
    }
    
    public function down(): void
    {
        // No rollback needed
    }
};
```

### Post-Migration User Communication

After migration, show existing users a one-time modal:

```typescript
// apps/web/src/components/modals/VerticalConfirmationModal.tsx

export function VerticalConfirmationModal() {
    const { tenant } = useTenant();
    const [shown, setShown] = useLocalStorage('vertical_confirmed', false);
    
    if (shown || !tenant.needs_vertical_confirmation) {
        return null;
    }
    
    return (
        <Modal open onClose={() => {}}>
            <h2>Help Us Serve You Better</h2>
            <p>
                We've set your business type to <strong>Mechanic / Garage</strong>.
                Is this correct?
            </p>
            
            <div className="space-y-2">
                <Button onClick={() => confirm('mechanic')}>
                    Yes, I'm a Mechanic / Garage
                </Button>
                <Button variant="outline" onClick={() => showVerticalSelector()}>
                    No, I'm a different type of business
                </Button>
            </div>
        </Modal>
    );
}
```

---

## 16. Testing Requirements

### Unit Tests

```php
// tests/Unit/Services/VerticalConfigServiceTest.php

class VerticalConfigServiceTest extends TestCase
{
    /** @test */
    public function it_returns_config_for_base_vertical(): void
    {
        $service = app(VerticalConfigService::class);
        
        $config = $service->getConfig('mechanic');
        
        $this->assertEquals('otospex', $config['product']);
        $this->assertContains('Vehicle', $config['core_modules']);
        $this->assertContains('Workshop', $config['core_modules']);
    }
    
    /** @test */
    public function it_returns_config_for_inherited_vertical(): void
    {
        $service = app(VerticalConfigService::class);
        
        $config = $service->getConfig('motorcycle_shop');
        
        $this->assertEquals('otospex', $config['product']);
        $this->assertEquals('mechanic', $config['extends']);
    }
    
    /** @test */
    public function it_validates_feature_compatibility(): void
    {
        $service = app(VerticalConfigService::class);
        
        $this->assertTrue($service->isFeatureCompatible('mechanic', 'appointments'));
        $this->assertFalse($service->isFeatureCompatible('mechanic', 'menu'));
    }
    
    /** @test */
    public function it_returns_verticals_for_product(): void
    {
        $service = app(VerticalConfigService::class);
        
        $iziposVerticals = $service->getVerticalsForProduct('izipos');
        
        $this->assertContains('pharmacy', $iziposVerticals);
        $this->assertContains('restaurant', $iziposVerticals);
        $this->assertNotContains('mechanic', $iziposVerticals);
    }
}
```

### Feature Tests

```php
// tests/Feature/SignupTest.php

class SignupTest extends TestCase
{
    /** @test */
    public function it_creates_tenant_with_correct_vertical(): void
    {
        $response = $this->postJson('/api/signup', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Test Pharmacy',
            'vertical' => 'pharmacy',
            'country' => 'TN',
        ]);
        
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('tenants', [
            'name' => 'Test Pharmacy',
            'vertical' => 'pharmacy',
        ]);
    }
    
    /** @test */
    public function it_rejects_incompatible_features(): void
    {
        $response = $this->postJson('/api/signup', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Test Pharmacy',
            'vertical' => 'pharmacy',
            'features' => ['vehicle', 'workshop'], // Incompatible!
            'country' => 'TN',
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('features');
    }
    
    /** @test */
    public function it_redirects_to_correct_product_domain(): void
    {
        // Simulating request to izipos.com with otospex vertical
        $this->app['config']->set('app.product', 'izipos');
        
        $response = $this->getJson('/api/signup?vertical=mechanic');
        
        $response->assertJson([
            'redirect' => 'https://app.otospex.com/signup?vertical=mechanic',
        ]);
    }
}
```

### Testing Matrix

| Combination | Priority | Test Coverage |
|-------------|----------|---------------|
| IziPOS + Pharmacy | High | Full E2E |
| IziPOS + Restaurant | High | Full E2E |
| Otospex + Mechanic | High | Full E2E |
| IziPOS + Coffee Shop | Medium | Feature tests |
| Otospex + Parts Retailer | Medium | Feature tests |
| All others | Low | Unit tests |

---

## 17. Implementation Guide for Claude Code

### Phase 1: Database & Models (Week 1)

**Day 1-2: Migrations**

```bash
# Create migrations in order:
php artisan make:migration add_vertical_to_tenants_table
php artisan make:migration add_feature_overrides_to_companies_table
php artisan make:migration create_signup_tracking_table
php artisan make:migration create_onboarding_checklists_table
php artisan make:migration backfill_existing_tenants_vertical

# Run migrations
php artisan migrate
```

**Day 3-4: Enums & Config Files**

Create in order:
1. `app/Enums/Product.php`
2. `app/Enums/Vertical.php`
3. `config/products.php`
4. `config/verticals.php`
5. `config/verticals_extended.php` (empty for now)
6. `config/marketing.php`

**Day 5: Services**

Create in order:
1. `app/Services/ProductService.php`
2. `app/Services/VerticalConfigService.php`
3. `app/Services/CompanyConfigService.php`
4. `app/DTOs/CompanyConfig.php`

**Verification:**
```bash
php artisan tinker
>>> app(App\Services\VerticalConfigService::class)->getConfig('mechanic')
>>> app(App\Services\VerticalConfigService::class)->getVerticalsForProduct('izipos')
```

### Phase 2: Security & Middleware (Week 1-2)

**Day 6-7: Middleware**

1. Create `app/Http/Middleware/RequireModule.php`
2. Register in `app/Http/Kernel.php`
3. Update ALL module route files to include middleware

**CRITICAL CHECKLIST:**
- [ ] Vehicle routes have `module:Vehicle`
- [ ] Workshop routes have `module:Workshop`
- [ ] Menu routes have `module:Menu`
- [ ] Recipe routes have `module:Recipe`
- [ ] Tables routes have `module:Tables`
- [ ] BatchExpiry routes have `module:BatchExpiry`
- [ ] All future modules follow this pattern

**Verification:**
```bash
# Try accessing Vehicle endpoint without Vehicle module enabled
# Should return 403
```

### Phase 3: Signup Flow (Week 2)

**Day 8-10: Backend**

1. `app/Http/Controllers/Auth/SignupController.php`
2. `app/Http/Requests/SignupRequest.php`
3. `app/Services/SignupService.php`
4. `app/DTOs/SignupData.php`
5. `app/DTOs/SignupResult.php`
6. `app/Events/TenantSignedUp.php`
7. `routes/api.php` - Add signup routes

**Day 11-12: Frontend Signup**

1. `apps/web/src/pages/Signup/SignupPage.tsx`
2. `apps/web/src/pages/Signup/components/VerticalSelectionStep.tsx`
3. `apps/web/src/pages/Signup/components/BusinessDetailsStep.tsx`
4. `apps/web/src/pages/Signup/components/FeatureSelectionStep.tsx`

### Phase 4: Company Config & Navigation (Week 3)

**Day 13-14: Backend API**

1. Add `/api/v1/company/config` endpoint
2. Register model observers for cache invalidation

**Day 15-16: Frontend Context**

1. `apps/web/src/contexts/CompanyConfigContext.tsx`
2. `apps/web/src/contexts/ProductConfigContext.tsx`
3. Update `Sidebar.tsx` for dynamic navigation

**Day 17: Route Guards**

1. `apps/web/src/components/guards/ModuleGuard.tsx`
2. Update all routes to use guards

### Phase 5: POS Variants (Week 3-4)

**Day 18-21: POS Loader**

1. Create `apps/web/src/pages/POS/POSPage.tsx` with variant loader
2. Create basic variant components (can be expanded later):
   - `StandardPOS.tsx`
   - `PharmacyPOS.tsx`
   - `RestaurantPOS.tsx`
   - `QuickServicePOS.tsx`
   - `WorkshopPOS.tsx`

### Phase 6: Testing & Documentation (Week 4)

**Day 22-24: Tests**

1. Unit tests for services
2. Feature tests for signup
3. Feature tests for module access

**Day 25: Documentation**

Generate reference documentation (see next section)

---

## 18. Reference Documentation Template

After implementation, Claude Code should generate this documentation file:

```markdown
# Vertical System - Reference Documentation

Generated: [DATE]
Version: 1.0

## Quick Reference

### Check Current Product
```php
app(ProductService::class)->current(); // 'izipos' or 'otospex'
app(ProductService::class)->isOtospex(); // true/false
```

### Check Company Config
```php
$config = app(CompanyConfigService::class)->getEffectiveConfig($company);
$config->vertical;        // 'pharmacy'
$config->product;         // 'izipos'
$config->modules;         // ['POS', 'Inventory', 'BatchExpiry', ...]
$config->posVariant;      // 'pharmacy'
$config->hasModule('BatchExpiry');  // true
$config->hasPosFeature('batch_selection'); // true
```

### Frontend Config Access
```typescript
const { hasModule, hasPosFeature, config } = useCompanyConfig();

if (hasModule('Vehicle')) {
    // Show vehicle features
}

if (hasPosFeature('batch_selection')) {
    // Show batch selector in POS
}
```

## Adding a New Vertical

1. Add enum value to `app/Enums/Vertical.php`
2. Add config to `config/verticals.php`
3. Add to product's vertical list in `config/products.php`
4. Create POS variant if needed
5. Update tests
6. Clear cache: `php artisan cache:clear`

## Adding a New Feature Extra

1. Add to vertical's `compatible_extras` in `config/verticals.php`
2. Create module if needed
3. Add `module:ModuleName` middleware to routes
4. Update frontend navigation
5. Update tests

## Vertical → Module Mapping

| Vertical | Core Modules | POS Variant |
|----------|--------------|-------------|
| mechanic | Vehicle, Workshop, POS | workshop |
| pharmacy | BatchExpiry, POS | pharmacy |
| restaurant | Menu, Recipe, Tables, POS | restaurant |
| coffee_shop | Menu, POS | quick_service |
| retail | POS | standard |

## Troubleshooting

### Module not accessible
1. Check tenant.vertical includes module
2. Check company.feature_overrides if extra
3. Check route has `module:X` middleware
4. Clear cache: `Cache::forget("company_config:{$companyId}")`

### Wrong POS variant loading
1. Check `config.posVariant` in browser devtools
2. Verify vertical config in `config/verticals.php`
3. Check `POSPage.tsx` variant mapping
```

---

## Summary Checklist

Before marking implementation complete:

### Backend
- [ ] Migrations created and run
- [ ] Enums created (Product, Vertical)
- [ ] Config files created (products, verticals, marketing)
- [ ] Services created (ProductService, VerticalConfigService, CompanyConfigService)
- [ ] DTOs created (CompanyConfig, SignupData, SignupResult)
- [ ] Middleware created and registered (RequireModule)
- [ ] ALL module routes have `module:X` middleware
- [ ] Signup controller and service created
- [ ] Model observers for cache invalidation
- [ ] Existing data backfilled

### Frontend
- [ ] TypeScript types created
- [ ] CompanyConfigContext created
- [ ] ProductConfigContext created
- [ ] Sidebar dynamically filters navigation
- [ ] ModuleGuard component created
- [ ] Routes use ModuleGuard
- [ ] POSPage loads correct variant
- [ ] Signup flow with vertical selection

### Testing
- [ ] Unit tests for services
- [ ] Feature tests for signup
- [ ] Feature tests for module access
- [ ] E2E test for critical paths

### Documentation
- [ ] Reference documentation generated
- [ ] CLAUDE.md updated with vertical info
- [ ] README updated if needed
