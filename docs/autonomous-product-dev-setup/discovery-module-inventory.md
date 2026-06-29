# AutoERP Module Inventory & Completeness Audit

**Date:** 2026-06-15
**Scope:** `apps/api/app/Modules/` -- all 37 modules (Workshop counted as 3 sub-modules)

---

## Module Inventory

| Module | Models | Actions/Services | Routes | Tests | Events | Status |
|--------|--------|-------------------|--------|-------|--------|--------|
| **Accounting** | 5 (Account, JournalEntry, JournalLine, OpeningBalanceBatch, OpeningBalanceImportRow) | 11 services + 6 reports | Yes | 29 (unit+feature) | 6 | **Complete** |
| **Admin** | 0 | 2 (HealthCheck, Monitoring) | Via api.php | 2 | 0 | **Partial** -- admin panel support only, no domain models |
| **BatchExpiry** | 3 (Batch, BatchMovement, BatchStock) | 3 (BatchStock, WriteOff, FEFO) | Yes | 3 | 0 | **Partial** -- core CRUD works, thin test coverage, no events |
| **Billing** | 7 (Invoice, InvoiceItem, Payment, Plan, PlanLimits, Refund, TenantSubscription) | 3 (Invoice, PaymentProvider, PlanEnforcement) | Via api.php (admin only) | 0 | 0 | **Partial** -- no dedicated tests, no events, admin-only routes |
| **Cart** | 2 (CatalogCart, CatalogCartItem) | 3 (Cart, CartConversion, MarketplaceCheckout) | Yes | 3 | 0 | **Partial** -- functional but no events, light tests |
| **Catalog** | 6 (CompositeItem, CompositeItemVariant, Modifier, ModifierGroup, Recipe, RecipeLine) | 3 (Availability, Import, RecipeCost) | Yes | 8 | 0 | **Partial** -- good CRUD, missing events |
| **Communication** | 1 (DocumentAttachment via Media) | 1 (DocumentEmailService) | No routes | 0 | 0 | **Scaffolded** -- only email sending, no tests, no routes |
| **Company** | 8 (Company, CompanyDocument, CompanyHashChain, CompanySequence, FiscalPeriod, FiscalYear, Location, UserCompanyMembership) | 7 (Location, FiscalYear, FiscalPeriod, TaxStatus, etc.) | Yes | 19 | 4 | **Complete** |
| **Compliance** | 3 (AuditEvent, CompanyFraudSettings, FraudAlert) | 7 (Audit, FiscalHash, FraudAlert, AnomalyDetection, NF525, UninvoicedDN) | Yes | 20 | 0 | **Complete** -- heavy testing, cross-module contracts |
| **Contact** | 2 (Contact, PartyContact) | 1 (ContactService) | Yes | 2 | 0 | **Partial** -- basic CRUD, light tests, no events |
| **Coupon** | 2 (Coupon, CouponUsage) | 3 (Application, Management, Validation) | Yes | 2 | 0 | **Partial** -- domain logic solid, thin test coverage |
| **Dashboard** | 0 | 0 | Yes | 0 | 0 | **Scaffolded** -- controller only, no services or tests |
| **Document** | 7 (Document, DocumentLine, DocumentAdditionalCost, DocumentSequence, CreditNoteAllocation, ReturnNoteMetadata, DocumentVehicleContext) | 12 (CreditNote, Posting, Numbering, PDF, FacturX, Conversion pipeline, etc.) | Yes | 57 | 17 | **Complete** -- most tested module, full conversion pipeline |
| **Expense** | 2 (ExpenseCategory, ExpenseMetadata) | 1 (ExpenseService) | Yes | 0 | 0 | **Scaffolded** -- CRUD only, no tests, no events |
| **Identity** | 3 (User, Device, EmailVerificationToken) | 1 (EmailVerification) | Yes | 10 | 0 | **Partial** -- auth works, RBAC tested, no domain events |
| **Import** | 2 (ImportJob, ImportRow) | 5 (Import, MigrationWizard, SpreadsheetParser, ValidationEngine, FailedRowsExport) | Yes | 5 | 2 | **Partial** -- functional with broadcasting, moderate tests |
| **Inventory** | 7 (StockLevel, StockMovement, StockReservation, InventoryCounting, InventoryCountingItem, InventoryCountingAssignment, InventoryCounterMetrics) | 9 (Counting, Reconciliation, GoodsReceipt, LandedCost, WAC, Reservation, etc.) | Yes | 15 | 5 | **Complete** |
| **Loyalty** | 9 (LoyaltyProgram, LoyaltyMember, Tier, EarningRule, Reward, Transaction, Enrollment, StampCardDefinition, MemberStampCard) | 6 (Earning, Enrollment, PointAdjustment, ProgramMgmt, Redemption, TierMgmt) + 6 domain services | Yes | 25 | 17 | **Complete** -- full DDD, repository pattern, extensive tests |
| **Marketplace** | 5 (MarketplaceListing, MarketplaceOrder, MarketplaceOrderLine, MarketplaceSeller, BuyerSellerMapping) | 4 (ListingSync, MarketplaceOrder, Search, PriceComparison) | Yes | 6 | 0 | **Partial** -- functional, no events, moderate tests |
| **Media** | 1 (DocumentAttachment) | 1 (AttachmentService) | Yes | 0 | 0 | **Scaffolded** -- basic file upload, no tests |
| **Menu** | 3 (Menu, MenuCategory, MenuCategoryItem) | 1 (MenuResolution) | Yes | 3 | 0 | **Partial** -- F&B menus, basic tests, no events |
| **POS** | 15 (Terminal, Shift, Receipt, ReceiptLine, ReceiptPayment, Order, OrderLine, Table, Floor, HeldOrder, ZReport, XReport, CashDrawerOperation, GrandtotalEvent, ReceiptPrint) | 15+ (OrderMgmt, ReceiptPayment, ShiftMgmt, TableMgmt, Analytics, Sync, PDF, Hash, etc.) | Yes (5 route files) | 75 | 18 | **Complete** -- largest module, heaviest tested, NF525 fiscal compliance |
| **Partner** | 1 (Partner) | 3 (Partner, InvoiceConsolidation, TaxIdValidation) | Yes | 14 | 3 | **Complete** |
| **PlatformIntegration** | 0 value objects only | 4 (BarcodeLookup, CatalogBrowse, ProductSubmission, PlatformHttpClient) | Yes | 12 | 0 | **Partial** -- integration layer, well tested for what it does |
| **Pricing** | 3 (PriceList, PriceListItem, PartnerPriceList) | 1 (PricingService) | Yes | 1 | 0 | **Scaffolded** -- models + basic service, minimal tests |
| **Product** | 12 (Product, Category, ProductImage, EnrichmentResult, AutomotiveProductMetadata + 3 sub-models, ParapharmacyProductMetadata, Ingredient, HealthClaim, KeyComponent, Certification) | 7 (Product, Margin, Image, Tombstone, Enrichment, etc.) | Yes | 27 | 5 | **Complete** -- multi-vertical support (auto, parapharmacy) |
| **Progression** | 0 (DTOs only) | 1 (ProgressionService) | Yes | 14 | 0 | **Partial** -- onboarding advisor, relies on external HTTP client |
| **Promotion** | 2 (Promotion, PromotionUsage) | 3 (CartPromotion, PromotionMgmt, PromotionEvaluation) | Yes | 3 | 0 | **Partial** -- domain logic exists, thin tests, no events |
| **PurchaseHub** | 0 | 1 (PurchaseHubService) | Yes | 2 | 0 | **Scaffolded** -- marketplace purchasing, minimal implementation |
| **Scheduling** | 5 (Appointment, AppointmentReminder, AppointmentService, AppointmentStatusTransition, Bay, ScheduleConfig) | 7 (Authoring, Conversion, Reminder, Transition, Capacity, CQRS queries) | Yes | 26 | 6 | **Complete** -- full CQRS, state machine, storefront booking |
| **Service** | 2 (Service, ServiceCategory) | 1 (ServiceCatalogService) | Yes | 9 | 0 | **Partial** -- clean CRUD, good tests, no events |
| **SmartPrompts** | 0 | 1 (SmartPromptsService) | Yes | 2 | 0 | **Scaffolded** -- AI recommendation proxy, external HTTP client |
| **Taxation** | 7 (DocumentTaxDetail, StampDutyRule, TaxConfiguration, VatPeriod, VatPeriodBreakdown, WithholdingCertificate, WithholdingTaxRule, SalesWithholdingTracking) | 10 (TaxCalc, VatPeriodMgmt, VatReport, VatExport, StampDuty, Withholding, TEJ, CertificatePDF, HashChain) | Yes | 27 | 6 | **Complete** -- multi-country (TN, FR, UK), full fiscal compliance |
| **Tenant** | 2 (Tenant, Domain) | 2 (TenantInitialization, OnboardingChecklist) | Yes | 5 | 0 | **Partial** -- core multi-tenancy, tested, no events |
| **Treasury** | 7 (Payment, PaymentAllocation, PaymentInstrument, PaymentMethod, PaymentRepository, BankReconciliation, BankReconciliationItem, CountryPaymentSettings) | 7 (PaymentAllocation, Tolerance, BankReconciliation, MultiPayment, Refund, VendorRefund, CloseWithTolerance) | Yes | 28 | 11 | **Complete** -- heavy tolerance/discount logic, well tested |
| **Uom** | 2 (Unit, UnitCategory) | 1 (UnitConversionService) | Yes | 3 | 0 | **Partial** -- clean DDD, basic tests |
| **Vehicle** | 3 (Vehicle, VehicleMileageReading, VehicleOwnership) | 3 (Mileage, Ownership, ContextBuilder) | Yes | 18 | 5 | **Complete** |
| **Workshop/Bundle** | 3 (ServiceBundle, ServiceBundleComponent, ServiceBundleVehicleApplicability) | 5 (Authoring, Expansion, Resolution, CQRS commands/queries) | Yes | 22 | 0 | **Complete** -- cycle detection, pricing modes, well tested |
| **Workshop/Technician** | 5 (TechnicianProfile, TechnicianCertification, TechnicianCertificationAttachment, TechnicianTimeEntry, TechnicianTimeOff) | 3 (Availability, TimeEntry, WeeklyHours) | Yes | 18 | 4 | **Complete** -- event-driven time tracking, payroll export |
| **Workshop/WorkOrder** | 4 (WorkOrder, WorkOrderLine, WorkOrderAssignment, WorkOrderStatusTransition) | 8 (Creation, Authoring, Line, Bundle, Assignment, Transition + CQRS commands) | Yes | 21 | 15 | **Complete** -- full state machine, document generation adapter |

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| **Total modules** | 37 (Workshop = 3 sub-modules) |
| **Complete** | 18 |
| **Partial** | 14 |
| **Scaffolded** | 5 (Communication, Dashboard, Expense, Media, Pricing) |
| **Total test files** | ~545 |
| **Total domain events** | ~147 |
| **Modules with routes** | 35 (all except Communication and Admin/Billing which use api.php) |
| **Migrations** | 330 |

