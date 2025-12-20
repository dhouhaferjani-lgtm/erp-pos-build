# Services Module

> Configurable service catalog for workshops with pricing types and categories.

---

## Purpose

The Services module manages the service catalog for automotive workshops, body shops, and service centers. Services can be priced as flat rate, hourly, or percentage-based, and organized into hierarchical categories.

---

## Service Types

| Pricing Type | Code | Description |
|--------------|------|-------------|
| Flat Rate | `flat_rate` | Fixed price per service |
| Hourly | `hourly` | Price calculated from hourly rate x duration |
| Percentage | `percentage` | Price as percentage of parts/labor |

---

## Key Models

### Service

```php
Service {
    id: UUID
    tenant_id: UUID
    company_id: UUID
    category_id: UUID?
    code: string          // Unique service code (e.g., SRV-001)
    name: string
    description: string?
    pricing_type: PricingType
    base_price: decimal?  // For flat_rate
    hourly_rate: decimal? // For hourly
    default_duration_minutes: int? // Default time for hourly services
    tax_rate: decimal?
    currency: string      // TND, EUR, USD
    is_active: boolean
}
```

### ServiceCategory

```php
ServiceCategory {
    id: UUID
    tenant_id: UUID
    company_id: UUID
    parent_id: UUID?      // For hierarchical categories
    name: string
    description: string?
    sort_order: int
    is_active: boolean
}
```

---

## Pricing Types

### Flat Rate
Fixed price for the service, regardless of time spent.
- `base_price` stores the flat rate amount
- Best for: Oil changes, tire rotations, standard inspections

### Hourly
Price calculated from hourly rate multiplied by actual time.
- `hourly_rate` stores the rate per hour
- `default_duration_minutes` provides estimated time
- Best for: Diagnostic work, repairs, custom jobs

### Percentage
Price as a percentage of another value (parts cost, labor, etc.).
- `base_price` stores the percentage (e.g., 10 for 10%)
- Best for: Markup on parts, handling fees

---

## Categories

Categories support hierarchical organization:

```
Maintenance
├── Oil Change
├── Tire Services
│   ├── Rotation
│   ├── Balancing
│   └── Alignment
└── Filters
    ├── Air Filter
    └── Oil Filter
```

Categories are optional - services can exist without a category.

---

## API Endpoints

### Services

```
GET    /api/services                     # List with filters
GET    /api/services/{id}                # Get single service
POST   /api/services                     # Create service
PUT    /api/services/{id}                # Update service
DELETE /api/services/{id}                # Delete service
```

### Categories

```
GET    /api/services/categories          # List all categories
POST   /api/services/categories          # Create category
PUT    /api/services/categories/{id}     # Update category
DELETE /api/services/categories/{id}     # Delete category
```

---

## Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Search by name or code |
| `category_id` | UUID | Filter by category |
| `pricing_type` | string | Filter by pricing type |
| `is_active` | boolean | Filter by status |

---

## Frontend Routes

| Route | Component | Description |
|-------|-----------|-------------|
| `/services` | ServiceListPage | List all services with search/filter |
| `/services/new` | ServiceForm | Create new service |
| `/services/:id` | ServiceDetailPage | View service details |
| `/services/:id/edit` | ServiceForm | Edit existing service |
| `/services/categories` | ServiceCategoryListPage | Manage categories |

---

## Permissions

| Permission | Roles |
|------------|-------|
| `services.view` | admin, sales, manager |
| `services.create` | admin, sales, manager |
| `services.edit` | admin, sales, manager |

---

## Integration Points

### Document Lines
Services can be added to quotes, orders, and invoices as line items.

### Work Orders (Future)
Services will be linked to work order tasks for workshop scheduling.

### Pricing Rules (Future)
Services can have special pricing per customer or vehicle type.

---

## File Structure

### Backend
```
apps/api/app/Modules/Service/
├── Domain/
│   ├── Service.php
│   ├── ServiceCategory.php
│   └── Enums/
│       └── PricingType.php
└── Presentation/
    ├── ServiceController.php
    └── ServiceCategoryController.php
```

### Frontend
```
apps/web/src/features/services/
├── ServiceListPage.tsx
├── ServiceDetailPage.tsx
├── ServiceForm.tsx
├── ServiceCategoryListPage.tsx
└── types.ts
```

---

## Translations

Services module supports i18n with keys under the `services` namespace:
- `services.title` - Page title
- `services.addService` - Add button
- `services.pricingTypes.*` - Pricing type labels
- `services.fields.*` - Form field labels
- `services.categories` - Categories management
