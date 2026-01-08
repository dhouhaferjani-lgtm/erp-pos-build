# AutoERP Multi-App Scaffolding - Final Implementation Plan

**Version:** 1.0 Final
**Date:** 2025-12-30
**Status:** ✅ Ready for Implementation
**Based on:** MULTI-APP-SCAFFOLDING-V2.md + SCAFFOLDING-CLARIFICATIONS.md

---

## Executive Summary

This document provides the **finalized, implementation-ready** specification for the multi-app, multi-vertical architecture supporting **IziPOS** and **Otospex** products with 12 business verticals.

**Key Architectural Decisions:**
- ✅ Vertical stored on **Tenant** (not Company)
- ✅ Product determined by **APP_PRODUCT environment variable**
- ✅ Module dependencies **auto-resolved** from config
- ✅ Security enforced via **RequireModule middleware**
- ✅ Single **StandardPOS** component for Phase 1
- ✅ Performance optimized with **24-hour caching**

**Timeline:** 4 weeks (phased implementation)

---

## Part 1: Architecture Overview

### 1.1 Products & Verticals

**2 Products:**
- **Otospex** (automotive) - 6 verticals
- **IziPOS** (retail/service) - 6 verticals

**12 Verticals:**

| Product | Vertical | POS Variant | Core Modules |
|---------|----------|-------------|--------------|
| **Otospex** | mechanic | workshop | Vehicle, Workshop, POS |
| **Otospex** | body_shop | body_shop | Vehicle, Workshop, POS |
| **Otospex** | parts_retailer | parts_counter | Vehicle, POS |
| **Otospex** | car_glass | glass_specialist | Vehicle, POS |
| **Otospex** | tire_shop | tire_shop | Vehicle, POS |
| **Otospex** | service_station | express | Vehicle, POS |
| **IziPOS** | pharmacy | pharmacy | BatchExpiry, POS |
| **IziPOS** | parapharmacy | parapharmacy | BatchExpiry, POS |
| **IziPOS** | restaurant | restaurant | Menu, Recipe, Tables, POS |
| **IziPOS** | coffee_shop | quick_service | Menu, POS |
| **IziPOS** | retail | standard | POS |
| **IziPOS** | fashion | boutique | POS |

---

### 1.2 Product Detection

**Simple Environment Variable Approach:**

```yaml
# docker-compose.yml
services:
  izipos:
    environment:
      APP_PRODUCT: izipos
      APP_NAME: "IziPOS"
      APP_URL: https://app.izipos.com

  otospex:
    environment:
      APP_PRODUCT: otospex
      APP_NAME: "Otospex"
      APP_URL: https://app.otospex.com
```

**Backend:**
```php
// config/app.php
'product' => env('APP_PRODUCT', 'izipos'),

// ProductService.php
public function current(): Product
{
    return Product::from(config('app.product'));
}
```

---

### 1.3 Tenant-Level Vertical (Simplified)

**Decision:** Vertical stored ONLY on Tenant (not Company).

```
Tenant (vertical = 'mechanic')
  └─ Company 1 (Paris location) - inherits 'mechanic'
  └─ Company 2 (Lyon location) - inherits 'mechanic'
```

**Future Enhancement (NOT Phase 1):** Tenant switching for multi-vertical businesses.

---

## Part 2: Database Schema

### 2.1 Tenants Table

```php
// Migration: add_vertical_to_tenants_table.php

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
```

---

### 2.2 Companies Table

**No changes needed** - Companies inherit vertical from Tenant.

**Removed:** `vertical_override` column (per clarifications).

---

### 2.3 Signup Tracking Table

```php
// Migration: create_signup_tracking_table.php

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

    // Device info
    $table->string('device_type', 20)->nullable();
    $table->char('country_code', 2)->nullable();

    // Conversion tracking
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('email_verified_at')->nullable();
    $table->timestamp('first_sale_at')->nullable();

    // Indexes
    $table->index(['utm_campaign', 'created_at']);
    $table->index(['vertical', 'created_at']);
});
```

---

### 2.4 Onboarding Checklists Table

