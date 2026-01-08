# Multi-App Vertical System - Database Schema

**Document Version:** 1.0
**Last Updated:** 2025-12-30
**Status:** ✅ Implemented and Verified

---

## Overview

This document describes the database schema for the multi-app vertical system that enables AutoERP to support multiple products (IziPOS and Otospex) with 12 distinct business verticals.

### Design Principles

1. **Vertical at Tenant Level** - Each tenant has one vertical that determines the entire application experience
2. **Extensibility via Extras** - Tenants can enable optional modules compatible with their vertical
3. **Signup Tracking** - Complete marketing attribution and conversion funnel tracking
4. **Onboarding Guidance** - Vertical-specific onboarding checklists to ensure proper setup

---

## Schema Additions

### 1. Tenants Table Extensions

**Migration:** `2025_12_30_115627_add_vertical_to_tenants_table.php`

Adds vertical system support to the existing tenants table.

#### Columns Added

| Column | Type | Constraints | Purpose |
|--------|------|-------------|---------|
| `vertical` | `VARCHAR(50)` | NOT NULL, DEFAULT 'retail' | Business vertical (mechanic, pharmacy, restaurant, etc.) |
| `enabled_extras` | `JSONB` | NOT NULL, DEFAULT '[]' | Array of enabled optional modules |
| `signup_source` | `VARCHAR(100)` | NULLABLE | Campaign or referral source identifier |
| `signup_tracking` | `JSONB` | NULLABLE | Complete signup context (UTM, device, etc.) |

#### Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| `tenants_vertical_index` | `vertical` | Fast filtering by business vertical |

#### Schema Definition

```php
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

#### Example Data

```json
{
  "vertical": "mechanic",
  "enabled_extras": ["appointments", "fleet_management"],
  "signup_source": "google_ads_q1_2025",
  "signup_tracking": {
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "automotive_q1_2025",
    "device_type": "mobile",
    "country_code": "TN"
  }
}
```

---

### 2. Signup Tracking Table

**Migration:** `2025_12_30_115731_create_signup_tracking_table.php`

Detailed signup analytics and conversion tracking for marketing attribution.

#### Purpose

- Track marketing campaign performance
- Measure conversion funnel metrics
- Analyze geographic and device distribution
- Calculate customer acquisition cost (CAC)

#### Columns

| Column | Type | Constraints | Purpose |
|--------|------|-------------|---------|
| `id` | `BIGINT UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT | Unique identifier |
| `tenant_id` | `UUID` | NOT NULL, FK to tenants(id) CASCADE | Links to tenant |
| `vertical` | `VARCHAR(50)` | NOT NULL | Vertical at signup time (denormalized for analytics) |
| **UTM Parameters** ||||
| `utm_source` | `VARCHAR(100)` | NULLABLE | Traffic source (google, facebook, direct, etc.) |
| `utm_medium` | `VARCHAR(100)` | NULLABLE | Marketing medium (cpc, email, social, etc.) |
| `utm_campaign` | `VARCHAR(200)` | NULLABLE | Campaign identifier |
| `utm_content` | `VARCHAR(200)` | NULLABLE | Content variant (for A/B testing) |
| `utm_term` | `VARCHAR(200)` | NULLABLE | Paid search keywords |
| **Referral Tracking** ||||
| `referral_code` | `VARCHAR(50)` | NULLABLE | Partner/affiliate referral code |
| `referrer_url` | `TEXT` | NULLABLE | Full HTTP referrer URL |
| **Device & Location** ||||
| `device_type` | `VARCHAR(20)` | NULLABLE | Desktop, mobile, tablet |
| `country_code` | `CHAR(2)` | NULLABLE | ISO 3166-1 alpha-2 country code |
| **Conversion Funnel** ||||
| `created_at` | `TIMESTAMP` | NOT NULL, DEFAULT NOW() | Signup timestamp |
| `email_verified_at` | `TIMESTAMP` | NULLABLE | Email verification timestamp |
| `first_sale_at` | `TIMESTAMP` | NULLABLE | First transaction timestamp |

#### Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| `signup_tracking_tenant_id_foreign` | `tenant_id` | Foreign key index |
| `signup_tracking_utm_campaign_created_at_index` | `utm_campaign`, `created_at` | Campaign performance queries |
| `signup_tracking_vertical_created_at_index` | `vertical`, `created_at` | Vertical-specific analytics |

#### Schema Definition

```php
Schema::create('signup_tracking', function (Blueprint $table) {
    $table->id();
    $table->uuid('tenant_id');
    $table->foreign('tenant_id')
        ->references('id')
        ->on('tenants')
        ->onDelete('cascade');
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

#### Example Analytics Queries

**Campaign Performance:**
```sql
SELECT
    utm_campaign,
    COUNT(*) as signups,
    COUNT(email_verified_at) as verified,
    COUNT(first_sale_at) as converted,
    COUNT(first_sale_at)::float / COUNT(*) as conversion_rate
