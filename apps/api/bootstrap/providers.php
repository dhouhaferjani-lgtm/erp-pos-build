<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\BatchExpiry\BatchExpiryServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Cart\Providers\CartServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Company\CompanyServiceProvider;
use App\Modules\Compliance\Providers\ComplianceServiceProvider;
use App\Modules\Contact\Providers\ContactServiceProvider;
use App\Modules\Coupon\Providers\CouponServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Document\Providers\DocumentServiceProvider;
use App\Modules\Expense\Providers\ExpenseServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Import\Providers\ImportServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Loyalty\Providers\LoyaltyServiceProvider;
use App\Modules\Marketplace\Providers\MarketplaceServiceProvider;
use App\Modules\Media\MediaServiceProvider;
use App\Modules\Menu\Providers\MenuServiceProvider;
use App\Modules\Partner\PartnerServiceProvider;
use App\Modules\PlatformIntegration\Providers\PlatformIntegrationServiceProvider;
use App\Modules\POS\Providers\HeldOrderServiceProvider;
use App\Modules\POS\Providers\KitchenServiceProvider;
use App\Modules\POS\Providers\OrderServiceProvider;
use App\Modules\POS\Providers\POSServiceProvider;
use App\Modules\POS\Providers\TableServiceProvider;
use App\Modules\Pricing\Providers\PricingServiceProvider;
use App\Modules\Product\ProductServiceProvider;
use App\Modules\Progression\Providers\ProgressionServiceProvider;
use App\Modules\Promotion\Providers\PromotionServiceProvider;
use App\Modules\PurchaseHub\Providers\PurchaseHubServiceProvider;
use App\Modules\Service\Providers\ServiceModuleServiceProvider;
use App\Modules\SmartPrompts\Providers\SmartPromptsServiceProvider;
use App\Modules\Taxation\Providers\TaxationServiceProvider;
use App\Modules\Tenant\Infrastructure\Providers\TenantServiceProvider;
use App\Modules\Treasury\Providers\TreasuryServiceProvider;
use App\Modules\Uom\Infrastructure\Providers\UomServiceProvider;
use App\Modules\Vehicle\Providers\VehicleServiceProvider;
use App\Modules\Workshop\Technician\TechnicianServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\EventServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    EventServiceProvider::class,
    IdentityServiceProvider::class,
    TenantServiceProvider::class,
    CompanyServiceProvider::class,
    PartnerServiceProvider::class,
    ProductServiceProvider::class,
    VehicleServiceProvider::class,
    DocumentServiceProvider::class,
    AccountingServiceProvider::class,
    InventoryServiceProvider::class,
    BatchExpiryServiceProvider::class,
    TreasuryServiceProvider::class,
    ComplianceServiceProvider::class,
    ImportServiceProvider::class,
    DashboardServiceProvider::class,
    MediaServiceProvider::class,
    PricingServiceProvider::class,
    ServiceModuleServiceProvider::class,
    BillingServiceProvider::class,
    ExpenseServiceProvider::class,
    TaxationServiceProvider::class,
    POSServiceProvider::class,
    HeldOrderServiceProvider::class,
    OrderServiceProvider::class,
    TableServiceProvider::class,
    KitchenServiceProvider::class,
    UomServiceProvider::class,
    LoyaltyServiceProvider::class,
    CatalogServiceProvider::class,
    MenuServiceProvider::class,
    PromotionServiceProvider::class,
    CouponServiceProvider::class,
    PlatformIntegrationServiceProvider::class,
    ContactServiceProvider::class,
    MarketplaceServiceProvider::class,
    PurchaseHubServiceProvider::class,
    CartServiceProvider::class,
    ProgressionServiceProvider::class,
    SmartPromptsServiceProvider::class,
    TechnicianServiceProvider::class,
];
