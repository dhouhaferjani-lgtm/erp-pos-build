# Units of Measure (UOM) - Core Module FRD

**ERP Core Module**

| Field | Value |
|-------|-------|
| Version | 1.0 |
| Date | January 2026 |
| Status | Draft for Review |
| Module Type | Core (foundational, required by Products, Inventory, Production) |

---

## 1. Executive Summary

### 1.1 Purpose

This document defines the functional requirements for the Units of Measure (UOM) module, a foundational component that standardizes how quantities are expressed, converted, and managed across the entire ERP system. Currently, units are stored as free-text fields, leading to inconsistencies ("kg" vs "Kg" vs "kilogram") and inability to perform unit conversions.

### 1.2 Problem Statement

| Current State | Issues |
|---------------|--------|
| `products.unit` is a text field | No validation, inconsistent entries |
| No conversion support | Can't convert kg to grams in recipes |
| No categorization | Can't validate compatible units |
| Per-product definition | Same unit defined differently across products |

### 1.3 Solution

Implement a managed UOM system where:
- Units are defined once and reused across all entities
- Units belong to categories (Weight, Volume, Length, Pieces, Time)
- Conversions are defined between units in the same category
- Products, recipes, and inventory reference UOM by ID, not text

---

## 2. Core Concepts

### 2.1 UOM Categories

Categories group related units and define what conversions are possible:

| Category | Base Unit | Description | Example Units |
|----------|-----------|-------------|---------------|
| **Weight** | gram (g) | Mass measurement | mg, g, kg, oz, lb |
| **Volume** | milliliter (ml) | Liquid/gas measurement | ml, cl, L, fl oz, gal |
| **Length** | millimeter (mm) | Distance measurement | mm, cm, m, in, ft |
| **Area** | sq millimeter (mm²) | Surface measurement | mm², cm², m², sq ft |
| **Pieces** | piece (pc) | Countable items | pc, pair, dozen, box |
| **Time** | minute (min) | Duration | sec, min, hour, day |
| **Custom** | (defined) | Tenant-specific | varies |

### 2.2 Base Unit Principle

Each category has a **base unit** that serves as the conversion anchor:

```
Weight Category (base: gram)
├── milligram: 0.001 g
├── gram: 1 g (base)
├── kilogram: 1000 g
├── ounce: 28.3495 g
└── pound: 453.592 g

Conversion: 2.5 kg → grams = 2.5 × 1000 = 2500 g
Conversion: 2500 g → kg = 2500 ÷ 1000 = 2.5 kg
```

### 2.3 Unit Scope

| Scope | Description | Use Case |
|-------|-------------|----------|
| **System** | Pre-defined, immutable | Standard SI units, common units |
| **Tenant** | Created by tenant admin | Industry-specific units |
| **Company** | Company-specific | Rare, for unique business needs |

---

## 3. Data Model

### 3.1 UOM Category

```sql
CREATE TABLE uom_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id),  -- NULL for system categories
    
    code VARCHAR(50) NOT NULL,              -- 'weight', 'volume', 'length'
    name VARCHAR(100) NOT NULL,             -- 'Weight', 'Volume', 'Length'
    description TEXT,
    
    base_unit_id UUID,                      -- Reference to base unit (set after units created)
    
    is_system BOOLEAN DEFAULT FALSE,        -- System categories are immutable
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(tenant_id, code)
);
```

### 3.2 Unit of Measure

```sql
CREATE TABLE units_of_measure (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id),  -- NULL for system units
    category_id UUID NOT NULL REFERENCES uom_categories(id),
    
    code VARCHAR(20) NOT NULL,              -- 'kg', 'g', 'ml', 'pc'
    name VARCHAR(100) NOT NULL,             -- 'Kilogram', 'Gram', 'Milliliter'
    symbol VARCHAR(10) NOT NULL,            -- 'kg', 'g', 'mL', 'pcs'
    
    -- Conversion to base unit
    conversion_factor DECIMAL(20,10) NOT NULL DEFAULT 1,  -- How many base units = 1 of this unit
    
    -- Display settings
    decimal_places INT DEFAULT 2,           -- Precision for display
    rounding_method VARCHAR(20) DEFAULT 'half_up',  -- 'half_up', 'floor', 'ceil'
    
    is_base_unit BOOLEAN DEFAULT FALSE,     -- Is this the category's base unit?
    is_system BOOLEAN DEFAULT FALSE,        -- System units are immutable
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(tenant_id, code),
    UNIQUE(tenant_id, category_id, name)
);

-- Index for lookups
CREATE INDEX idx_uom_category ON units_of_measure(category_id);
CREATE INDEX idx_uom_tenant_active ON units_of_measure(tenant_id, is_active);
```

