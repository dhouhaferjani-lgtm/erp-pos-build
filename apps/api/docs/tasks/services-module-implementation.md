# Services Module Implementation

> **Document Version:** 1.0
> **Created:** 2025-12-12
> **Status:** IN PROGRESS
> **Author:** Claude Code Agent

---

## Executive Summary

This document details the implementation plan for a dedicated Services module in AutoERP. The module provides first-class support for service catalog management, service-specific pricing, and seamless integration with the existing document workflow (quotes, sales orders, invoices).

### Why a Separate Module?

After analyzing ERP best practices (SAP, Odoo, ERPNext, NetSuite), we've determined that services should be a **first-class entity** rather than a product subtype because:

1. **Different Lifecycle**: Services don't track inventory, don't require stock levels
2. **Different Pricing Models**: Time-based, flat-rate, percentage-based pricing
3. **Different Compliance**: No delivery notes required (Tunisia fiscal)
4. **Extensibility**: Future workshop module will need rich service definitions
5. **Separation of Concerns**: Cleaner architecture, easier to maintain

### Current State

Currently, services are stored in the `products` table with `type = 'service'` and `is_physical = false`. This works but:
- Mixes concerns (products have OEM numbers, stock-related fields)
- Product validation rules don't apply to services
- Future service features (labor rates, skills, time tracking) would pollute the Product model

---

## Architecture Decision

### Approach: Hybrid Model (Recommended)

**Keep `products` table for Parts/Consumables. Create new `services` table for Services.**

This allows:
- Services to have their own domain model with service-specific fields
- Clean separation of concerns
- Backward compatibility with existing code
- Future extensibility for workshop module

### Database Schema

```sql
-- New services table
CREATE TABLE services (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),

    -- Basic info
    code VARCHAR(50) NOT NULL,          -- Service code (e.g., "SRV-001", "OIL-CHANGE")
    name VARCHAR(255) NOT NULL,
    description TEXT,

    -- Categorization
    category_id UUID REFERENCES service_categories(id),

    -- Pricing
    pricing_type VARCHAR(20) NOT NULL DEFAULT 'flat_rate',  -- flat_rate, hourly, percentage
    base_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'TND',

    -- Time-based pricing
    default_duration_minutes INT,       -- Expected duration for scheduling
    hourly_rate DECIMAL(15,2),          -- For hourly pricing

    -- Tax
    tax_rate DECIMAL(5,2),              -- Default VAT rate

    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,

    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP WITH TIME ZONE,

    -- Constraints
    CONSTRAINT uk_services_code_company UNIQUE (company_id, code)
);

-- Service categories for organization
CREATE TABLE service_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),

    name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_id UUID REFERENCES service_categories(id),
    sort_order INT NOT NULL DEFAULT 0,

    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uk_service_categories_name_company UNIQUE (company_id, name)
);

-- Indexes
CREATE INDEX idx_services_tenant ON services(tenant_id);
CREATE INDEX idx_services_company ON services(company_id);
CREATE INDEX idx_services_category ON services(category_id);
CREATE INDEX idx_services_active ON services(company_id, is_active);
CREATE INDEX idx_service_categories_company ON service_categories(company_id);
```

### Module Structure

```
app/Modules/Service/
├── ServiceServiceProvider.php
├── routes.php
├── Domain/
│   ├── Service.php                 # Eloquent model
│   ├── ServiceCategory.php         # Category model
│   ├── Enums/
│   │   └── PricingType.php         # flat_rate, hourly, percentage
│   └── Services/
│       └── ServicePricingService.php
├── Application/
│   ├── DTOs/
│   │   ├── ServiceData.php
│   │   └── ServiceCategoryData.php
│   └── Services/
│       └── ServiceCatalogService.php
├── Infrastructure/
│   └── Providers/
│       └── ServiceModuleServiceProvider.php
└── Presentation/
    ├── Controllers/
    │   ├── ServiceController.php
    │   └── ServiceCategoryController.php
    ├── Requests/
    │   ├── CreateServiceRequest.php
    │   ├── UpdateServiceRequest.php
    │   └── ServiceCategoryRequest.php
    └── Resources/
        ├── ServiceResource.php
        └── ServiceCategoryResource.php
```

---

## Implementation Phases

### Phase 1: Database Migration & Enums ✅/⏳
**Status:** PENDING
**Estimated Tests:** 3

**Tasks:**
1. Create `PricingType` enum
2. Create migration for `service_categories` table
3. Create migration for `services` table

**Tests to Write First:**
- `test_pricing_type_enum_has_expected_values`
- `test_services_migration_creates_table_with_correct_columns`
- `test_service_categories_migration_creates_table`

**Files to Create:**
- `app/Modules/Service/Domain/Enums/PricingType.php`
- `database/migrations/2025_12_12_120000_create_service_categories_table.php`
- `database/migrations/2025_12_12_120001_create_services_table.php`

---

### Phase 2: Domain Models
**Status:** PENDING
**Estimated Tests:** 6

