# Inventory Counting Feature

> Double-blind physical inventory counting with mobile app support.

---

## Overview

Systematic inventory counting with:
- **Blind Counting** - Counters never see theoretical quantities
- **Multi-Counter Verification** - 1, 2, or 3 independent counts
- **Mobile-First** - React Native app as primary interface
- **Discrepancy Tracking** - Full audit trail with resolution workflow

---

## Counting Modes

| Mode | Counters | Use Case |
|------|----------|----------|
| Single Count | 1 | Quick spot checks, low-value items |
| Double Count | 2 | Standard verification |
| Triple Count | 3 | High-value items, discrepancy resolution |

---

## Blind Counting Principle

```
┌─────────────────────────────────────────────────────────┐
│                 WHAT COUNTERS SEE                       │
├─────────────────────────────────────────────────────────┤
│  ✓ Product name, code, barcode                         │
│  ✓ Location to count at                                │
│  ✓ Product image (if available)                        │
│  ✓ Unit of measure                                     │
├─────────────────────────────────────────────────────────┤
│               WHAT COUNTERS DON'T SEE                   │
├─────────────────────────────────────────────────────────┤
│  ✗ Theoretical/system quantity                         │
│  ✗ Other counters' results                             │
│  ✗ Previous count history                              │
└─────────────────────────────────────────────────────────┘
```

---

## Workflow

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    CREATE    │ ──► │   COUNTING   │ ──► │   REVIEW     │
│   Session    │     │    Phase     │     │  Discrepancy │
└──────────────┘     └──────────────┘     └──────────────┘
       │                    │                    │
       ▼                    ▼                    ▼
  Define scope       Counters submit      Supervisor
  Assign counters    blind counts         resolves diffs
                                               │
                                               ▼
                                        ┌──────────────┐
                                        │   ADJUST     │
                                        │    Stock     │
                                        └──────────────┘
```

---

## Count Scopes

| Scope | Description |
|-------|-------------|
| Product + Location | Specific product at specific shelf/bin |
| Product | All locations for a product |
| Location | All products at a location |
| Category | All products in a category |
| Warehouse | All products in warehouse |
| Full | Entire inventory |

---

## Reconciliation Algorithm

1. **Collect all counts** for each item
2. **Compare counts**:
   - If all match → Use counted value
   - If 2 of 3 match → Use majority value
   - If all different → Flag for review
3. **Compare to theoretical**:
   - Within tolerance → Auto-approve
   - Outside tolerance → Require supervisor approval
4. **Generate adjustments**:
   - Create stock adjustment records
   - Update weighted average cost if needed

---

## Database Models

### InventoryCounting (Session)

```php
InventoryCounting {
    id: UUID
    company_id: UUID
    status: CountingStatus (draft, in_progress, review, completed, cancelled)
    scope_type: CountingScopeType
    execution_mode: CountingExecutionMode (single, double, triple)
    scheduled_date: date
    started_at: datetime?
    completed_at: datetime?
}
```

### InventoryCountingAssignment

```php
InventoryCountingAssignment {
    id: UUID
    counting_id: UUID
    user_id: UUID
    counter_number: int (1, 2, or 3)
    status: AssignmentStatus
}
```

### InventoryCountingItem

```php
InventoryCountingItem {
    id: UUID
    counting_id: UUID
    product_id: UUID
    location_id: UUID
    theoretical_quantity: decimal  // Hidden from counters
    count_1: decimal?
    count_2: decimal?
    count_3: decimal?
    final_quantity: decimal?
    resolution_method: ItemResolutionMethod?
    discrepancy_notes: string?
}
```

---

## Mobile App Screens

| Screen | Purpose |
|--------|---------|
| Task List | View assigned counting sessions |
| Count Session | List of items to count |
| Item Count | Enter count for single item |
| Barcode Scanner | Scan product barcode |
| Sync Status | View offline queue |

---

## API Endpoints

```
# Sessions
GET    /api/inventory-countings              # List sessions
POST   /api/inventory-countings              # Create session
GET    /api/inventory-countings/{id}         # Get session details
POST   /api/inventory-countings/{id}/start   # Start counting phase
POST   /api/inventory-countings/{id}/close   # Close counting, start review

# Counting (Mobile)
GET    /api/counting-assignments/mine        # My assigned sessions
GET    /api/counting-items/{id}              # Get item (blind - no qty)
POST   /api/counting-items/{id}/submit       # Submit count

# Reconciliation
GET    /api/inventory-countings/{id}/discrepancies
POST   /api/inventory-countings/{id}/resolve
POST   /api/inventory-countings/{id}/complete
```

---

## Implementation Status

| Component | Status |
|-----------|--------|
| Backend Models | Complete |
| Backend Services | Complete |
| API Endpoints | Complete |
| Web Management UI | Partial |
| Mobile App | In Progress |
| Reconciliation UI | Pending |

---

## Related Documentation

- [Mobile App](../mobile/README.md) - Mobile app documentation
- [Inventory Module](../modules/inventory.md) - Stock management