### 3.3 UOM Conversion (Optional - for non-linear conversions)

For most units, `conversion_factor` is sufficient. This table handles special cases:

```sql
CREATE TABLE uom_conversions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id),
    
    from_unit_id UUID NOT NULL REFERENCES units_of_measure(id),
    to_unit_id UUID NOT NULL REFERENCES units_of_measure(id),
    
    -- Conversion formula: to_value = (from_value × multiplier) + offset
    multiplier DECIMAL(20,10) NOT NULL,
    offset DECIMAL(20,10) DEFAULT 0,        -- For temperature conversions
    
    is_bidirectional BOOLEAN DEFAULT TRUE,  -- Can convert both ways?
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(tenant_id, from_unit_id, to_unit_id)
);
```

---

## 4. Functional Requirements

### 4.1 UOM Category Management

| ID | Requirement |
|----|-------------|
| FR-CAT-001 | System provides pre-seeded categories (Weight, Volume, Length, Area, Pieces, Time) |
| FR-CAT-002 | Tenant admins can create custom categories |
| FR-CAT-003 | Each category must have exactly one base unit designated |
| FR-CAT-004 | System categories cannot be modified or deleted |
| FR-CAT-005 | Categories can be deactivated (hides from dropdowns, preserves data) |

### 4.2 Unit Management

| ID | Requirement |
|----|-------------|
| FR-UOM-001 | System provides pre-seeded common units (g, kg, ml, L, pc, etc.) |
| FR-UOM-002 | Tenant admins can create custom units within categories |
| FR-UOM-003 | Each unit must specify conversion factor to category's base unit |
| FR-UOM-004 | Units can be deactivated but not deleted if referenced by products |
| FR-UOM-005 | Unit codes must be unique within tenant scope |
| FR-UOM-006 | System units are available to all tenants, immutable |

### 4.3 Unit Conversion

| ID | Requirement |
|----|-------------|
| FR-CONV-001 | System can convert quantities between any units in same category |
| FR-CONV-002 | Conversion uses: `target_qty = source_qty × (source_factor / target_factor)` |
| FR-CONV-003 | Cross-category conversion is not allowed (kg to liters = error) |
| FR-CONV-004 | Conversion respects target unit's decimal places and rounding |
| FR-CONV-005 | Conversion service is available to all modules |

### 4.4 Product Integration

| ID | Requirement |
|----|-------------|
| FR-PROD-001 | Product `unit_id` references `units_of_measure.id` (not text) |
| FR-PROD-002 | Product unit selection shows dropdown filtered by active units |
| FR-PROD-003 | Migration converts existing text units to UOM references |
| FR-PROD-004 | Products can have primary unit and secondary unit (e.g., sold by piece, stocked by box) |

### 4.5 Recipe/BOM Integration

| ID | Requirement |
|----|-------------|
| FR-REC-001 | Recipe lines specify quantity and unit |
| FR-REC-002 | Recipe unit can differ from ingredient's stock unit (auto-conversion) |
| FR-REC-003 | Example: Recipe says "250 ml milk", ingredient stocked in liters → system converts |

### 4.6 Inventory Integration

| ID | Requirement |
|----|-------------|
| FR-INV-001 | Stock quantities stored in product's primary unit |
| FR-INV-002 | Stock adjustments can be entered in any compatible unit (converted on save) |
| FR-INV-003 | Purchase orders can use supplier's unit (converted on receipt) |

---

## 5. System-Seeded Data

### 5.1 Categories