**Tasks:**
1. Create `ServiceCategory` model with relationships
2. Create `Service` model with relationships
3. Create model factories

**Tests to Write First:**
- `test_service_belongs_to_company`
- `test_service_belongs_to_category`
- `test_service_category_has_many_services`
- `test_service_category_can_have_parent`
- `test_service_scopes_filter_correctly`
- `test_service_factory_creates_valid_service`

**Files to Create:**
- `app/Modules/Service/Domain/ServiceCategory.php`
- `app/Modules/Service/Domain/Service.php`
- `database/factories/ServiceFactory.php`
- `database/factories/ServiceCategoryFactory.php`

---

### Phase 3: DTOs & Application Services
**Status:** PENDING
**Estimated Tests:** 4

**Tasks:**
1. Create `ServiceData` DTO
2. Create `ServiceCategoryData` DTO
3. Create `ServiceCatalogService` for CRUD operations
4. Create `ServicePricingService` for price calculations

**Tests to Write First:**
- `test_service_data_dto_validates_required_fields`
- `test_service_catalog_service_creates_service`
- `test_service_catalog_service_updates_service`
- `test_service_pricing_calculates_correctly_for_each_type`

**Files to Create:**
- `app/Modules/Service/Application/DTOs/ServiceData.php`
- `app/Modules/Service/Application/DTOs/ServiceCategoryData.php`
- `app/Modules/Service/Application/Services/ServiceCatalogService.php`
- `app/Modules/Service/Domain/Services/ServicePricingService.php`

---

### Phase 4: API Controllers & Routes
**Status:** PENDING
**Estimated Tests:** 10

**Tasks:**
1. Create request validation classes
2. Create API resources
3. Create controllers
4. Register routes
5. Create service provider

**Tests to Write First:**
- `test_can_list_services`
- `test_can_create_service`
- `test_can_update_service`
- `test_can_delete_service`
- `test_can_list_service_categories`
- `test_can_create_service_category`
- `test_service_validation_rejects_invalid_data`
- `test_service_code_must_be_unique_per_company`
- `test_service_routes_require_authentication`
- `test_service_routes_scope_to_company`

**Files to Create:**
- `app/Modules/Service/Presentation/Requests/CreateServiceRequest.php`
- `app/Modules/Service/Presentation/Requests/UpdateServiceRequest.php`
- `app/Modules/Service/Presentation/Requests/ServiceCategoryRequest.php`
- `app/Modules/Service/Presentation/Resources/ServiceResource.php`
- `app/Modules/Service/Presentation/Resources/ServiceCategoryResource.php`
- `app/Modules/Service/Presentation/Controllers/ServiceController.php`
- `app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php`
- `app/Modules/Service/routes.php`
- `app/Modules/Service/ServiceServiceProvider.php`

---

### Phase 5: Document Integration
**Status:** PENDING
**Estimated Tests:** 5

**Tasks:**
1. Update `DocumentLine` to support `service_id` alongside `product_id`
2. Update `DocumentConversionService` to handle service lines
3. Ensure service lines skip delivery note requirements

**Tests to Write First:**
- `test_document_line_can_reference_service`
- `test_quote_with_service_lines_converts_to_order`
- `test_service_only_order_converts_directly_to_invoice`
- `test_mixed_order_services_dont_require_delivery`
- `test_invoice_line_total_calculates_correctly_for_services`

**Files to Modify:**
- `app/Modules/Document/Domain/DocumentLine.php`
- `app/Modules/Document/Domain/Services/DocumentConversionService.php`
- `database/migrations/2025_12_12_130000_add_service_id_to_document_lines.php`

---

### Phase 6: Data Migration
**Status:** PENDING
**Estimated Tests:** 2

**Tasks:**
1. Create migration to move existing `products` with `type=service` to new `services` table
2. Update existing `document_lines` to reference `services` instead of `products`
3. Create rollback strategy

**Tests to Write First:**
- `test_migration_moves_service_products_to_services_table`
- `test_migration_updates_document_line_references`

**Files to Create:**
- `database/migrations/2025_12_12_140000_migrate_product_services_to_services_table.php`

---

### Phase 7: Frontend Integration
**Status:** PENDING
**Estimated Tests:** 3

**Tasks:**
1. Generate TypeScript types
2. Create service API client
3. Update document forms to support service selection

**Tests to Write First:**
- `test_service_types_generated_correctly` (manual verification)
- `test_service_api_client_fetches_services`
- `test_document_form_shows_service_selector`

**Files to Create:**
- `apps/web/src/features/services/api/services.ts`
- `apps/web/src/features/services/types.ts`
- Update `apps/web/src/features/documents/DocumentForm.tsx`

---

## Test Coverage Requirements

| Phase | Unit Tests | Integration Tests | Total |
|-------|------------|-------------------|-------|
| 1     | 3          | 0                 | 3     |
| 2     | 4          | 2                 | 6     |
| 3     | 4          | 0                 | 4     |
| 4     | 6          | 4                 | 10    |
| 5     | 3          | 2                 | 5     |
| 6     | 0          | 2                 | 2     |
| 7     | 0          | 3                 | 3     |
| **Total** | **20** | **13**        | **33** |