```php
// Migration: create_onboarding_checklists_table.php

Schema::create('onboarding_checklists', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
    $table->string('step_key', 50);
    $table->string('title', 100');
    $table->boolean('is_required')->default(false);
    $table->boolean('is_completed')->default(false);
    $table->timestamp('completed_at')->nullable();
    $table->unsignedSmallInteger('order')->default(0);
    $table->timestamps();

    $table->unique(['tenant_id', 'step_key']);
    $table->index(['tenant_id', 'is_completed']);
});
```

---

## Part 3: Configuration Files

### 3.1 Product Configuration

**File:** `config/products.php`

```php
<?php

use App\Enums\Product;

return [
    'izipos' => [
        'name' => 'IziPOS',
        'tagline' => 'Point of Sale Solution for Every Business',
        'theme' => 'blue',
        'primary_color' => '#3b82f6',
        'logo' => '/logos/izipos.svg',
        'favicon' => '/favicons/izipos.ico',
        'app_domain' => env('IZIPOS_DOMAIN', 'app.izipos.com'),
        'support_email' => 'support@izipos.com',
        'available_verticals' => [
            'pharmacy',
            'parapharmacy',
            'restaurant',
            'coffee_shop',
            'retail',
            'fashion',
        ],
    ],

    'otospex' => [
        'name' => 'Otospex',
        'tagline' => 'Automotive Business Management',
        'theme' => 'orange',
        'primary_color' => '#f97316',
        'logo' => '/logos/otospex.svg',
        'favicon' => '/favicons/otospex.ico',
        'app_domain' => env('OTOSPEX_DOMAIN', 'app.otospex.com'),
        'support_email' => 'support@otospex.com',
        'available_verticals' => [
            'mechanic',
            'body_shop',
            'parts_retailer',
            'car_glass',
            'tire_shop',
            'service_station',
        ],
    ],
];
```

---

### 3.2 Vertical Configuration

**File:** `config/verticals.php`

*Full configuration per V2 document (lines 531-1338)*

**Key Structure:**
```php
'mechanic' => [
    'product' => 'otospex',
    'label' => 'Mechanic / Garage',
    'description' => 'General automotive repair',
    'icon' => 'wrench',

    'core_modules' => [
        'Identity', 'Company', 'Settings', 'Product', 'Partner',
        'Document', 'Inventory', 'Treasury', 'Accounting',
        'Communication', 'Media',
        'Vehicle',
        'Workshop',
        'POS',
    ],

    'pos_variant' => 'workshop',
    'pos_features' => [
        'vehicle_selector' => true,
        'labor_time_tracking' => true,
        'job_card_printing' => true,
    ],

    'compatible_extras' => [
        'appointments' => ['label' => 'Appointment Booking', 'module' => 'Appointments'],
        'fleet_management' => ['label' => 'Fleet Management', 'module' => 'Fleet'],
    ],

    'incompatible' => ['menu', 'tables', 'recipe'],

    'onboarding_steps' => [
        ['key' => 'company_details', 'title' => 'Business Details', 'required' => true],
        ['key' => 'import_vehicles', 'title' => 'Import Vehicles', 'required' => false],
        ['key' => 'setup_pos', 'title' => 'Set Up POS', 'required' => false],
    ],

    'defaults' => [
        'tax_included_in_price' => false,
        'show_labor_separate' => true,
    ],
],
```

---

### 3.3 Module Dependencies

**File:** `config/modules.php` (NEW)

```php
<?php

return [
    // Module dependency graph
    'dependencies' => [
        'Recipe' => ['Product', 'Inventory'],
        'Workshop' => ['Vehicle', 'Inventory'],
        'Tables' => ['Menu'],
        'Fleet' => ['Vehicle', 'Partner'],
        'Appointments' => ['Partner'],
        'Reservations' => ['Menu', 'Tables'],
    ],

    // Module loading order (optional - for future use)
    'loading_order' => [
        'Identity' => 10,
        'Company' => 20,
        'Product' => 30,
        'Partner' => 30,
        'Vehicle' => 40,
        'Workshop' => 50,
        'Menu' => 40,
        'Recipe' => 50,
    ],
];
```

---