```json
[
  { "code": "weight", "name": "Weight", "base_unit": "g" },
  { "code": "volume", "name": "Volume", "base_unit": "ml" },
  { "code": "length", "name": "Length", "base_unit": "mm" },
  { "code": "area", "name": "Area", "base_unit": "mm2" },
  { "code": "pieces", "name": "Pieces/Count", "base_unit": "pc" },
  { "code": "time", "name": "Time", "base_unit": "min" }
]
```

### 5.2 Units

**Weight (base: gram)**

| Code | Name | Symbol | Factor | Decimals |
|------|------|--------|--------|----------|
| mg | Milligram | mg | 0.001 | 0 |
| g | Gram | g | 1 | 2 |
| kg | Kilogram | kg | 1000 | 3 |
| oz | Ounce | oz | 28.3495 | 2 |
| lb | Pound | lb | 453.592 | 2 |

**Volume (base: milliliter)**

| Code | Name | Symbol | Factor | Decimals |
|------|------|--------|--------|----------|
| ml | Milliliter | mL | 1 | 0 |
| cl | Centiliter | cL | 10 | 1 |
| l | Liter | L | 1000 | 3 |
| floz | Fluid Ounce | fl oz | 29.5735 | 2 |
| gal | Gallon (US) | gal | 3785.41 | 3 |

**Length (base: millimeter)**

| Code | Name | Symbol | Factor | Decimals |
|------|------|--------|--------|----------|
| mm | Millimeter | mm | 1 | 0 |
| cm | Centimeter | cm | 10 | 1 |
| m | Meter | m | 1000 | 2 |
| in | Inch | in | 25.4 | 2 |
| ft | Foot | ft | 304.8 | 2 |

**Pieces (base: piece)**

| Code | Name | Symbol | Factor | Decimals |
|------|------|--------|--------|----------|
| pc | Piece | pcs | 1 | 0 |
| pair | Pair | pair | 2 | 0 |
| doz | Dozen | doz | 12 | 0 |
| box | Box | box | (varies) | 0 |
| case | Case | case | (varies) | 0 |

**Time (base: minute)**

| Code | Name | Symbol | Factor | Decimals |
|------|------|--------|--------|----------|
| sec | Second | sec | 0.0167 | 0 |
| min | Minute | min | 1 | 0 |
| hr | Hour | hr | 60 | 2 |
| day | Day | day | 1440 | 2 |

---

## 6. Conversion Service

### 6.1 Interface

```php
interface UomConversionServiceInterface
{
    /**
     * Convert quantity from one unit to another
     * 
     * @throws IncompatibleUnitsException if units are from different categories
     */
    public function convert(
        Decimal $quantity,
        UnitOfMeasure $fromUnit,
        UnitOfMeasure $toUnit
    ): Decimal;
    
    /**
     * Check if two units can be converted
     */
    public function canConvert(
        UnitOfMeasure $fromUnit,
        UnitOfMeasure $toUnit
    ): bool;
    
    /**
     * Get conversion factor between two units
     */
    public function getConversionFactor(
        UnitOfMeasure $fromUnit,
        UnitOfMeasure $toUnit
    ): Decimal;
}
```

### 6.2 Conversion Formula

```
Standard conversion (same category):

to_quantity = from_quantity × (from_unit.factor / to_unit.factor)

Example: 2.5 kg to grams
  = 2.5 × (1000 / 1)
  = 2500 g

Example: 500 g to kg
  = 500 × (1 / 1000)
  = 0.5 kg

Example: 2 lb to kg
  = 2 × (453.592 / 1000)
  = 0.907 kg
```

---

## 7. UI Requirements

### 7.1 UOM Settings Page

**Location:** Settings → Units of Measure