---

## API Specification

### Services Endpoints

```
GET    /api/v1/services                    # List services (with filters)
POST   /api/v1/services                    # Create service
GET    /api/v1/services/{id}               # Get service details
PATCH  /api/v1/services/{id}               # Update service
DELETE /api/v1/services/{id}               # Soft delete service

GET    /api/v1/service-categories          # List categories
POST   /api/v1/service-categories          # Create category
GET    /api/v1/service-categories/{id}     # Get category
PATCH  /api/v1/service-categories/{id}     # Update category
DELETE /api/v1/service-categories/{id}     # Delete category
```

### Request/Response Examples

**Create Service:**
```json
POST /api/v1/services
{
  "code": "OIL-CHANGE",
  "name": "Oil Change Service",
  "description": "Complete oil change with filter replacement",
  "category_id": "uuid",
  "pricing_type": "flat_rate",
  "base_price": "45.00",
  "currency": "TND",
  "default_duration_minutes": 30,
  "tax_rate": "19.00",
  "is_active": true
}
```

**Service Response:**
```json
{
  "data": {
    "id": "uuid",
    "code": "OIL-CHANGE",
    "name": "Oil Change Service",
    "description": "Complete oil change with filter replacement",
    "category": {
      "id": "uuid",
      "name": "Maintenance"
    },
    "pricing_type": "flat_rate",
    "base_price": "45.00",
    "currency": "TND",
    "default_duration_minutes": 30,
    "tax_rate": "19.00",
    "is_active": true,
    "created_at": "2025-12-12T10:00:00Z",
    "updated_at": "2025-12-12T10:00:00Z"
  }
}
```

---

## Pricing Type Calculations

### Flat Rate
```php
$lineTotal = $service->base_price * $quantity;
```

### Hourly
```php
$hours = $durationMinutes / 60;
$lineTotal = $service->hourly_rate * $hours;
```

### Percentage (e.g., for parts markup services)
```php
$lineTotal = $baseAmount * ($service->base_price / 100);
```

---

## Progress Tracking

### Phase 1: Database Migration & Enums
- [ ] Write tests for `PricingType` enum
- [ ] Implement `PricingType` enum
- [ ] Write tests for migrations
- [ ] Create `service_categories` migration
- [ ] Create `services` migration
- [ ] Run migrations
- [ ] Verify tests pass

### Phase 2: Domain Models
- [ ] Write tests for `ServiceCategory` model
- [ ] Implement `ServiceCategory` model
- [ ] Write tests for `Service` model
- [ ] Implement `Service` model
- [ ] Create factories
- [ ] Verify tests pass

### Phase 3: DTOs & Application Services
- [ ] Write tests for DTOs
- [ ] Implement DTOs
- [ ] Write tests for `ServiceCatalogService`
- [ ] Implement `ServiceCatalogService`
- [ ] Write tests for `ServicePricingService`
- [ ] Implement `ServicePricingService`
- [ ] Verify tests pass

### Phase 4: API Controllers & Routes
- [ ] Write tests for API endpoints
- [ ] Create request validation classes
- [ ] Create API resources
- [ ] Create controllers
- [ ] Register routes
- [ ] Create service provider
- [ ] Register provider in config/app.php
- [ ] Verify tests pass

### Phase 5: Document Integration
- [ ] Write tests for document integration
- [ ] Add `service_id` to `document_lines`
- [ ] Update `DocumentLine` model
- [ ] Update `DocumentConversionService`
- [ ] Verify tests pass

### Phase 6: Data Migration
- [ ] Write tests for data migration
- [ ] Create migration script
- [ ] Test on dev database
- [ ] Verify rollback works
- [ ] Verify tests pass

### Phase 7: Frontend Integration
- [ ] Generate TypeScript types
- [ ] Create API client
- [ ] Update document forms
- [ ] Manual testing

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Breaking existing document lines | Medium | High | Thorough testing, feature flag |
| Performance with large catalogs | Low | Medium | Proper indexing, pagination |
| Category hierarchy complexity | Low | Low | Limit depth, simple queries |

---

## Rollback Strategy

If issues arise after deployment:

1. **Phase 1-4**: Simply drop new tables, no data loss
2. **Phase 5**: Revert migration, `service_id` column nullable
3. **Phase 6**: Keep data in both places during transition period
4. **Phase 7**: Frontend changes are independent

---

## Dependencies

- Existing `Document` module
- Existing `Company` module
- Existing `Tenant` module
- PHP 8.3+
- PostgreSQL 16+

---

## Success Criteria

1. All 33 tests pass
2. PHPStan level 8 passes
3. API endpoints respond in < 100ms
4. Service catalog supports 10,000+ services per company
5. Document workflow unchanged for existing orders

---

## Changelog

| Date | Version | Changes |
|------|---------|---------|
| 2025-12-12 | 1.0 | Initial document created |