## Part 4: Backend Implementation

### 4.1 Enums

**File:** `app/Enums/Product.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Product: string
{
    case IziPOS = 'izipos';
    case Otospex = 'otospex';

    public function name(): string
    {
        return match($this) {
            self::IziPOS => 'IziPOS',
            self::Otospex => 'Otospex',
        };
    }

    public function theme(): string
    {
        return match($this) {
            self::IziPOS => 'blue',
            self::Otospex => 'orange',
        };
    }
}
```

**File:** `app/Enums/Vertical.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Vertical: string
{
    // Otospex
    case Mechanic = 'mechanic';
    case BodyShop = 'body_shop';
    case PartsRetailer = 'parts_retailer';
    case CarGlass = 'car_glass';
    case TireShop = 'tire_shop';
    case ServiceStation = 'service_station';

    // IziPOS
    case Pharmacy = 'pharmacy';
    case Parapharmacy = 'parapharmacy';
    case Restaurant = 'restaurant';
    case CoffeeShop = 'coffee_shop';
    case Retail = 'retail';
    case Fashion = 'fashion';

    public function product(): Product
    {
        return match($this) {
            self::Mechanic,
            self::BodyShop,
            self::PartsRetailer,
            self::CarGlass,
            self::TireShop,
            self::ServiceStation => Product::Otospex,

            self::Pharmacy,
            self::Parapharmacy,
            self::Restaurant,
            self::CoffeeShop,
            self::Retail,
            self::Fashion => Product::IziPOS,
        };
    }

    public function label(): string
    {
        return config("verticals.{$this->value}.label");
    }
}
```

---

### 4.2 Services

**File:** `app/Services/ProductService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Product;

class ProductService
{
    public function current(): Product
    {
        return Product::from(config('app.product'));
    }

    public function isOtospex(): bool
    {
        return $this->current() === Product::Otospex;
    }

    public function isIziPOS(): bool
    {
        return $this->current() === Product::IziPOS;
    }

    public function config(string $key = null): mixed
    {
        $product = $this->current()->value;

        if ($key === null) {
            return config("products.{$product}");
        }

        return config("products.{$product}.{$key}");
    }
}
```

---

**File:** `app/Services/VerticalConfigService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Vertical;
use App\Enums\Product;
use Illuminate\Support\Facades\Cache;

class VerticalConfigService
{
    public function getConfig(string|Vertical $vertical): array
    {
        $verticalValue = $vertical instanceof Vertical ? $vertical->value : $vertical;

        return Cache::rememberForever(
            "vertical_config:{$verticalValue}",
            fn() => $this->normalizeConfig($verticalValue)
        );
    }

    private function normalizeConfig(string $vertical): array
    {
        $config = config("verticals.{$vertical}");

        if (!$config) {
            throw new \InvalidArgumentException("Unknown vertical: {$vertical}");
        }

        return array_merge([
            'vertical' => $vertical,
            'core_modules' => [],
            'pos_variant' => 'standard',
            'pos_features' => [],
            'databases' => [],
            'compatible_extras' => [],
            'incompatible' => [],
            'onboarding_steps' => [],
            'defaults' => [],
        ], $config);
    }

    public function getVerticalsForProduct(string|Product $product): array
    {
        $productValue = $product instanceof Product ? $product->value : $product;

        return config("products.{$productValue}.available_verticals", []);
    }

    public function isFeatureCompatible(string $vertical, string $feature): bool
    {
        $config = $this->getConfig($vertical);

        if (in_array($feature, $config['incompatible'])) {
            return false;
        }

        return isset($config['compatible_extras'][$feature]);
    }

    public function getEnabledModules(string $vertical, array $enabledExtras = []): array
    {
        $config = $this->getConfig($vertical);
        $modules = $config['core_modules'];

        // Add extra modules
        foreach ($enabledExtras as $extra) {
            if ($this->isFeatureCompatible($vertical, $extra)) {
                $extraConfig = $config['compatible_extras'][$extra];
                if (isset($extraConfig['module']) && $extraConfig['module']) {
                    $modules[] = $extraConfig['module'];
                }
            }
        }

        // Resolve dependencies
        return $this->resolveDependencies(array_unique($modules));
    }

    private function resolveDependencies(array $modules): array
    {
        $dependencies = config('modules.dependencies', []);
        $resolved = $modules;

        foreach ($modules as $module) {
            if (isset($dependencies[$module])) {
                foreach ($dependencies[$module] as $dep) {
                    if (!in_array($dep, $resolved)) {
                        $resolved[] = $dep;
                    }
                }
            }
        }

        return array_unique($resolved);
    }

    public function clearCache(): void
    {
        foreach (Vertical::cases() as $vertical) {
            Cache::forget("vertical_config:{$vertical->value}");
        }
    }
}
```