---

## Cross-Module Contracts (`app/Shared/Contracts/`)

| Contract | Purpose |
|----------|---------|
| `AccountingServiceInterface` | GL posting from Document/POS/Inventory modules |
| `InventoryServiceInterface` | Stock operations from Document/POS modules |
| `ProductServiceInterface` | Product lookups from Catalog/POS/Document |
| `ProductInventoryQueryInterface` | Stock level queries across modules |
| `LocationServiceInterface` | Location resolution from Inventory/POS |
| `LoyaltyServiceInterface` | Points earning/redemption from POS |
| `PartnerServiceInterface` | Partner lookups from Document/POS |
| `PlatformSubmissionInterface` | Product submission to external platforms |
| `EnrichmentQueryInterface` | Product enrichment data queries |
| `CurrencyScaleResolverInterface` | Currency precision across all financial modules |
| `RepositoryInterface` | Base repository pattern |
| `SellableContract` | Sellable item abstraction (Product, Service, CompositeItem) |
| `StockDeductionStrategyInterface` | FIFO/FEFO/LIFO strategy for inventory |
| `CompositeItemServiceInterface` | Composite item resolution from POS/Document |
| `CompanyVerticalQueryContract` | Vertical-specific feature gating |
| `Nf525DataProviderContract` | NF525 fiscal data from POS to Compliance |
| `PaymentToleranceCheckerContract` | Payment tolerance from Treasury to Document |