**Layout:**
```
┌─────────────────────────────────────────────────────────────────┐
│ Units of Measure                                    [+ Add Unit] │
├─────────────────────────────────────────────────────────────────┤
│ Category: [All Categories ▼]    Search: [____________]          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ WEIGHT                                                          │
│ ┌─────────┬───────────┬────────┬──────────┬────────┬─────────┐ │
│ │ Code    │ Name      │ Symbol │ Factor   │ System │ Actions │ │
│ ├─────────┼───────────┼────────┼──────────┼────────┼─────────┤ │
│ │ g       │ Gram      │ g      │ 1 (base) │ ✓      │ 👁       │ │
│ │ kg      │ Kilogram  │ kg     │ 1000     │ ✓      │ 👁       │ │
│ │ mg      │ Milligram │ mg     │ 0.001    │ ✓      │ 👁       │ │
│ │ lb      │ Pound     │ lb     │ 453.592  │ ✓      │ 👁       │ │
│ │ oz      │ Ounce     │ oz     │ 28.3495  │ ✓      │ 👁       │ │
│ └─────────┴───────────┴────────┴──────────┴────────┴─────────┘ │
│                                                                 │
│ VOLUME                                                          │
│ ┌─────────┬───────────┬────────┬──────────┬────────┬─────────┐ │
│ │ ml      │ Milliliter│ mL     │ 1 (base) │ ✓      │ 👁       │ │
│ │ l       │ Liter     │ L      │ 1000     │ ✓      │ 👁       │ │
│ │ cl      │ Centiliter│ cL     │ 10       │ ✓      │ 👁       │ │
│ │ cup     │ Cup       │ cup    │ 236.588  │        │ ✏️ 🗑️    │ │
│ └─────────┴───────────┴────────┴──────────┴────────┴─────────┘ │
│                                                                 │
│ PIECES                                                          │
│ ...                                                             │
└─────────────────────────────────────────────────────────────────┘
```

### 7.2 Add/Edit Unit Modal

```
┌─────────────────────────────────────────────────────────────────┐
│ Add Unit                                                    [X] │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Category*        [Volume                              ▼]        │
│                                                                 │
│ Code*            [tbsp        ]  (unique identifier)            │
│                                                                 │
│ Name*            [Tablespoon  ]                                 │
│                                                                 │
│ Symbol*          [tbsp        ]  (shown in UI)                  │
│                                                                 │
│ Conversion Factor*  [14.787   ]  (1 tbsp = 14.787 ml)          │
│                                                                 │
│ ℹ️ Factor is how many base units (ml) equal 1 of this unit      │
│                                                                 │
│ Decimal Places   [1           ]  (display precision)            │
│                                                                 │
│ Rounding         [Half Up                             ▼]        │
│                                                                 │
│                                        [Cancel]  [Save Unit]    │
└─────────────────────────────────────────────────────────────────┘
```

### 7.3 Product Unit Selection

**Current (text field):**
```
Unit: [kg____________]  ← Free text, inconsistent
```

**New (dropdown):**
```
Unit: [Kilogram (kg)                              ▼]
      ├── WEIGHT
      │   ├── Gram (g)
      │   ├── Kilogram (kg)  ✓
      │   ├── Milligram (mg)
      │   ├── Pound (lb)
      │   └── Ounce (oz)
      ├── VOLUME
      │   ├── Milliliter (mL)
      │   ├── Liter (L)
      │   └── ...
      └── PIECES
          ├── Piece (pcs)
          ├── Dozen (doz)
          └── ...
```

**Grouped dropdown** for better UX, filterable by typing.

---

## 8. Migration Strategy

### 8.1 Data Migration