---

**File:** `app/Services/CompanyConfigService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CompanyConfig;
use App\Modules\Company\Domain\Company;
use Illuminate\Support\Facades\Cache;

class CompanyConfigService
{
    public function __construct(
        private VerticalConfigService $verticalService,
    ) {}

    public function getEffectiveConfig(Company $company): CompanyConfig
    {
        return Cache::remember(
            "company_config:{$company->id}",
            now()->addHours(24),
            fn() => $this->computeEffectiveConfig($company)
        );
    }

    private function computeEffectiveConfig(Company $company): CompanyConfig
    {
        $tenant = $company->tenant;
        $vertical = $tenant->vertical->value;

        $verticalConfig = $this->verticalService->getConfig($vertical);

        // Merge tenant extras + company extras
        $enabledExtras = array_unique(array_merge(
            $tenant->enabled_extras ?? [],
            $company->feature_overrides ?? []
        ));

        // Filter to only compatible extras
        $validExtras = array_filter(
            $enabledExtras,
            fn($extra) => $this->verticalService->isFeatureCompatible($vertical, $extra)
        );

        // Get all enabled modules (including dependencies)
        $modules = $this->verticalService->getEnabledModules($vertical, $validExtras);

        return new CompanyConfig(
            vertical: $vertical,
            product: $verticalConfig['product'],
            modules: $modules,
            posVariant: $verticalConfig['pos_variant'],
            posFeatures: $verticalConfig['pos_features'],
            posLayout: $verticalConfig['pos_layout'] ?? [],
            databases: $verticalConfig['databases'],
            enabledExtras: array_values($validExtras),
            defaults: $verticalConfig['defaults'],
            documentTypes: $verticalConfig['document_types'] ?? [],
        );
    }

    public function clearCache(Company $company): void
    {
        Cache::forget("company_config:{$company->id}");
    }
}
```

---

### 4.3 DTOs

**File:** `app/DTOs/CompanyConfig.php`

```php
<?php

declare(strict_types=1);

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
}
```

---

### 4.4 Middleware

**File:** `app/Http/Middleware/RequireModule.php`

```php
<?php

declare(strict_types=1);

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

**Register in:** `bootstrap/app.php`

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'module' => \App\Http\Middleware\RequireModule::class,
    ]);
})
```

---

### 4.5 Model Updates

**File:** `app/Modules/Tenant/Domain/Tenant.php`

```php
// Add to fillable
protected $fillable = [
    // ... existing
    'vertical',
    'enabled_extras',
    'signup_source',
    'signup_tracking',
];

// Add to casts
protected function casts(): array
{
    return [
        // ... existing
        'vertical' => Vertical::class,
        'enabled_extras' => 'array',
        'signup_tracking' => 'array',
    ];
}

// Add helper methods
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
```

---

### 4.6 Model Observers

**File:** `app/Observers/TenantObserver.php`

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Cache;

class TenantObserver
{
    public function updated(Tenant $tenant): void
    {
        if ($tenant->wasChanged(['vertical', 'enabled_extras'])) {
            // Clear company config cache for all companies
            $tenant->companies->each(function ($company) {
                Cache::forget("company_config:{$company->id}");
            });
        }
    }
}
```

**Register in:** `AppServiceProvider.php`

```php
use App\Modules\Tenant\Domain\Tenant;
use App\Observers\TenantObserver;

