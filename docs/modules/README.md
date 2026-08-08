# Module Reference

> Detailed documentation for all 22 backend modules.

---

## Module Categories

### Core Modules
| Module | Purpose | Key Entities |
|--------|---------|--------------|
| [Identity](#identity-module) | Authentication, users, RBAC | User, Device, Role |
| [Tenant](#tenant-module) | Multi-tenancy management | Tenant, Domain |
| [Company](#company-module) | Company, locations, fiscal config | Company, Location, FiscalYear |

### Business Modules
| Module | Purpose | Key Entities |
|--------|---------|--------------|
| [Document](#document-module) | Unified document system | Document, DocumentLine |
| [Partner](#partner-module) | Customers and suppliers | Partner, Contact |
| [Product](#product-module) | Product catalog | Product, Category |
| [Service](#service-module) | Service catalog | Service, ServiceCategory |
| [Vehicle](#vehicle-module) | Vehicle management | Vehicle |
| [Pricing](#pricing-module) | Price lists | PriceList, PriceRule |

### Operations Modules
| Module | Purpose | Key Entities |
|--------|---------|--------------|
| [Inventory](#inventory-module) | Stock management | StockLevel, StockMovement, InventoryCounting |
| [Treasury](#treasury-module) | Payments and instruments | Payment, PaymentMethod, PaymentInstrument |
| [Accounting](#accounting-module) | Chart of accounts, GL | Account, JournalEntry |
| [Workshop / Work Orders](./workshop-work-orders.md) | Workshop job lifecycle and state machine | WorkOrder, WorkOrderLine, WorkOrderAssignment |

### Platform Modules
| Module | Purpose | Key Entities |
|--------|---------|--------------|
| [Billing](#billing-module) | SaaS subscriptions | Plan, TenantSubscription, BillingInvoice |
| [Admin](#admin-module) | System monitoring | HealthCheck, Metrics |
| [Compliance](#compliance-module) | Fiscal compliance | HashChain, AuditLog |
| [Import](#import-module) | Data imports | ImportJob, ImportRow |
| [Media](#media-module) | File attachments | Media, Attachment |
| [Communication](#communication-module) | Email/SMS dispatch | EmailLog, Template |
| [Dashboard](#dashboard-module) | Aggregations | DashboardMetric |

---

## Identity Module

**Location**: `app/Modules/Identity/`

### Purpose
Handles user authentication, authorization, and device management.

### Entities

#### User
```php
// Key fields
'id'              // UUID primary key
'name'            // Full name
'email'           // Unique email
'status'          // UserStatus enum
'password'        // Hashed
'email_verified_at'
```

#### Device
```php
// User devices for session management
'user_id'
'device_identifier'
'device_type'
'push_token'
'last_active_at'
```

### Enums
- `UserStatus`: pending_verification, active, suspended

### Controllers
- `AuthController` - Login, logout, refresh token, me
- `UserController` - CRUD operations
- `RoleController` - Role and permission management

### Key Routes
```
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
POST   /api/v1/auth/refresh
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/roles
```

---

## Company Module

**Location**: `app/Modules/Company/`

### Purpose
Manages company entities, locations, fiscal years, and configuration.

### Entities

#### Company
```php
'id', 'name', 'legal_name', 'tax_id'
'invoice_prefix', 'invoice_next_number'
'quote_prefix', 'quote_next_number'
'sales_order_prefix', 'sales_order_next_number'
'inventory_costing_method'  // weighted_average, fifo, lifo
'default_target_margin', 'default_minimum_margin'
'allow_below_cost_sales'
'fiscal_chain_seed'  // 256-bit seed for hash chain
```

#### Location
```php
'company_id', 'name', 'type'  // LocationType enum
'address', 'city', 'country'
'is_default'
```

#### FiscalYear / FiscalPeriod
```php
// Fiscal year management
'company_id', 'name', 'start_date', 'end_date'
'is_closed'
// Periods for monthly/quarterly closing
```

### Enums
- `CompanyStatus`: active, suspended, closed
- `LocationType`: warehouse, shop, office, service_center
- `MembershipRole`: owner, admin, user
- `PeriodStatus`: open, closing, closed

---

## Document Module

**Location**: `app/Modules/Document/`

### Purpose
Unified system for all commercial documents: quotes, orders, invoices, credit notes, delivery notes, purchase orders.

### Design Decision
Single `documents` table with `type` discriminator instead of separate tables per document type. This enables:
- Shared business logic
- Easy document conversions
- Unified search and reporting

### Entities

#### Document (Header)
```php
'id', 'company_id', 'type'  // DocumentType enum
'status'                     // DocumentStatus enum
'partner_id', 'partner_name', 'partner_address'
'number', 'date', 'due_date'
'subtotal', 'tax_amount', 'total'
'currency', 'exchange_rate'
'notes', 'internal_notes'
// Fiscal fields (for posted invoices)
'hash', 'previous_hash', 'chain_sequence'
'fiscal_status'  // FiscalStatus enum
// Conversion tracking
'source_document_id', 'source_document_type'
```

#### DocumentLine
```php
'document_id', 'line_number'
'product_id', 'description'
'quantity', 'unit_price', 'discount_percent'
'tax_rate', 'line_total'
// Cost tracking
'unit_cost', 'cost_at_sale'
```

#### DocumentAdditionalCost
```php
// For landed cost calculations
'document_id', 'description', 'amount'
'allocation_method'  // by_value, by_quantity, by_weight
```

### Enums
- `DocumentType`: quote, sales_order, invoice, credit_note, delivery_note, purchase_order
- `DocumentStatus`: draft, confirmed, posted, cancelled
- `FiscalStatus`: draft, approved, posted
- `FiscalCategory`: revenue, expense

### Domain Services
- `DocumentPostingService` - Posts documents, creates GL entries, updates hash chain
- `DocumentConversionService` - Converts documents (Quote → Order → Invoice)
- `DocumentNumberingService` - Sequential numbering with hash chains
- `DeliveryNoteService` - DDT management
- `RefundService` - Credit notes and cancellations

### Document Workflow
```
Quote ──[convert]──► Sales Order ──[convert]──► Invoice ──[post]──► Posted
                           │
                           └──[convert]──► Delivery Note
                                               │
                                               └──[consolidate]──► Invoice
```

### Key Routes
```
# Quotes
GET    /api/v1/quotes
POST   /api/v1/quotes
PATCH  /api/v1/quotes/{id}
POST   /api/v1/quotes/{id}/confirm
POST   /api/v1/quotes/{id}/convert-to-order

# Sales Orders
GET    /api/v1/orders
POST   /api/v1/orders/{id}/convert-to-invoice
POST   /api/v1/orders/{id}/convert-to-delivery

# Invoices
GET    /api/v1/invoices
POST   /api/v1/invoices/{id}/post
POST   /api/v1/invoices/{id}/cancel
POST   /api/v1/invoices/{id}/credit-full
POST   /api/v1/invoices/{id}/credit-partial

# Credit Notes
GET    /api/v1/credit-notes
POST   /api/v1/credit-notes/{id}/post

# Purchase Orders
GET    /api/v1/purchase-orders
POST   /api/v1/purchase-orders/{id}/receive

# PDF & Email
GET    /api/v1/documents/{id}/pdf
POST   /api/v1/documents/{id}/email
```

---

## Inventory Module

**Location**: `app/Modules/Inventory/`

### Purpose
Stock management, movements, and physical counting with multi-counter support.

### Entities

#### StockLevel
```php
'product_id', 'location_id'
'quantity'           // Current stock
'reserved'           // Reserved for orders
'available'          // Computed: quantity - reserved
'weighted_average_cost'
```

#### StockMovement
```php
'product_id', 'location_id'
'movement_type'      // MovementType enum
'quantity', 'unit_cost'
'reference_type', 'reference_id'  // Source document
'created_by'
```

#### InventoryCounting
```php
'company_id', 'location_id'
'name', 'status'     // CountingStatus enum
'scope_type'         // CountingScopeType enum
'execution_mode'     // CountingExecutionMode enum
'counter_1_id', 'counter_2_id', 'counter_3_id'
'scheduled_at', 'started_at', 'finalized_at'
'mobile_initiated', 'created_by_user_id'
```

#### InventoryCountingItem
```php
'counting_id', 'product_id'
'system_quantity'
'count_1', 'count_2', 'count_3'
'final_quantity'
'resolution_method'  // ItemResolutionMethod enum
'variance', 'resolved_at'
```

### Enums
- `MovementType`: receipt, issue, transfer, adjustment, return
- `CountingStatus`: draft, scheduled, count_1_in_progress, count_1_completed, count_2_in_progress, count_2_completed, count_3_in_progress, count_3_completed, pending_review, finalized, cancelled
- `CountingScopeType`: full_inventory, product_location, category
- `CountingExecutionMode`: parallel, sequential
- `ItemResolutionMethod`: auto_all_match, auto_counters_agree, third_count_decisive, manual_override

### Application Services
- `GoodsReceiptService` - Process PO receipts
- `InventoryCountingService` - Manage count sessions
- `CountingReconciliationService` - Variance analysis
- `WeightedAverageCostService` - Cost calculations
- `LandedCostService` - Allocate freight to product costs

### Counting Workflow
```
draft → scheduled → count_1_in_progress → count_1_completed
    → count_2_in_progress → count_2_completed
    → count_3_in_progress → count_3_completed (if needed)
    → pending_review → finalized
```

### Key Routes
```
# Locations
GET    /api/v1/locations
POST   /api/v1/locations
POST   /api/v1/locations/{id}/set-default

# Stock Levels
GET    /api/v1/stock-levels
GET    /api/v1/stock-levels/{product}/{location}

# Stock Movements (READ-ONLY since DPA V7 — the four raw writers were removed)
GET    /api/v1/stock-movements

# Stock Adjustments (the document that replaced POST /stock-movements/adjust)
GET    /api/v1/stock-adjustments
POST   /api/v1/stock-adjustments
GET    /api/v1/stock-adjustments/{id}
PATCH  /api/v1/stock-adjustments/{id}
POST   /api/v1/stock-adjustments/{id}/post
POST   /api/v1/stock-adjustments/{id}/cancel
POST   /api/v1/stock-adjustments/{id}/correct

# Inventory Counting
GET    /api/v1/inventory/countings
POST   /api/v1/inventory/countings
POST   /api/v1/inventory/countings/drafts  # Mobile-initiated
POST   /api/v1/inventory/countings/{id}/activate
POST   /api/v1/inventory/countings/{id}/finalize
GET    /api/v1/inventory/countings/{id}/reconciliation
POST   /api/v1/inventory/countings/items/{id}/count
```

---

## Treasury Module

**Location**: `app/Modules/Treasury/`

### Purpose
Universal payment system supporting all payment types across countries.

### Design Philosophy
Instead of hardcoding payment types (cash, check, card), the system uses configurable "switches" that define payment behavior.

### Entities

#### PaymentMethod
```php
'company_id', 'code', 'name'
// Behavior switches
'is_physical'          // Needs physical storage (check, voucher)
'has_maturity'         // Has due date (PDC, traite)
'requires_third_party' // Bank/gateway processing
'is_push'              // Client sends (vs pull like direct debit)
'has_deducted_fees'    // Fees taken from amount
'is_restricted'        // Limited use (meal voucher)
'is_active'
```

#### PaymentRepository
```php
'company_id', 'type'   // RepositoryType enum
'name', 'account_id'   // Linked GL account
'current_balance'
```

#### PaymentInstrument
```php
// Physical payment items (checks, vouchers)
'payment_method_id', 'amount', 'currency'
'status'               // InstrumentStatus enum
'reference', 'maturity_date'
'current_repository_id'
'received_at', 'deposited_at', 'cleared_at'
```

#### Payment
```php
'company_id', 'type'   // PaymentType enum
'payment_method_id', 'repository_id'
'partner_id', 'amount', 'currency'
'status'               // PaymentStatus enum
'reference', 'notes'
'instrument_id'        // If physical
```

#### PaymentAllocation
```php
'payment_id', 'document_id'
'allocated_amount'
'tolerance_amount'     // Write-off for small differences
'tolerance_type'       // underpayment, overpayment
```

### Enums
- `PaymentType`: incoming, outgoing
- `PaymentStatus`: pending, recorded, partially_allocated, allocated, reversed
- `RepositoryType`: safe, cash_register, bank_account, virtual
- `InstrumentStatus`: received, used, deposited, cleared, bounced, cancelled

### Payment Method Switches Explained
```
┌──────────────────────────────────────────────────────────────────┐
│ Switch              │ True                │ False               │
├──────────────────────────────────────────────────────────────────┤
│ is_physical         │ Check, voucher      │ Cash, wire, card    │
│ has_maturity        │ PDC, traite         │ Immediate payment   │
│ requires_third_party│ Card, bank transfer │ Cash, check         │
│ is_push             │ Customer pays       │ We charge customer  │
│ has_deducted_fees   │ Western Union       │ Most methods        │
│ is_restricted       │ Meal vouchers       │ General purpose     │
└──────────────────────────────────────────────────────────────────┘
```

### Application Services
- `PaymentAllocationService` - Smart allocation with FIFO
- `PaymentRefundService` - Refunds and reversals
- `MultiPaymentService` - Split payments
- `PaymentToleranceService` - Handle rounding differences
- `BankReconciliationService` - Match bank statements

### Key Routes
```
# Payment Methods
GET    /api/v1/payment-methods
POST   /api/v1/payment-methods

# Payment Repositories
GET    /api/v1/payment-repositories
GET    /api/v1/payment-repositories/{id}/balance
GET    /api/v1/payment-repositories/{id}/transactions

# Payment Instruments
GET    /api/v1/payment-instruments
POST   /api/v1/payment-instruments/{id}/deposit
POST   /api/v1/payment-instruments/{id}/clear
POST   /api/v1/payment-instruments/{id}/bounce

# Payments
GET    /api/v1/payments
POST   /api/v1/payments
POST   /api/v1/payments/{id}/refund
POST   /api/v1/payments/{id}/reverse

# Smart Payment
POST   /api/v1/smart-payment/preview-allocation
POST   /api/v1/smart-payment/apply-allocation
GET    /api/v1/partners/{id}/open-invoices

# Bank Reconciliation
GET    /api/v1/bank-reconciliations
POST   /api/v1/bank-reconciliations
POST   /api/v1/bank-reconciliations/{id}/match/{payment}
POST   /api/v1/bank-reconciliations/{id}/complete
```

---

## Accounting Module

**Location**: `app/Modules/Accounting/`

### Purpose
Chart of accounts, journal entries, and general ledger.

### Entities

#### Account
```php
'company_id', 'code', 'name'
'type'                 // AccountType enum
'parent_id'            // For hierarchy
'is_system'            // System-managed
'system_purpose'       // SystemAccountPurpose enum
'is_active'
```

#### JournalEntry
```php
'company_id', 'number', 'date'
'description', 'status'  // JournalEntryStatus enum
'source_type', 'source_id'  // Link to originating document
'created_by'
```

#### JournalLine
```php
'journal_entry_id', 'account_id'
'debit', 'credit'
'partner_id'           // For AR/AP tracking
'description'
```

### Enums
- `AccountType`: asset, liability, equity, revenue, expense
- `SystemAccountPurpose`: accounts_receivable, accounts_payable, sales_revenue, cash, bank, customer_advance, payment_tolerance_expense, payment_tolerance_income
- `JournalEntryStatus`: draft, posted, reversed

### System Account Purposes
```
accounts_receivable      → 411 - Customer invoices
accounts_payable         → 401 - Supplier invoices
sales_revenue            → 701 - Product sales
cash                     → 531 - Cash payments
bank                     → 512 - Bank transfers
customer_advance         → 419 - Advance payments
payment_tolerance_expense→ 658 - Write-off underpayments
payment_tolerance_income → 758 - Write-off overpayments
```

### Application Services
- `ChartOfAccountsService` - COA management
- `GeneralLedgerService` - GL entry creation
- `AccountingOpeningService` - Opening balance setup
- `PartnerBalanceService` - AR/AP tracking

### Key Routes
```
# Chart of Accounts
GET    /api/v1/accounts
POST   /api/v1/accounts
PATCH  /api/v1/accounts/{id}

# Journal Entries
GET    /api/v1/journal-entries
POST   /api/v1/journal-entries
POST   /api/v1/journal-entries/{id}/post

# Account Purposes
GET    /api/v1/companies/{id}/accounts/purposes
PUT    /api/v1/companies/{id}/accounts/{accountId}/purpose

# Partner Balances
GET    /api/v1/companies/{id}/partners/{partnerId}/balance
GET    /api/v1/companies/{id}/partners/{partnerId}/statement
GET    /api/v1/companies/{id}/subledger/receivables
GET    /api/v1/companies/{id}/subledger/payables

# Opening Balances
GET    /api/v1/companies/{id}/opening-batches
POST   /api/v1/companies/{id}/opening-batches
POST   /api/v1/companies/{id}/opening-batches/{batchId}/post
```

---

## Billing Module

**Location**: `app/Modules/Billing/`

### Purpose
SaaS subscription management for the platform.

### Entities

#### Plan
```php
'name', 'code'
'price_monthly', 'price_yearly'
'currency'
'limits'               // PlanLimits value object
'features'             // JSON array
'is_active'
```

#### PlanLimits (Value Object)
```php
'max_users', 'max_products', 'max_invoices_per_month'
'max_locations', 'max_storage_gb'
```

#### TenantSubscription
```php
'tenant_id', 'plan_id'
'status'               // SubscriptionStatus enum
'current_period_start', 'current_period_end'
'trial_ends_at'
'stripe_subscription_id'
```

#### BillingInvoice
```php
'tenant_id', 'subscription_id'
'number', 'amount', 'currency'
'status'               // InvoiceStatus enum
'due_date', 'paid_at'
```

### Enums
- `SubscriptionStatus`: trial, active, expired, suspended, cancelled
- `InvoiceStatus`: draft, pending, sent, paid, overdue, void
- `PaymentProviderCode`: manual, stripe

### Contracts
- `PaymentProviderInterface` - Abstraction for payment providers

### Providers
- `StripePaymentProvider` - Stripe integration
- `ManualPaymentProvider` - Offline payments

### Notifications
- `InvoicePaidNotification`
- `PaymentSucceededNotification`
- `PaymentFailedNotification`
- `SubscriptionCancelledNotification`

---

## Related Documentation

- [Backend Architecture](../architecture/backend.md)
- [Database Schema](../architecture/database.md)
- [API Reference](../api/README.md)