---

## Modules With Zero Tests (Risk Areas)

1. **Billing** -- subscription/payment provider logic untested
2. **Communication** -- email sending untested
3. **Dashboard** -- aggregation logic untested
4. **Expense** -- basic CRUD untested
5. **Media** -- file upload untested

---

## Modules With No Domain Events (Integration Gaps)

These modules lack event-driven integration, meaning changes aren't broadcast to other modules:

BatchExpiry, Billing, Cart, Catalog, Communication, Contact, Coupon, Dashboard, Expense, Identity, Marketplace, Media, Menu, PlatformIntegration, Pricing, Progression, Promotion, PurchaseHub, Service, SmartPrompts, Tenant, Uom

**Notable gaps:**
- **Catalog** -- CompositeItem/Recipe changes should notify POS and Inventory
- **Marketplace** -- Order lifecycle should trigger Document/Inventory
- **Promotion** -- Activation/deactivation should notify POS terminals
- **Billing** -- Subscription changes should trigger tenant feature gating

---

## Architecture Notes

- **Pattern:** Clean Architecture (Domain/Application/Infrastructure/Presentation) consistently applied
- **DDD maturity varies:** Loyalty, Workshop, Scheduling, Treasury use full DDD (aggregates, value objects, repository interfaces, domain services). Simpler modules like Contact, Media, Dashboard use anemic models.
- **Workshop** is a bounded context with 3 sub-modules (Bundle, Technician, WorkOrder), each with its own service provider and routes -- the most sophisticated module structure.
- **POS** is the densest module: 15 models, 18 events, 75 tests, 5 route files, NF525 fiscal compliance, hash chain integrity.
- **Document** has the most complete conversion pipeline: Quote -> SalesOrder -> DeliveryNote -> Invoice -> CreditNote, with FacturX e-invoicing.