```sql
-- Step 1: Create UOM tables and seed system data
-- (done by migrations)

-- Step 2: Map existing text units to UOM IDs
CREATE TEMP TABLE unit_mapping AS
SELECT DISTINCT 
    LOWER(TRIM(unit)) as old_unit,
    CASE LOWER(TRIM(unit))
        WHEN 'kg' THEN (SELECT id FROM units_of_measure WHERE code = 'kg' AND is_system = true)
        WHEN 'kilogram' THEN (SELECT id FROM units_of_measure WHERE code = 'kg' AND is_system = true)
        WHEN 'g' THEN (SELECT id FROM units_of_measure WHERE code = 'g' AND is_system = true)
        WHEN 'gram' THEN (SELECT id FROM units_of_measure WHERE code = 'g' AND is_system = true)
        WHEN 'l' THEN (SELECT id FROM units_of_measure WHERE code = 'l' AND is_system = true)
        WHEN 'liter' THEN (SELECT id FROM units_of_measure WHERE code = 'l' AND is_system = true)
        WHEN 'litre' THEN (SELECT id FROM units_of_measure WHERE code = 'l' AND is_system = true)
        WHEN 'ml' THEN (SELECT id FROM units_of_measure WHERE code = 'ml' AND is_system = true)
        WHEN 'pc' THEN (SELECT id FROM units_of_measure WHERE code = 'pc' AND is_system = true)
        WHEN 'pcs' THEN (SELECT id FROM units_of_measure WHERE code = 'pc' AND is_system = true)
        WHEN 'piece' THEN (SELECT id FROM units_of_measure WHERE code = 'pc' AND is_system = true)
        -- Add more mappings as needed
        ELSE NULL
    END as new_unit_id
FROM products
WHERE unit IS NOT NULL;

-- Step 3: Add unit_id column
ALTER TABLE products ADD COLUMN unit_id UUID REFERENCES units_of_measure(id);

-- Step 4: Update products with mapped units
UPDATE products p
SET unit_id = m.new_unit_id
FROM unit_mapping m
WHERE LOWER(TRIM(p.unit)) = m.old_unit;

-- Step 5: Handle unmapped units (create as tenant custom units or review manually)
-- Generate report of unmapped units for manual review

-- Step 6: Eventually drop old unit column (after validation)
-- ALTER TABLE products DROP COLUMN unit;
```

### 8.2 Migration Phases

| Phase | Action | Risk |
|-------|--------|------|
| 1 | Add `unit_id` column (nullable) | None |
| 2 | Seed system UOM data | None |
| 3 | Run mapping migration | Low - review unmapped |
| 4 | Update UI to use dropdown | Low |
| 5 | Make `unit_id` required for new products | Low |
| 6 | Migrate remaining text units | Medium - needs review |
| 7 | Drop `unit` text column | Medium - point of no return |

---

## 9. API Endpoints

### 9.1 UOM Categories

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/uom/categories` | List all categories |
| GET | `/api/uom/categories/{id}` | Get category with units |
| POST | `/api/uom/categories` | Create custom category |
| PUT | `/api/uom/categories/{id}` | Update category |
| DELETE | `/api/uom/categories/{id}` | Deactivate category |

### 9.2 Units

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/uom/units` | List all units (filterable) |
| GET | `/api/uom/units/{id}` | Get unit details |
| POST | `/api/uom/units` | Create custom unit |
| PUT | `/api/uom/units/{id}` | Update unit |
| DELETE | `/api/uom/units/{id}` | Deactivate unit |

### 9.3 Conversion

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/uom/convert` | Convert quantity between units |

**Request:**
```json
{
  "quantity": 2.5,
  "from_unit_id": "uuid-kg",
  "to_unit_id": "uuid-g"
}
```

**Response:**
```json
{
  "original_quantity": 2.5,
  "original_unit": { "code": "kg", "symbol": "kg" },
  "converted_quantity": 2500,
  "converted_unit": { "code": "g", "symbol": "g" },
  "conversion_factor": 1000
}
```

---

## 10. Acceptance Criteria

### 10.1 Must Have

- [ ] System-seeded UOM categories (Weight, Volume, Length, Pieces, Time)
- [ ] System-seeded common units with conversion factors
- [ ] Tenant can create custom units within categories
- [ ] Products reference `unit_id` instead of text field
- [ ] Product form shows unit dropdown grouped by category
- [ ] UOM settings page for tenant admins
- [ ] Conversion service available to other modules
- [ ] Migration path for existing text-based units

### 10.2 Should Have

- [ ] Unit search/filter in dropdown
- [ ] Commonly used units appear at top of dropdown
- [ ] Secondary unit support for products (sell vs stock unit)
- [ ] Conversion preview in recipe builder

### 10.3 Nice to Have

- [ ] Batch unit assignment for multiple products
- [ ] Import/export unit configurations
- [ ] Unit usage analytics (most used units)

---

## 11. Open Questions

| # | Question | Status |
|---|----------|--------|
| 1 | Allow tenant to hide system units they don't use? | Recommend yes |
| 2 | Support imperial vs metric preference at tenant level? | Needs decision |
| 3 | Allow "box" and "case" with variable piece counts per product? | Recommend yes, store factor on product |
| 4 | Temperature units (°C, °F) with offset conversion? | Defer unless needed |

---

*— End of Document —*