public function boot(): void
{
    Tenant::observe(TenantObserver::class);
}
```

---

## Part 5: Frontend Implementation

### 5.1 Contexts

**File:** `apps/web/src/contexts/CompanyConfigContext.tsx`

```typescript
import { createContext, useContext, ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';

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
}

const CompanyConfigContext = createContext<CompanyConfigContextType | null>(null);

export function CompanyConfigProvider({ children }: { children: ReactNode }) {
    const { data: config, isLoading } = useQuery({
        queryKey: ['company-config'],
        queryFn: () => apiGet<CompanyConfig>('/api/v1/company/config'),
        staleTime: 1000 * 60 * 60, // 1 hour
    });

    const hasModule = (module: string) => config?.modules.includes(module) ?? false;
    const hasPosFeature = (feature: string) => config?.posFeatures[feature] ?? false;
    const hasExtra = (extra: string) => config?.enabledExtras.includes(extra) ?? false;
    const getDefault = <T,>(key: string, fallback?: T) => (config?.defaults[key] ?? fallback) as T;

    return (
        <CompanyConfigContext.Provider value={{
            config: config ?? null,
            isLoading,
            hasModule,
            hasPosFeature,
            hasExtra,
            getDefault,
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

---

### 5.2 Dynamic Navigation

**File:** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

```typescript
import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import { useMemo } from 'react';
import {
    Home, Package, Users, FileText, Warehouse, CreditCard,
    Calculator, Car, Wrench, Coffee, Utensils, Layers, Settings
} from 'lucide-react';

interface NavItem {
    name: string;
    href: string;
    icon: React.ComponentType;
    module?: string;
}

export function Sidebar() {
    const { hasModule, config } = useCompanyConfig();

    const navigationItems: NavItem[] = useMemo(() => {
        const items: NavItem[] = [
            // Core (always visible)
            { name: 'Dashboard', href: '/', icon: Home },
            { name: 'Products', href: '/products', icon: Package },
            { name: 'Partners', href: '/partners', icon: Users },
            { name: 'Documents', href: '/documents', icon: FileText },
            { name: 'Inventory', href: '/inventory', icon: Warehouse },
            { name: 'Treasury', href: '/treasury', icon: Calculator },

            // POS
            { name: 'Point of Sale', href: '/pos', icon: CreditCard, module: 'POS' },

            // Otospex modules
            { name: 'Vehicles', href: '/vehicles', icon: Car, module: 'Vehicle' },
            { name: 'Workshop', href: '/workshop', icon: Wrench, module: 'Workshop' },

            // IziPOS modules
            { name: 'Menu', href: '/menu', icon: Coffee, module: 'Menu' },
            { name: 'Recipes', href: '/recipes', icon: Utensils, module: 'Recipe' },
            { name: 'Batches & Expiry', href: '/batches', icon: Layers, module: 'BatchExpiry' },

            // Settings (always visible)
            { name: 'Settings', href: '/settings', icon: Settings },
        ];

        return items.filter(item => !item.module || hasModule(item.module));
    }, [hasModule]);

    return (
        <nav className="flex flex-col gap-1 p-4">
            {navigationItems.map((item) => (
                <NavLink key={item.href} to={item.href}>
                    <item.icon className="h-5 w-5" />
                    {item.name}
                </NavLink>
            ))}
        </nav>
    );
}
```

---

### 5.3 Module Guard

**File:** `apps/web/src/components/guards/ModuleGuard.tsx`

```typescript
import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import { Navigate } from 'react-router-dom';
import { LoadingScreen } from '@/components/atoms/LoadingScreen';

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
```

**Usage:**
```typescript
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

### 5.4 POS Variant Loader (Phase 1)

**File:** `apps/web/src/pages/POS/POSPage.tsx`

```typescript
import { useCompanyConfig } from '@/contexts/CompanyConfigContext';
import StandardPOS from './StandardPOS';
import { LoadingScreen } from '@/components/atoms/LoadingScreen';

export function POSPage() {
    const { config, isLoading } = useCompanyConfig();

    if (isLoading || !config) {
        return <LoadingScreen />;
    }

    // Phase 1: Use StandardPOS for all, styled per vertical
    return <StandardPOS variant={config.posVariant} features={config.posFeatures} />;
}
```

---

## Part 6: Security Checklist

### 6.1 Module Route Protection

**CRITICAL:** Every vertical-specific module MUST have middleware protection.

**Checklist:**

- [ ] Vehicle routes → `middleware(['module:Vehicle'])`
- [ ] Workshop routes → `middleware(['module:Workshop'])`
- [ ] Menu routes → `middleware(['module:Menu'])`
- [ ] Recipe routes → `middleware(['module:Recipe'])`
- [ ] Tables routes → `middleware(['module:Tables'])`
- [ ] BatchExpiry routes → `middleware(['module:BatchExpiry'])`
- [ ] Fleet routes → `middleware(['module:Fleet'])`
- [ ] Appointments routes → `middleware(['module:Appointments'])`

**Example:**
```php
// app/Modules/Vehicle/Presentation/routes.php

Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:Vehicle'])
    ->prefix('api/v1')
    ->group(function () {
        Route::apiResource('vehicles', VehicleController::class);
    });
```

---

## Part 7: Implementation Timeline

### Week 1: Database & Core Services

**Day 1-2: Migrations**
```bash
php artisan make:migration add_vertical_to_tenants_table
php artisan make:migration create_signup_tracking_table
php artisan make:migration create_onboarding_checklists_table
php artisan migrate
```

**Day 3-4: Enums & Config**
- Create `app/Enums/Product.php`
- Create `app/Enums/Vertical.php`
- Create `config/products.php`
- Create `config/verticals.php`
- Create `config/modules.php`

**Day 5: Services**
- `ProductService`
- `VerticalConfigService`
- `CompanyConfigService`
- DTOs

**Verification:**
```bash
php artisan tinker
>>> app(App\Services\VerticalConfigService::class)->getConfig('mechanic')
>>> app(App\Services\VerticalConfigService::class)->getEnabledModules('mechanic', ['appointments'])
```

---

### Week 2: Security & Signup

**Day 6-7: Middleware**
- Create `RequireModule` middleware
- Register in `bootstrap/app.php`
- Update ALL module routes

**Day 8-9: Signup Backend**
- `SignupController`
- `SignupService`
- `SignupRequest`
- Routes

**Day 10: Signup Frontend**
- Signup page with vertical selection
- Vertical-specific onboarding flow

**Verification:**
```bash
# Test signup with different verticals
curl -X POST /api/signup -d '{"vertical":"pharmacy",...}'

# Test module access without middleware (should fail)
curl -X GET /api/v1/vehicles -H "Authorization: Bearer token"
# Expected: 403 Forbidden
```

---

### Week 3: Company Config & Navigation

**Day 11-12: Backend API**
- `GET /api/v1/company/config` endpoint
- Model observers
- Cache invalidation

**Day 13-14: Frontend Context**
- `CompanyConfigContext`
- `ProductConfigContext`
- Dynamic Sidebar

**Day 15: Route Guards**
- `ModuleGuard` component
- Apply to all routes

**Verification:**
```bash
# Login as mechanic vertical user
# Check navigation shows Vehicles, Workshop
# Login as pharmacy vertical user
# Check navigation shows Batches, not Vehicles
```

---

### Week 4: POS & Testing

**Day 16-17: POS Implementation**
- StandardPOS component
- Variant styling system
- Feature toggling

**Day 18-20: Testing**
- Unit tests for services
- Feature tests for signup
- Feature tests for module access
- E2E tests for critical paths

**Day 21: Documentation**
- Update CLAUDE.md
- Generate reference docs
- Update README

**Verification:**
```bash
php artisan test
pnpm test
```

---

## Part 8: Testing Strategy

### 8.1 Unit Tests

**File:** `tests/Unit/Services/VerticalConfigServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Services\VerticalConfigService;
use Tests\TestCase;

class VerticalConfigServiceTest extends TestCase
{
    private VerticalConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VerticalConfigService::class);
    }

    public function test_returns_config_for_vertical(): void
    {
        $config = $this->service->getConfig('mechanic');

        $this->assertEquals('otospex', $config['product']);
        $this->assertContains('Vehicle', $config['core_modules']);
        $this->assertContains('Workshop', $config['core_modules']);
    }

    public function test_resolves_module_dependencies(): void
    {
        $modules = $this->service->getEnabledModules('restaurant', []);

        // Recipe depends on Product, Inventory
        $this->assertContains('Recipe', $modules);
        $this->assertContains('Product', $modules);
        $this->assertContains('Inventory', $modules);
    }

    public function test_validates_feature_compatibility(): void
    {
        $this->assertTrue($this->service->isFeatureCompatible('mechanic', 'appointments'));
        $this->assertFalse($this->service->isFeatureCompatible('mechanic', 'menu'));
    }
}
```

---

### 8.2 Feature Tests

**File:** `tests/Feature/SignupTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SignupTest extends TestCase
{
    public function test_creates_tenant_with_correct_vertical(): void
    {
        $response = $this->postJson('/api/signup', [
            'email' => 'test@pharmacy.com',
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

    public function test_rejects_incompatible_extras(): void
    {
        $response = $this->postJson('/api/signup', [
            'email' => 'test@pharmacy.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Test Pharmacy',
            'vertical' => 'pharmacy',
            'extras' => ['vehicle', 'workshop'], // Incompatible!
            'country' => 'TN',
        ]);

        $response->assertStatus(422);
    }
}
```

---

### 8.3 Testing Matrix

| Vertical | Priority | Test Type |
|----------|----------|-----------|
| mechanic | High | Full E2E |
| pharmacy | High | Full E2E |
| restaurant | High | Full E2E |
| coffee_shop | Medium | Feature |
| parts_retailer | Medium | Feature |
| retail | Low | Unit |
| Others | Low | Unit |

---

## Part 9: Deployment

### 9.1 Docker Compose

```yaml
version: '3.8'

services:
  izipos-api:
    build: .
    environment:
      APP_PRODUCT: izipos
      APP_NAME: IziPOS
      APP_URL: https://app.izipos.com
      DB_HOST: postgres
      REDIS_HOST: redis
    labels:
      - "traefik.http.routers.izipos.rule=Host(`app.izipos.com`)"
      - "traefik.http.services.izipos.loadbalancer.server.port=8000"

  otospex-api:
    build: .
    environment:
      APP_PRODUCT: otospex
      APP_NAME: Otospex
      APP_URL: https://app.otospex.com
      DB_HOST: postgres
      REDIS_HOST: redis
    labels:
      - "traefik.http.routers.otospex.rule=Host(`app.otospex.com`)"
      - "traefik.http.services.otospex.loadbalancer.server.port=8000"

  postgres:
    image: postgres:16-alpine

  redis:
    image: redis:7-alpine
```

---

## Part 10: Final Checklist

### Backend
- [ ] Migrations created and run
- [ ] Enums created (Product, Vertical)
- [ ] Config files created (products, verticals, modules)
- [ ] Services created (ProductService, VerticalConfigService, CompanyConfigService)
- [ ] DTOs created (CompanyConfig)
- [ ] Middleware created (RequireModule)
- [ ] **ALL module routes have `module:X` middleware**
- [ ] Model observers for cache invalidation
- [ ] Tenant model updated

### Frontend
- [ ] CompanyConfigContext created
- [ ] Sidebar dynamically filters navigation
- [ ] ModuleGuard component created
- [ ] Routes use ModuleGuard
- [ ] POSPage loads StandardPOS
- [ ] Signup flow with vertical selection

### Testing
- [ ] Unit tests for services
- [ ] Feature tests for signup
- [ ] Feature tests for module access
- [ ] E2E tests for high-priority verticals

### Documentation
- [ ] CLAUDE.md updated
- [ ] Reference documentation generated

---

## Summary

This final implementation plan is **ready for execution**. All architectural decisions have been clarified, and the 4-week timeline provides a clear path from database schema to production deployment.

**Key Success Factors:**
1. Follow the timeline strictly (week by week)
2. Complete verification steps after each phase
3. Ensure middleware protection on ALL vertical-specific routes
4. Test each vertical configuration before moving to next phase

**Ready to begin Week 1.**