FROM signup_tracking
WHERE utm_campaign = 'automotive_q1_2025'
  AND created_at >= '2025-01-01'
GROUP BY utm_campaign;
```

**Vertical Distribution:**
```sql
SELECT
    vertical,
    COUNT(*) as signups,
    country_code,
    device_type
FROM signup_tracking
WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
GROUP BY vertical, country_code, device_type
ORDER BY signups DESC;
```

---

### 3. Onboarding Checklists Table

**Migration:** `2025_12_30_115832_create_onboarding_checklists_table.php`

Tracks completion of vertical-specific onboarding steps for each tenant.

#### Purpose

- Guide tenants through initial setup
- Ensure critical configuration is completed
- Track onboarding progress and completion rates
- Enable vertical-specific setup workflows

#### Columns

| Column | Type | Constraints | Purpose |
|--------|------|-------------|---------|
| `id` | `BIGINT UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT | Unique identifier |
| `tenant_id` | `UUID` | NOT NULL, FK to tenants(id) CASCADE | Links to tenant |
| `step_key` | `VARCHAR(50)` | NOT NULL | Unique step identifier (e.g., 'company_details') |
| `title` | `VARCHAR(100)` | NOT NULL | Display title for the step |
| `is_required` | `BOOLEAN` | NOT NULL, DEFAULT false | Whether step blocks onboarding completion |
| `is_completed` | `BOOLEAN` | NOT NULL, DEFAULT false | Current completion status |
| `completed_at` | `TIMESTAMP` | NULLABLE | When the step was completed |
| `order` | `SMALLINT UNSIGNED` | NOT NULL, DEFAULT 0 | Display order in checklist |
| `created_at` | `TIMESTAMP` | NOT NULL, DEFAULT NOW() | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NOT NULL, DEFAULT NOW() | Last update timestamp |

#### Constraints

| Constraint | Type | Columns | Purpose |
|------------|------|---------|---------|
| `onboarding_checklists_tenant_id_step_key_unique` | UNIQUE | `tenant_id`, `step_key` | Prevent duplicate steps per tenant |
| `onboarding_checklists_tenant_id_foreign` | FOREIGN KEY | `tenant_id` | Links to tenants table |

#### Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| `onboarding_checklists_tenant_id_is_completed_index` | `tenant_id`, `is_completed` | Fast incomplete steps query |

#### Schema Definition

```php
Schema::create('onboarding_checklists', function (Blueprint $table) {
    $table->id();
    $table->uuid('tenant_id');
    $table->foreign('tenant_id')
        ->references('id')
        ->on('tenants')
        ->onDelete('cascade');
    $table->string('step_key', 50);
    $table->string('title', 100);
    $table->boolean('is_required')->default(false);
    $table->boolean('is_completed')->default(false);
    $table->timestamp('completed_at')->nullable();
    $table->unsignedSmallInteger('order')->default(0);
    $table->timestamps();

    $table->unique(['tenant_id', 'step_key']);
    $table->index(['tenant_id', 'is_completed']);
});
```

#### Example Data - Mechanic Vertical

```json
[
  {
    "step_key": "company_details",
    "title": "Business Details",
    "is_required": true,
    "is_completed": true,
    "completed_at": "2025-01-15 10:30:00",
    "order": 1
  },
  {
    "step_key": "import_vehicles",
    "title": "Import Vehicles",
    "is_required": false,
    "is_completed": false,
    "completed_at": null,
    "order": 2
  },
  {
    "step_key": "setup_pos",
    "title": "Set Up POS",
    "is_required": false,
    "is_completed": true,
    "completed_at": "2025-01-15 11:00:00",
    "order": 3
  }
]
```

#### Example Queries

**Get Incomplete Required Steps:**
```sql
SELECT step_key, title, order
FROM onboarding_checklists
WHERE tenant_id = :tenant_id
  AND is_required = true
  AND is_completed = false
ORDER BY order ASC;
```

**Calculate Onboarding Progress:**
```sql
SELECT
    COUNT(*) as total_steps,
    COUNT(*) FILTER (WHERE is_completed) as completed_steps,
    (COUNT(*) FILTER (WHERE is_completed)::float / COUNT(*))  * 100 as progress_percent
FROM onboarding_checklists
WHERE tenant_id = :tenant_id;
```

---

## Data Relationships

### Entity Relationship Diagram

```
┌─────────────────────────────────────┐
│ Tenants                             │
│─────────────────────────────────────│
│ id (UUID) PK                        │
│ name                                │
│ vertical (NEW)                      │
│ enabled_extras (NEW)                │
│ signup_source (NEW)                 │
│ signup_tracking (NEW)               │
└─────────────────────────────────────┘
          │                │
          │                │
          │                ├──────────────────────────┐
          │                │                          │
          ▼                ▼                          ▼
┌───────────────────┐  ┌─────────────────────┐  ┌──────────────────────┐
│ Signup Tracking   │  │ Onboarding          │  │ Companies            │
│───────────────────│  │ Checklists          │  │──────────────────────│
│ id PK             │  │─────────────────────│  │ id PK                │
│ tenant_id FK      │  │ id PK               │  │ tenant_id FK         │
│ vertical          │  │ tenant_id FK        │  │ name                 │
│ utm_* (5 cols)    │  │ step_key            │  │ ... (inherits        │
│ referral_* (2)    │  │ title               │  │      vertical from   │
│ device_* (2)      │  │ is_required         │  │      tenant)         │
│ conversion (3)    │  │ is_completed        │  │                      │
└───────────────────┘  │ completed_at        │  └──────────────────────┘
                       │ order               │
                       │ timestamps          │
                       └─────────────────────┘
```

