# Multi-App Architecture & Vertical Scaffolding

**Version:** 1.0  
**Purpose:** How the platform supports multiple products (IziPOS, Otospex) and business verticals from a single codebase

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Product Configuration](#2-product-configuration)
3. [Business Verticals](#3-business-verticals)
4. [Module Visibility System](#4-module-visibility-system)
5. [Signup & Onboarding Flow](#5-signup--onboarding-flow)
6. [Database Considerations](#6-database-considerations)
7. [Frontend Theming](#7-frontend-theming)
8. [Implementation Guide](#8-implementation-guide)

---

## 1. Architecture Overview

### Single Codebase, Multiple Products

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         SINGLE CODEBASE                                  │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │                    CORE MODULES (85%)                               │ │
│  │  Identity │ Company │ Product │ Partner │ Document │ Inventory     │ │
│  │  Treasury │ Accounting │ POS │ Communication │ Media               │ │
│  │                                                                     │ │
│  │  → Used by ALL products and verticals                              │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                          │
│  ┌─────────────────────┐          ┌─────────────────────┐              │
│  │  OTOSPEX MODULES    │          │  IZIPOS MODULES     │              │
│  │  (15%)              │          │  (Future)           │              │
│  │                     │          │                     │              │
│  │  • Vehicle          │          │  • Menu             │              │
│  │  • Workshop         │          │  • Recipe           │              │
│  │                     │          │  • Tables           │              │
│  │  → Automotive only  │          │  → F&B only         │              │
│  └─────────────────────┘          └─────────────────────┘              │
│                                                                          │
│         ┌─────────────────┐        ┌─────────────────┐                 │
│         │    IZIPOS       │        │    OTOSPEX      │                 │
│         │    Product      │        │    Product      │                 │
│         │                 │        │                 │        DEPLOYED │
│         │ • Blue theme    │        │ • Orange theme  │        AS       │
│         │ • Retail focus  │        │ • Auto focus    │                 │
│         │ • Generic ERP   │        │ • Vehicle ERP   │                 │
│         └─────────────────┘        └─────────────────┘                 │
└─────────────────────────────────────────────────────────────────────────┘
```

### Key Principles

1. **Same Docker image** for both products
2. **APP_PRODUCT** environment variable determines product
3. **Business vertical** stored per company in database
4. **Modules load conditionally** based on product + vertical
5. **Visual theming** via CSS variables, not separate codebases

---

## 2. Product Configuration

### Environment Variables

```env
# .env
APP_PRODUCT=izipos    # or 'otospex'
APP_NAME="IziPOS"     # Display name
APP_THEME=blue        # or 'orange' for Otospex
```

### Product Configuration File

```php
// config/product.php
return [
    'products' => [
        'izipos' => [
            'name' => 'IziPOS',
            'tagline' => 'Smart POS & ERP for Modern Business',
            'theme' => 'blue',
            'logo' => 'izipos-logo.svg',
            'favicon' => 'izipos-favicon.ico',
            'landing_page' => 'https://izipos.com',
            'support_email' => 'support@izipos.com',
            'default_vertical' => 'retail',
            'available_verticals' => [
                'retail',
                'pharmacy',
                'coffee_shop',
                'restaurant',
                'services',
            ],
            'hidden_modules' => [
                'Vehicle',
                'Workshop',
            ],
        ],
        'otospex' => [
            'name' => 'Otospex',
            'tagline' => 'Complete Automotive Business Solution',
            'theme' => 'orange',
            'logo' => 'otospex-logo.svg',
            'favicon' => 'otospex-favicon.ico',
            'landing_page' => 'https://otospex.com',
            'support_email' => 'support@otospex.com',
            'default_vertical' => 'mechanic',
            'available_verticals' => [
                'mechanic',
                'body_shop',
                'parts_retailer',
                'tire_shop',
                'car_wash',
            ],
            'hidden_modules' => [
                'Menu',
                'Recipe',
                'Tables',
            ],
        ],
    ],
];
```

### Product Helper Service

```php
// app/Services/ProductService.php
class ProductService
{
    public function current(): string
    {
        return config('app.product', 'izipos');
    }
    
    public function config(?string $key = null): mixed
    {
        $product = $this->current();
        $config = config("product.products.{$product}");
        
        return $key ? data_get($config, $key) : $config;
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
        return $this->config('available_verticals');
    }
    
    public function isModuleHidden(string $module): bool
    {
        return in_array($module, $this->config('hidden_modules', []));
    }
}
```

---

## 3. Business Verticals

### Vertical Definitions

```php
// app/Enums/BusinessVertical.php
enum BusinessVertical: string
{
    // IziPOS Verticals
    case RETAIL = 'retail';
    case PHARMACY = 'pharmacy';
    case COFFEE_SHOP = 'coffee_shop';
    case RESTAURANT = 'restaurant';
    case SERVICES = 'services';
    
    // Otospex Verticals
    case MECHANIC = 'mechanic';
    case BODY_SHOP = 'body_shop';
    case PARTS_RETAILER = 'parts_retailer';
    case TIRE_SHOP = 'tire_shop';
    case CAR_WASH = 'car_wash';
    
    public function label(): string
    {
        return match($this) {
            self::RETAIL => 'Retail Store',
            self::PHARMACY => 'Pharmacy / Parapharmacy',
            self::COFFEE_SHOP => 'Coffee Shop / Café',
            self::RESTAURANT => 'Restaurant / Fast Food',
            self::SERVICES => 'Service Business',
            self::MECHANIC => 'Auto Mechanic',
            self::BODY_SHOP => 'Body Shop',
            self::PARTS_RETAILER => 'Auto Parts Retailer',
            self::TIRE_SHOP => 'Tire Shop',
            self::CAR_WASH => 'Car Wash',
        };
    }
    
    public function icon(): string
    {
        return match($this) {
            self::RETAIL => 'shopping-bag',
            self::PHARMACY => 'pill',
            self::COFFEE_SHOP => 'coffee',
            self::RESTAURANT => 'utensils',
            self::SERVICES => 'briefcase',
            self::MECHANIC => 'wrench',
            self::BODY_SHOP => 'car',
            self::PARTS_RETAILER => 'cog',
            self::TIRE_SHOP => 'circle',
            self::CAR_WASH => 'droplet',
        };
    }
    
    public function requiredModules(): array
    {
        return match($this) {
            self::PHARMACY => ['BatchExpiry'],
            self::COFFEE_SHOP, self::RESTAURANT => ['Menu', 'Recipe'],
            self::MECHANIC, self::BODY_SHOP => ['Vehicle', 'Workshop'],
            self::PARTS_RETAILER => ['Vehicle'], // For compatibility lookup
            default => [],
        };
    }
    
    public function optionalModules(): array
    {
        return match($this) {
            self::RESTAURANT => ['Tables', 'Reservations'],
            self::MECHANIC, self::BODY_SHOP => ['Appointments'],
            default => [],
        };
    }
    
    public function features(): array
    {
        return match($this) {
            self::PHARMACY => [
                'batch_tracking' => true,
                'expiry_management' => true,
                'prescription_tracking' => false, // Future
                'controlled_substances' => false, // Future
            ],
            self::COFFEE_SHOP => [
                'quick_sale_mode' => true,
                'modifiers' => true, // Extra shot, size, etc.
                'combo_deals' => true,
            ],
            self::RESTAURANT => [
                'table_management' => true,
                'kitchen_display' => true, // Future
                'course_management' => true,
                'split_bill' => true,
            ],
            self::MECHANIC => [
                'vehicle_history' => true,
                'work_orders' => true,
                'labor_tracking' => true,
                'parts_compatibility' => true,
            ],
            default => [],
        };
    }
}
```

### Vertical Configuration Matrix

| Vertical | Batch/Expiry | Menu/Recipe | Tables | Vehicle | Workshop | Special Features |
|----------|--------------|-------------|--------|---------|----------|------------------|
| Retail | Optional | ❌ | ❌ | ❌ | ❌ | - |
| Pharmacy | ✅ Required | ❌ | ❌ | ❌ | ❌ | FEFO, Expiry alerts |
| Coffee Shop | Optional | ✅ Required | Optional | ❌ | ❌ | Quick sale, Modifiers |
| Restaurant | Optional | ✅ Required | ✅ Required | ❌ | ❌ | Courses, Split bill |
| Services | ❌ | ❌ | ❌ | ❌ | ❌ | Appointments |
| Mechanic | Optional | ❌ | ❌ | ✅ Required | ✅ Required | Work orders |
| Body Shop | Optional | ❌ | ❌ | ✅ Required | ✅ Required | Estimates |
| Parts Retailer | Optional | ❌ | ❌ | ✅ Required | ❌ | Compatibility lookup |
| Tire Shop | Optional | ❌ | ❌ | ✅ Required | Optional | Size matching |
| Car Wash | ❌ | ❌ | ❌ | Optional | ❌ | Packages, Subscriptions |

---

## 4. Module Visibility System

### Module Registry

```php
// config/modules.php
return [
    'modules' => [
        // Core - Always loaded
        'Identity' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Company' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Product' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Partner' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Document' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Inventory' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Treasury' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Accounting' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'POS' => [
            'visibility' => 'universal',
            'required' => false,
        ],
        'Communication' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        'Media' => [
            'visibility' => 'universal',
            'required' => true,
        ],
        
        // Vertical-specific
        'BatchExpiry' => [
            'visibility' => 'universal',  // Available to all, enabled per vertical
            'required' => false,
            'required_for_verticals' => ['pharmacy'],
        ],
        'Menu' => [
            'visibility' => 'izipos',
            'required' => false,
            'required_for_verticals' => ['coffee_shop', 'restaurant'],
        ],
        'Recipe' => [
            'visibility' => 'izipos',
            'required' => false,
            'required_for_verticals' => ['coffee_shop', 'restaurant'],
        ],
        'Tables' => [
            'visibility' => 'izipos',
            'required' => false,
            'required_for_verticals' => ['restaurant'],
        ],
        'Vehicle' => [
            'visibility' => 'otospex',
            'required' => false,
            'required_for_verticals' => ['mechanic', 'body_shop', 'parts_retailer', 'tire_shop'],
        ],
        'Workshop' => [
            'visibility' => 'otospex',
            'required' => false,
            'required_for_verticals' => ['mechanic', 'body_shop'],
        ],
        
        // Compliance - Universal but country-specific
        'TN_EInvoice' => [
            'visibility' => 'universal',
            'required' => false,
            'required_for_countries' => ['TN'],
        ],
        'TN_Withholding' => [
            'visibility' => 'universal',
            'required' => false,
            'required_for_countries' => ['TN'],
        ],
        'FR_NF525' => [
            'visibility' => 'universal',
            'required' => false,
            'required_for_countries' => ['FR'],
        ],
    ],
];
```

### Module Loader Service

```php
// app/Services/ModuleLoaderService.php
class ModuleLoaderService
{
    public function __construct(
        private ProductService $productService,
        private CompanyService $companyService
    ) {}
    
    public function getEnabledModules(?Company $company = null): array
    {
        $allModules = config('modules.modules');
        $product = $this->productService->current();
        $enabled = [];
        
        foreach ($allModules as $module => $config) {
            if ($this->shouldLoadModule($module, $config, $product, $company)) {
                $enabled[] = $module;
            }
        }
        
        return $enabled;
    }
    
    private function shouldLoadModule(
        string $module,
        array $config,
        string $product,
        ?Company $company
    ): bool {
        // Check product visibility
        $visibility = $config['visibility'];
        if ($visibility !== 'universal' && $visibility !== $product) {
            return false;
        }
        
        // Check if hidden for this product
        if ($this->productService->isModuleHidden($module)) {
            return false;
        }
        
        // Always load required modules
        if ($config['required'] ?? false) {
            return true;
        }
        
        // Check if required for company's vertical
        if ($company) {
            $vertical = $company->business_vertical;
            $requiredForVerticals = $config['required_for_verticals'] ?? [];
            
            if (in_array($vertical, $requiredForVerticals)) {
                return true;
            }
            
            // Check country requirements
            $requiredForCountries = $config['required_for_countries'] ?? [];
            if (in_array($company->country_code, $requiredForCountries)) {
                return true;
            }
            
            // Check if company explicitly enabled it
            if ($company->hasModuleEnabled($module)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function getNavigationItems(?Company $company = null): array
    {
        $enabledModules = $this->getEnabledModules($company);
        $navigation = [];
        
        foreach ($enabledModules as $module) {
            $navConfig = config("modules.navigation.{$module}");
            if ($navConfig) {
                $navigation = array_merge($navigation, $navConfig);
            }
        }
        
        return $navigation;
    }
}
```

---

## 5. Signup & Onboarding Flow

### 5.1 Landing Page Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         LANDING PAGE                                     │
│                    (izipos.com or otospex.com)                          │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                    "Start Your Free Trial"                       │   │
│  │                                                                   │   │
│  │    ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐          │   │
│  │    │ Retail  │  │Pharmacy │  │ Coffee  │  │Restaurant│          │   │
│  │    │  Store  │  │         │  │  Shop   │  │         │          │   │
│  │    │   🛍️    │  │   💊    │  │   ☕    │  │   🍽️    │          │   │
│  │    └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘          │   │
│  │         │            │            │            │                 │   │
│  │         └────────────┴─────┬──────┴────────────┘                 │   │
│  │                            │                                      │   │
│  │                            ▼                                      │   │
│  │                    [Select Your Business]                         │   │
│  │                            │                                      │   │
│  │                            ▼                                      │   │
│  │              Redirect to /signup?vertical=pharmacy                │   │
│  └───────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Signup Process

```
Step 1: Basic Info (from landing page)
┌─────────────────────────────────────────┐
│         Create Your Account              │
│                                          │
│  Business Type: [Pharmacy] (pre-filled)  │
│                                          │
│  Email: [________________________]       │
│  Password: [____________________]        │
│  Confirm: [_____________________]        │
│                                          │
│  [x] I agree to Terms & Conditions       │
│                                          │
│          [Create Account]                │
└─────────────────────────────────────────┘

Step 2: Email Verification
┌─────────────────────────────────────────┐
│      Check Your Email                    │
│                                          │
│  We've sent a verification link to       │
│  your@email.com                          │
│                                          │
│  [Resend Email]   [Change Email]         │
└─────────────────────────────────────────┘

Step 3: Business Details
┌─────────────────────────────────────────┐
│      Tell Us About Your Business         │
│                                          │
│  Business Name: [____________________]   │
│                                          │
│  Country: [Tunisia 🇹🇳 ▼]                │
│                                          │
│  Tax ID (optional): [________________]   │
│                                          │
│  Phone: [________________________]       │
│                                          │
│  Address: [______________________]       │
│           [______________________]       │
│                                          │
│          [Continue]                      │
└─────────────────────────────────────────┘

Step 4: Feature Selection (based on vertical)
┌─────────────────────────────────────────┐
│      Customize Your Setup                │
│                                          │
│  Based on your business type (Pharmacy), │
│  we've pre-selected these features:      │
│                                          │
│  [x] Point of Sale                       │
│  [x] Inventory Management                │
│  [x] Batch & Expiry Tracking ⭐          │
│      (Recommended for pharmacies)        │
│  [ ] Multi-Location                      │
│  [ ] Customer Loyalty Program            │
│                                          │
│  These can be changed later in settings. │
│                                          │
│          [Complete Setup]                │
└─────────────────────────────────────────┘

Step 5: Onboarding Wizard
┌─────────────────────────────────────────┐
│      Let's Get You Started! 🎉           │
│                                          │
│  ○───○───●───○───○                       │
│  1   2   3   4   5                       │
│                                          │
│  Step 3: Add Your First Products         │
│                                          │
│  [Import from Excel]                     │
│  [Add Manually]                          │
│  [Skip for Now]                          │
│                                          │
└─────────────────────────────────────────┘
```

### 5.3 Signup API Flow

```php
// app/Http/Controllers/Auth/RegisterController.php

public function register(RegisterRequest $request): JsonResponse
{
    DB::transaction(function () use ($request) {
        // 1. Create User
        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        
        // 2. Create Tenant
        $tenant = Tenant::create([
            'name' => $request->business_name,
            'owner_id' => $user->id,
        ]);
        
        // 3. Create Company with vertical
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => $request->business_name,
            'country_code' => $request->country,
            'business_vertical' => $request->vertical, // From landing page
            'tax_id' => $request->tax_id,
            'settings' => $this->getDefaultSettings($request->vertical),
            'enabled_modules' => $this->getDefaultModules($request->vertical),
        ]);
        
        // 4. Assign owner role
        $user->assignRole('owner');
        $user->companies()->attach($company->id, ['role' => 'owner']);
        
        // 5. Seed default data based on vertical
        $this->seedDefaultData($company, $request->vertical);
        
        // 6. Create onboarding checklist
        $this->createOnboardingChecklist($company, $request->vertical);
    });
    
    // Send verification email
    // Return response with redirect to onboarding
}

private function getDefaultModules(string $vertical): array
{
    $vertical = BusinessVertical::from($vertical);
    
    return array_merge(
        ['Identity', 'Company', 'Product', 'Partner', 'Document', 'Inventory', 'Treasury'],
        $vertical->requiredModules()
    );
}

private function seedDefaultData(Company $company, string $vertical): void
{
    // Seed chart of accounts for country
    (new ChartOfAccountsSeeder($company))->run();
    
    // Seed tax rates for country
    (new TaxRateSeeder($company))->run();
    
    // Seed vertical-specific defaults
    match ($vertical) {
        'pharmacy' => (new PharmacyDefaultsSeeder($company))->run(),
        'coffee_shop' => (new CoffeeShopDefaultsSeeder($company))->run(),
        'restaurant' => (new RestaurantDefaultsSeeder($company))->run(),
        'mechanic' => (new MechanicDefaultsSeeder($company))->run(),
        default => null,
    };
}
```

### 5.4 Onboarding Checklist

```php
// Database: onboarding_checklists
Schema::create('onboarding_checklists', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained();
    $table->string('step_key');          // 'add_products', 'setup_pos', etc.
    $table->string('title');
    $table->text('description')->nullable();
    $table->boolean('is_required');
    $table->boolean('is_completed')->default(false);
    $table->timestamp('completed_at')->nullable();
    $table->integer('order');
    $table->timestamps();
});

// Onboarding steps per vertical
$pharmacySteps = [
    ['step_key' => 'verify_email', 'title' => 'Verify your email', 'required' => true],
    ['step_key' => 'company_details', 'title' => 'Complete business details', 'required' => true],
    ['step_key' => 'add_products', 'title' => 'Add your first products', 'required' => false],
    ['step_key' => 'setup_batches', 'title' => 'Set up batch tracking', 'required' => true], // Pharmacy-specific
    ['step_key' => 'expiry_alerts', 'title' => 'Configure expiry alerts', 'required' => false],
    ['step_key' => 'setup_pos', 'title' => 'Set up your POS terminal', 'required' => false],
    ['step_key' => 'invite_team', 'title' => 'Invite team members', 'required' => false],
];

$restaurantSteps = [
    ['step_key' => 'verify_email', 'title' => 'Verify your email', 'required' => true],
    ['step_key' => 'company_details', 'title' => 'Complete business details', 'required' => true],
    ['step_key' => 'create_menu', 'title' => 'Create your menu', 'required' => true], // Restaurant-specific
    ['step_key' => 'setup_tables', 'title' => 'Set up your floor plan', 'required' => false],
    ['step_key' => 'setup_pos', 'title' => 'Set up your POS terminal', 'required' => false],
    ['step_key' => 'invite_team', 'title' => 'Invite team members', 'required' => false],
];
```

---

## 6. Database Considerations

### Company Table Extensions

```sql
-- Add to companies table
ALTER TABLE companies ADD COLUMN business_vertical VARCHAR(50) NOT NULL DEFAULT 'retail';
ALTER TABLE companies ADD COLUMN enabled_modules JSONB NOT NULL DEFAULT '[]';
ALTER TABLE companies ADD COLUMN onboarding_completed_at TIMESTAMP;
ALTER TABLE companies ADD COLUMN vertical_settings JSONB DEFAULT '{}';

-- Index for querying by vertical
CREATE INDEX idx_companies_vertical ON companies(business_vertical);
```

### Company Model Extension

```php
// app/Modules/Company/Domain/Company.php
class Company extends Model
{
    protected $casts = [
        'business_vertical' => BusinessVertical::class,
        'enabled_modules' => 'array',
        'vertical_settings' => 'array',
        'onboarding_completed_at' => 'datetime',
    ];
    
    public function hasModuleEnabled(string $module): bool
    {
        return in_array($module, $this->enabled_modules);
    }
    
    public function enableModule(string $module): void
    {
        if (!$this->hasModuleEnabled($module)) {
            $this->enabled_modules = [...$this->enabled_modules, $module];
            $this->save();
        }
    }
    
    public function disableModule(string $module): void
    {
        $this->enabled_modules = array_filter(
            $this->enabled_modules,
            fn($m) => $m !== $module
        );
        $this->save();
    }
    
    public function getVerticalFeature(string $feature): mixed
    {
        return $this->business_vertical->features()[$feature] ?? null;
    }
    
    public function isOnboardingComplete(): bool
    {
        return $this->onboarding_completed_at !== null;
    }
}
```

---

## 7. Frontend Theming

### CSS Variables

```css
/* resources/css/themes/izipos.css */
:root {
    --color-primary: #2563eb;        /* Blue */
    --color-primary-dark: #1d4ed8;
    --color-primary-light: #3b82f6;
    --color-accent: #06b6d4;         /* Cyan */
    --brand-gradient: linear-gradient(135deg, #2563eb 0%, #06b6d4 100%);
    --logo-url: url('/images/izipos-logo.svg');
}

/* resources/css/themes/otospex.css */
:root {
    --color-primary: #ea580c;        /* Orange */
    --color-primary-dark: #c2410c;
    --color-primary-light: #f97316;
    --color-accent: #eab308;         /* Yellow */
    --brand-gradient: linear-gradient(135deg, #ea580c 0%, #eab308 100%);
    --logo-url: url('/images/otospex-logo.svg');
}
```

### Theme Provider (React)

```typescript
// resources/js/contexts/ThemeContext.tsx
interface ThemeConfig {
    product: 'izipos' | 'otospex';
    name: string;
    logo: string;
    primaryColor: string;
}

const ThemeContext = createContext<ThemeConfig | null>(null);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
    const config = useAppConfig(); // From backend
    
    useEffect(() => {
        // Load theme CSS
        import(`../css/themes/${config.product}.css`);
        
        // Set document title
        document.title = config.name;
    }, [config.product]);
    
    return (
        <ThemeContext.Provider value={config}>
            {children}
        </ThemeContext.Provider>
    );
}

export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) throw new Error('useTheme must be used within ThemeProvider');
    return context;
}
```

### Conditional Navigation

```typescript
// resources/js/components/Navigation/Sidebar.tsx
export function Sidebar() {
    const { enabledModules } = useCompany();
    const { product } = useTheme();
    
    const navigationItems = useMemo(() => {
        const items = [
            // Always shown
            { name: 'Dashboard', href: '/', icon: Home },
            { name: 'Products', href: '/products', icon: Package },
            { name: 'Sales', href: '/sales', icon: ShoppingCart },
        ];
        
        // Module-specific items
        if (enabledModules.includes('POS')) {
            items.push({ name: 'POS', href: '/pos', icon: CreditCard });
        }
        
        if (enabledModules.includes('BatchExpiry')) {
            items.push({ name: 'Batches', href: '/batches', icon: Layers });
        }
        
        if (enabledModules.includes('Vehicle')) {
            items.push({ name: 'Vehicles', href: '/vehicles', icon: Car });
        }
        
        if (enabledModules.includes('Menu')) {
            items.push({ name: 'Menu', href: '/menu', icon: BookOpen });
        }
        
        if (enabledModules.includes('Tables')) {
            items.push({ name: 'Tables', href: '/tables', icon: Grid });
        }
        
        return items;
    }, [enabledModules]);
    
    return (
        <nav>
            {navigationItems.map(item => (
                <NavItem key={item.href} {...item} />
            ))}
        </nav>
    );
}
```

---

## 8. Implementation Guide

### Step 1: Add Database Columns

```bash
php artisan make:migration add_vertical_to_companies_table
```

```php
public function up(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->string('business_vertical', 50)->default('retail')->after('country_code');
        $table->jsonb('enabled_modules')->default('[]')->after('business_vertical');
        $table->jsonb('vertical_settings')->default('{}')->after('enabled_modules');
        $table->timestamp('onboarding_completed_at')->nullable()->after('vertical_settings');
    });
}
```

### Step 2: Create Configuration Files

1. Create `config/product.php`
2. Create `config/modules.php`
3. Create `BusinessVertical` enum

### Step 3: Create Services

1. `ProductService` - Current product info
2. `ModuleLoaderService` - Module visibility logic
3. `OnboardingService` - Checklist management

### Step 4: Update Signup Flow

1. Add vertical parameter to registration
2. Create vertical selection component
3. Implement onboarding checklist UI

### Step 5: Update Navigation

1. Create `ThemeProvider`
2. Update `Sidebar` to filter by enabled modules
3. Add theme CSS files

### Step 6: Test Scenarios

1. Sign up as IziPOS Pharmacy → Batch/Expiry enabled
2. Sign up as IziPOS Restaurant → Menu/Tables enabled
3. Sign up as Otospex Mechanic → Vehicle/Workshop enabled
4. Verify hidden modules not accessible

---

## Placeholders for Future Modules

### Tunisia E-Invoice Module

```
Module: TN_EInvoice
Location: app/Modules/Compliance/Tunisia/EInvoice/
Spec Document: TN-EINVOICE-SPEC.md (To be created)
Status: 🔵 Specification pending - user providing documentation

Integration Points:
- Document module (Invoice, Credit Note generation)
- Partner module (Customer tax info)
- Accounting module (Tax reporting)

Configuration:
- visibility: 'universal'
- required_for_countries: ['TN']
```

### Tunisia Withholding Module

```
Module: TN_Withholding
Location: app/Modules/Compliance/Tunisia/Withholding/
Spec Document: TAX-WITHHOLDING-SPEC.md (Created)
Status: ✅ Specification ready

Integration Points:
- Treasury module (Payment processing)
- Partner module (Supplier tax settings)
- Document module (Certificate generation)
```

---

## Quick Reference

### Check Current Product

```php
app(ProductService::class)->current(); // 'izipos' or 'otospex'
app(ProductService::class)->isOtospex(); // true/false
```

### Check Company Vertical

```php
$company->business_vertical; // BusinessVertical::PHARMACY
$company->business_vertical->requiredModules(); // ['BatchExpiry']
```

### Check Module Availability

```php
$loader = app(ModuleLoaderService::class);
$loader->getEnabledModules($company); // ['Identity', 'Product', ..., 'BatchExpiry']
```

### Frontend Module Check

```typescript
const { enabledModules } = useCompany();
if (enabledModules.includes('BatchExpiry')) {
    // Show batch features
}
```