### Cascade Behavior

When a tenant is deleted:
- ✅ All `signup_tracking` records are automatically deleted (CASCADE)
- ✅ All `onboarding_checklists` records are automatically deleted (CASCADE)
- ✅ Tenant's companies are deleted (existing CASCADE)

---

## Migration & Rollback

### Forward Migration

```bash
php artisan migrate
```

Applies migrations in order:
1. `2025_12_30_115627_add_vertical_to_tenants_table` - Adds 4 columns + index to tenants
2. `2025_12_30_115731_create_signup_tracking_table` - Creates tracking table
3. `2025_12_30_115832_create_onboarding_checklists_table` - Creates checklist table

### Rollback

```bash
php artisan migrate:rollback --step=3
```

Rollback cleanly removes all changes:
1. Drops `onboarding_checklists` table
2. Drops `signup_tracking` table
3. Drops index and columns from `tenants` table

All rollbacks are safe and tested.

---

## Performance Considerations

### Index Strategy

| Table | Index | Cardinality | Usage Pattern |
|-------|-------|-------------|---------------|
| tenants | `vertical` | ~12 values | Frequent WHERE clauses |
| signup_tracking | `(utm_campaign, created_at)` | High | Analytics queries |
| signup_tracking | `(vertical, created_at)` | Medium | Vertical-specific reports |
| onboarding_checklists | `(tenant_id, is_completed)` | Low | Dashboard incomplete steps |

### Query Optimization Tips

1. **Use Covering Indexes:** Analytics queries on `signup_tracking` benefit from composite indexes
2. **JSONB Queries:** Use JSONB operators for `enabled_extras`:
   ```sql
   WHERE enabled_extras @> '["appointments"]'::jsonb
   ```
3. **Timestamp Partitioning:** Consider partitioning `signup_tracking` by `created_at` if volume exceeds 1M rows

---

## Testing

### Test Coverage

All schema changes are validated by comprehensive test suites:

| Test Suite | Tests | Coverage |
|------------|-------|----------|
| `VerticalMigrationTest` | 9 tests | Tenants table extensions |
| `SignupTrackingMigrationTest` | 9 tests | Signup tracking table |
| `OnboardingChecklistsMigrationTest` | 9 tests | Onboarding checklists table |
| **Total** | **27 tests** | **100% schema coverage** |

### Running Tests

```bash
# Run all migration tests
php artisan test --filter=Migration

# Run specific test suite
php artisan test --filter=VerticalMigrationTest
```

All tests follow TDD principles and were written before implementation.

---

## Future Enhancements

### Planned (Not in Phase 1)

1. **Tenant Switching** - Organization table for multi-tenant access
   ```sql
   CREATE TABLE organizations (
       id UUID PRIMARY KEY,
       name VARCHAR(100),
       owner_user_id UUID
   );

   CREATE TABLE organization_tenants (
       organization_id UUID REFERENCES organizations(id),
       tenant_id UUID REFERENCES tenants(id),
       added_at TIMESTAMP
   );
   ```

2. **Audit Trail for Extras** - Junction table instead of JSONB
   ```sql
   CREATE TABLE tenant_extras (
       tenant_id UUID,
       extra_key VARCHAR(50),
       enabled_by UUID REFERENCES users(id),
       enabled_at TIMESTAMP,
       PRIMARY KEY (tenant_id, extra_key)
   );
   ```

3. **Vertical Inheritance** - Extend mechanic → motorcycle_shop
   ```json
   {
       "vertical": "motorcycle_shop",
       "extends": "mechanic",
       "overrides": {
           "databases": {"vehicle_catalog": "motorcycle_catalog"}
       }
   }
   ```

---

## References

- **Implementation Plan:** `/docs/new_docs/MULTI-APP-SCAFFOLDING-FINAL.md`
- **Clarifications:** `/docs/new_docs/SCAFFOLDING-CLARIFICATIONS.md`
- **Verticals Documentation:** `/docs/architecture/verticals.md` (to be created in Milestone 2)
- **Products Documentation:** `/docs/architecture/products.md` (to be created in Milestone 2)

---

## Verification

✅ **Opus 4.5 Schema Audit:** PASSED (2025-12-30)
- All columns match specification
- All indexes created correctly
- All constraints properly defined
- 100% test coverage
- Ready for Milestone 2

---

*Document maintained by: Claude Code*
*Last schema verification: 2025-12-30*
