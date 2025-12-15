# AutoERP Inventory Counting Module

## Executive Summary

This document specifies the Inventory Counting module for AutoERP, designed for automotive service businesses (mechanics, body shops, car glass specialists, quick service centers) with future expansion to retail. The module implements **double-blind counting** with optional third-count verification, comprehensive discrepancy tracking, and fraud detection capabilities.

The design draws from industry best practices including SAP Business One's multi-counter system, blind counting methodologies, and cycle counting principles while adding AutoERP-specific features like event sourcing integration and anomaly detection for worker behavior analysis.

---

## Table of Contents

1. [Feature Overview](#1-feature-overview)
2. [Core Concepts](#2-core-concepts)
3. [User Roles & Permissions](#3-user-roles--permissions)
4. [Database Schema](#4-database-schema)
5. [Business Logic & State Machine](#5-business-logic--state-machine)
6. [Reconciliation Algorithm](#6-reconciliation-algorithm)
7. [Discrepancy Report](#7-discrepancy-report)
8. [API Endpoints](#8-api-endpoints)
9. [Event Sourcing Integration](#9-event-sourcing-integration)
10. [Mobile App Specifications](#10-mobile-app-specifications)
11. [Anomaly Detection](#11-anomaly-detection)
12. [Implementation Phases](#12-implementation-phases)

---

## 1. Feature Overview

### 1.1 Purpose

Enable businesses to conduct systematic inventory counts with:
- **Blind counting**: Counters don't see theoretical quantities or other counters' results
- **Multi-counter verification**: 1, 2, or 3 independent counts for accuracy
- **Granular scope**: Count specific products, categories, locations, warehouses, or entire inventory
- **Discrepancy tracking**: Full audit trail of all variances and resolutions
- **Fraud detection**: Pattern analysis to identify suspicious counter behavior

### 1.2 Key Differentiators from Standard ERP

| Feature | Standard ERP | AutoERP |
|---------|--------------|---------|
| Blind counting | Optional | **Mandatory** (counters never see theoretical qty) |
| Multi-counter | Team counting (additive) | **Independent validation** (comparative) |
| Third count | Manual process | **Automated trigger** based on discrepancies |
| Discrepancy reports | Basic variance reports | **Comprehensive audit trail** with resolution tracking |
| Fraud detection | None | **Anomaly detection** with counter behavior analysis |
| Mobile-first | Desktop focus | **React Native app** as primary interface |

### 1.3 Counting Configurations

| Mode | Counters | Use Case |
|------|----------|----------|
| **Single Count** | 1 counter | Quick spot checks, low-value items |
| **Double Count** | 2 counters | Standard verification, moderate value |
| **Triple Count** | 3 counters | High-value items, fraud investigation |

---

## 2. Core Concepts

### 2.1 Blind Counting Principle

```
┌─────────────────────────────────────────────────────────────────┐
│                    BLIND COUNTING RULES                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Counter 1, 2, and 3 NEVER see:                                │
│  ✗ Theoretical/system quantity                                 │
│  ✗ Other counters' results                                     │
│  ✗ Previous count history for this item                        │
│                                                                 │
│  Counter sees ONLY:                                            │
│  ✓ Product name/code/barcode                                   │
│  ✓ Location to count at                                        │
│  ✓ Product image (if available)                                │
│  ✓ Unit of measure                                             │
│                                                                 │
│  Admin/Supervisor sees:                                        │
│  ✓ All counts (after counting phase closes)                    │
│  ✓ Theoretical quantities                                      │
│  ✓ Discrepancy analysis                                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Count Scopes

```
Scope Hierarchy (from most specific to least):

┌─────────────────────────────────────────────────────────────────┐
│  PRODUCT + LOCATION                                             │
│  └─ Count specific product at specific shelf/bin               │
├─────────────────────────────────────────────────────────────────┤
│  PRODUCT (ALL LOCATIONS)                                        │
│  └─ Count a product across all its storage locations           │
├─────────────────────────────────────────────────────────────────┤
│  LOCATION                                                       │
│  └─ Count everything at a specific shelf/section               │
├─────────────────────────────────────────────────────────────────┤
│  CATEGORY                                                       │
│  └─ Count all products in a category                           │
├─────────────────────────────────────────────────────────────────┤
│  WAREHOUSE                                                      │
│  └─ Full count of one warehouse                                │
├─────────────────────────────────────────────────────────────────┤
│  FULL INVENTORY                                                 │
│  └─ Count everything across all warehouses                     │
│  └─ ONLY scope that allows reporting "unexpected items"        │
└─────────────────────────────────────────────────────────────────┘
```

### 2.3 Counting Execution Modes

| Mode | Description | When to Use |
|------|-------------|-------------|
| **Parallel** | Counters work simultaneously | Different people, time-sensitive |
| **Sequential** | Counter 2 starts after Counter 1 completes | Same person doing both counts |

> **Rule**: If same person is assigned to multiple counts, system enforces sequential mode automatically.

---

## 3. User Roles & Permissions

### 3.1 Permission Matrix

| Permission | Counter | Inventory Supervisor | Admin |
|------------|---------|---------------------|-------|
| View assigned count tasks | ✓ | ✓ | ✓ |
| Submit count results | ✓ | ✓ | ✓ |
| Create counting operation | ✗ | ✓ | ✓ |
| Assign counters | ✗ | ✓ | ✓ |
| View theoretical quantities | ✗ | ✓ | ✓ |
| View other counters' results | ✗ | ✓ (after close) | ✓ |
| Add items to 3rd count | ✗ | ✓ | ✓ |
| Override final quantities | ✗ | ✓ | ✓ |
| Generate discrepancy report | ✗ | ✓ | ✓ |
| View anomaly reports | ✗ | ✗ | ✓ |
| Configure counting settings | ✗ | ✗ | ✓ |

### 3.2 Role Definitions

```php
// Suggested permission names for Laravel
return [
    'inventory.count.view_assigned',      // View own count tasks
    'inventory.count.submit',              // Submit count results
    'inventory.count.create',              // Create counting operations
    'inventory.count.assign',              // Assign counters
    'inventory.count.view_theoretical',    // See system quantities
    'inventory.count.view_all_results',    // See all counter results
    'inventory.count.trigger_recount',     // Add items to 3rd count
    'inventory.count.override',            // Manually set final quantity
    'inventory.count.generate_report',     // Create discrepancy reports
    'inventory.count.view_anomalies',      // Access fraud detection
    'inventory.count.configure',           // System settings
];
```

---

## 4. Database Schema

### 4.1 Entity Relationship Diagram

```
┌──────────────────────┐       ┌──────────────────────┐
│ inventory_countings  │       │ inventory_counting_  │
│                      │       │      assignments     │
│ id                   │───┐   │                      │
│ company_id           │   │   │ id                   │
│ created_by_user_id   │   │   │ counting_id          │──┐
│ scope_type           │   └──►│ user_id              │  │
│ scope_filters (JSON) │       │ count_number (1,2,3) │  │
│ execution_mode       │       │ status               │  │
│ status               │       │ assigned_at          │  │
│ scheduled_start      │       │ started_at           │  │
│ scheduled_end        │       │ completed_at         │  │
│ count_1_user_id      │       │ deadline             │  │
│ count_2_user_id      │       └──────────────────────┘  │
│ count_3_user_id      │                                 │
│ requires_count_2     │       ┌──────────────────────┐  │
│ requires_count_3     │       │ inventory_counting_  │  │
│ allow_unexpected     │       │       items          │  │
│ created_at           │       │                      │  │
│ updated_at           │       │ id                   │  │
└──────────────────────┘       │ counting_id          │──┘
                               │ product_id           │
                               │ variant_id           │
                               │ location_id          │
                               │ warehouse_id         │
                               │ theoretical_qty      │
                               │ count_1_qty          │
                               │ count_1_at           │
                               │ count_2_qty          │
                               │ count_2_at           │
                               │ count_3_qty          │
                               │ count_3_at           │
                               │ final_qty            │
                               │ resolution_method    │
                               │ resolution_notes     │
                               │ resolved_by_user_id  │
                               │ resolved_at          │
                               │ is_flagged           │
                               │ flag_reason          │
                               │ is_unexpected_item   │
                               │ created_at           │
                               │ updated_at           │
                               └──────────────────────┘
```

### 4.2 Complete Schema Definitions

```sql
-- Main counting operation table
CREATE TABLE inventory_countings (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    
    -- Who created this counting operation
    created_by_user_id BIGINT NOT NULL REFERENCES users(id),
    
    -- Scope configuration
    scope_type VARCHAR(50) NOT NULL CHECK (scope_type IN (
        'product_location',  -- Specific product at specific location
        'product',           -- Product across all locations
        'location',          -- Everything at a location
        'category',          -- All products in category
        'warehouse',         -- Full warehouse count
        'full_inventory'     -- Everything
    )),
    
    -- Flexible filters stored as JSON
    -- Examples:
    -- {"product_ids": [1,2,3], "location_id": 5}
    -- {"category_ids": [10,20]}
    -- {"warehouse_ids": [1,2]}
    scope_filters JSONB NOT NULL DEFAULT '{}',
    
    -- Counting configuration
    execution_mode VARCHAR(20) NOT NULL DEFAULT 'parallel' 
        CHECK (execution_mode IN ('parallel', 'sequential')),
    requires_count_2 BOOLEAN NOT NULL DEFAULT true,
    requires_count_3 BOOLEAN NOT NULL DEFAULT false,
    allow_unexpected_items BOOLEAN NOT NULL DEFAULT false,
    
    -- Counter assignments (denormalized for quick access)
    count_1_user_id BIGINT REFERENCES users(id),
    count_2_user_id BIGINT REFERENCES users(id),
    count_3_user_id BIGINT REFERENCES users(id),
    
    -- Scheduling
    scheduled_start TIMESTAMP WITH TIME ZONE NOT NULL,
    scheduled_end TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- Status tracking
    status VARCHAR(30) NOT NULL DEFAULT 'draft' CHECK (status IN (
        'draft',              -- Being configured
        'scheduled',          -- Ready to start
        'count_1_in_progress', 
        'count_1_completed',
        'count_2_in_progress',
        'count_2_completed',
        'count_3_in_progress',
        'count_3_completed',
        'pending_review',     -- All counts done, awaiting admin review
        'finalized',          -- Report generated, quantities adjusted
        'cancelled'
    )),
    
    -- Final report
    report_generated_at TIMESTAMP WITH TIME ZONE,
    report_generated_by_user_id BIGINT REFERENCES users(id),
    
    -- Audit fields
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT valid_schedule CHECK (scheduled_end > scheduled_start),
    CONSTRAINT valid_count_2_assignment CHECK (
        NOT requires_count_2 OR count_2_user_id IS NOT NULL
    ),
    CONSTRAINT valid_count_3_assignment CHECK (
        NOT requires_count_3 OR count_3_user_id IS NOT NULL
    )
);

-- Index for company queries
CREATE INDEX idx_inventory_countings_company ON inventory_countings(company_id);
CREATE INDEX idx_inventory_countings_status ON inventory_countings(status);
CREATE INDEX idx_inventory_countings_schedule ON inventory_countings(scheduled_start, scheduled_end);


-- Counter assignments (for notifications and tracking)
CREATE TABLE inventory_counting_assignments (
    id BIGSERIAL PRIMARY KEY,
    counting_id BIGINT NOT NULL REFERENCES inventory_countings(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id),
    count_number SMALLINT NOT NULL CHECK (count_number IN (1, 2, 3)),
    
    -- Assignment status
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN (
        'pending',      -- Not yet started
        'in_progress',  -- Actively counting
        'completed',    -- Finished all items
        'overdue'       -- Deadline passed without completion
    )),
    
    -- Timestamps
    assigned_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    notified_at TIMESTAMP WITH TIME ZONE,
    started_at TIMESTAMP WITH TIME ZONE,
    completed_at TIMESTAMP WITH TIME ZONE,
    deadline TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- Stats (denormalized for quick dashboard)
    total_items INTEGER NOT NULL DEFAULT 0,
    counted_items INTEGER NOT NULL DEFAULT 0,
    
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    UNIQUE(counting_id, user_id, count_number)
);

CREATE INDEX idx_counting_assignments_user ON inventory_counting_assignments(user_id, status);


-- Individual items to count
CREATE TABLE inventory_counting_items (
    id BIGSERIAL PRIMARY KEY,
    counting_id BIGINT NOT NULL REFERENCES inventory_countings(id) ON DELETE CASCADE,
    
    -- What to count
    product_id BIGINT NOT NULL REFERENCES products(id),
    variant_id BIGINT REFERENCES product_variants(id),  -- If applicable
    location_id BIGINT REFERENCES warehouse_locations(id),
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    
    -- System quantity at time of counting creation (frozen)
    theoretical_qty DECIMAL(15, 4) NOT NULL,
    theoretical_qty_frozen_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    -- Count results (NULL until counted)
    count_1_qty DECIMAL(15, 4),
    count_1_at TIMESTAMP WITH TIME ZONE,
    count_1_notes TEXT,
    
    count_2_qty DECIMAL(15, 4),
    count_2_at TIMESTAMP WITH TIME ZONE,
    count_2_notes TEXT,
    
    count_3_qty DECIMAL(15, 4),
    count_3_at TIMESTAMP WITH TIME ZONE,
    count_3_notes TEXT,
    
    -- Resolution
    final_qty DECIMAL(15, 4),
    resolution_method VARCHAR(30) CHECK (resolution_method IN (
        'auto_all_match',           -- All counts match theoretical
        'auto_counters_agree',      -- Counters agree, differs from theoretical
        'third_count_decisive',     -- 3rd count broke the tie
        'manual_override',          -- Admin manually set value
        'pending'                   -- Not yet resolved
    )),
    resolution_notes TEXT,
    resolved_by_user_id BIGINT REFERENCES users(id),
    resolved_at TIMESTAMP WITH TIME ZONE,
    
    -- Flags for discrepancy report
    is_flagged BOOLEAN NOT NULL DEFAULT false,
    flag_reason VARCHAR(100),  -- e.g., 'counter_disagreement', 'variance_from_theoretical', 'suspicious_pattern'
    
    -- For unexpected items found during full counts
    is_unexpected_item BOOLEAN NOT NULL DEFAULT false,
    
    -- Stock adjustment reference (after finalization)
    stock_adjustment_id BIGINT,  -- References stock_movements or adjustments table
    
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    -- Ensure unique product/location per counting
    UNIQUE(counting_id, product_id, COALESCE(variant_id, 0), COALESCE(location_id, 0), warehouse_id)
);

CREATE INDEX idx_counting_items_counting ON inventory_counting_items(counting_id);
CREATE INDEX idx_counting_items_product ON inventory_counting_items(product_id);
CREATE INDEX idx_counting_items_flagged ON inventory_counting_items(counting_id, is_flagged) WHERE is_flagged = true;


-- Audit log for count submissions (event sourcing)
CREATE TABLE inventory_counting_events (
    id BIGSERIAL PRIMARY KEY,
    counting_id BIGINT NOT NULL REFERENCES inventory_countings(id),
    item_id BIGINT REFERENCES inventory_counting_items(id),
    
    event_type VARCHAR(50) NOT NULL,  -- 'count_submitted', 'resolution_changed', 'flag_added', etc.
    event_data JSONB NOT NULL,
    
    user_id BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    -- Hash chain for tamper-proofing
    previous_hash VARCHAR(64),
    event_hash VARCHAR(64) NOT NULL
);

CREATE INDEX idx_counting_events_counting ON inventory_counting_events(counting_id);
CREATE INDEX idx_counting_events_user ON inventory_counting_events(user_id);


-- Counter performance metrics (for anomaly detection)
CREATE TABLE inventory_counter_metrics (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id),
    user_id BIGINT NOT NULL REFERENCES users(id),
    
    -- Aggregated metrics
    total_counts INTEGER NOT NULL DEFAULT 0,
    total_items_counted INTEGER NOT NULL DEFAULT 0,
    
    -- Accuracy metrics
    matches_with_theoretical INTEGER NOT NULL DEFAULT 0,
    matches_with_other_counter INTEGER NOT NULL DEFAULT 0,
    disagreements_proven_wrong INTEGER NOT NULL DEFAULT 0,  -- Counter was wrong after 3rd count
    disagreements_proven_right INTEGER NOT NULL DEFAULT 0,  -- Counter was right after 3rd count
    
    -- Timing metrics
    avg_seconds_per_item DECIMAL(10, 2),
    
    -- Rolling window (last 30 days)
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    
    UNIQUE(company_id, user_id, period_start)
);

CREATE INDEX idx_counter_metrics_user ON inventory_counter_metrics(user_id);
```

### 4.3 Row-Level Security Policies

```sql
-- Counting operations: company-scoped
ALTER TABLE inventory_countings ENABLE ROW LEVEL SECURITY;

CREATE POLICY inventory_countings_company_policy ON inventory_countings
    USING (company_id = current_setting('app.current_company_id')::bigint);

-- Counting items: inherit from parent counting
ALTER TABLE inventory_counting_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY inventory_counting_items_policy ON inventory_counting_items
    USING (
        counting_id IN (
            SELECT id FROM inventory_countings 
            WHERE company_id = current_setting('app.current_company_id')::bigint
        )
    );

-- Counter view: can only see items assigned to them (before resolution)
-- This is enforced at application layer for the "blind" aspect
```

---

## 5. Business Logic & State Machine

### 5.1 Counting Operation State Machine

```
                                    ┌─────────┐
                                    │  DRAFT  │
                                    └────┬────┘
                                         │ activate()
                                         ▼
                                  ┌──────────────┐
                                  │  SCHEDULED   │
                                  └──────┬───────┘
                                         │ start_count_1()
                                         ▼
                              ┌────────────────────────┐
                              │  COUNT_1_IN_PROGRESS   │
                              └──────────┬─────────────┘
                                         │ complete_count_1()
                                         ▼
                              ┌────────────────────────┐
          ┌───────────────────│  COUNT_1_COMPLETED     │
          │                   └──────────┬─────────────┘
          │ (if !requires_count_2)       │ start_count_2()
          │                              ▼
          │                   ┌────────────────────────┐
          │                   │  COUNT_2_IN_PROGRESS   │
          │                   └──────────┬─────────────┘
          │                              │ complete_count_2()
          │                              ▼
          │                   ┌────────────────────────┐
          │   ┌───────────────│  COUNT_2_COMPLETED     │
          │   │               └──────────┬─────────────┘
          │   │ (if !requires_count_3    │ start_count_3()
          │   │  && no items added)      │ (if items need 3rd count)
          │   │                          ▼
          │   │               ┌────────────────────────┐
          │   │               │  COUNT_3_IN_PROGRESS   │
          │   │               └──────────┬─────────────┘
          │   │                          │ complete_count_3()
          │   │                          ▼
          │   │               ┌────────────────────────┐
          │   │               │  COUNT_3_COMPLETED     │
          │   │               └──────────┬─────────────┘
          │   │                          │
          ▼   ▼                          ▼
     ┌────────────────────────────────────────┐
     │            PENDING_REVIEW              │
     │  (Admin reviews discrepancies)         │
     └─────────────────┬──────────────────────┘
                       │ finalize()
                       ▼
                ┌──────────────┐
                │  FINALIZED   │
                │  (Report     │
                │  generated,  │
                │  stock       │
                │  adjusted)   │
                └──────────────┘
```

### 5.2 Item Resolution State Machine

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        ITEM RESOLUTION FLOW                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  After Count 1 & Count 2 Complete:                                      │
│  ─────────────────────────────────                                      │
│                                                                          │
│  CASE A: count_1 == count_2 == theoretical                              │
│  └─► resolution_method = 'auto_all_match'                               │
│  └─► final_qty = theoretical                                            │
│  └─► is_flagged = false                                                 │
│                                                                          │
│  CASE B: count_1 == count_2 != theoretical                              │
│  └─► resolution_method = 'auto_counters_agree'                          │
│  └─► final_qty = count_1 (or count_2, same value)                       │
│  └─► is_flagged = true                                                  │
│  └─► flag_reason = 'variance_from_theoretical'                          │
│                                                                          │
│  CASE C: count_1 != count_2                                             │
│  └─► If one matches theoretical:                                        │
│      └─► Add to 3rd count queue                                         │
│      └─► is_flagged = true                                              │
│      └─► flag_reason = 'counter_disagreement'                           │
│  └─► If neither matches theoretical:                                    │
│      └─► Admin decides: trigger 3rd count OR manual override            │
│                                                                          │
│  After Count 3 Complete (if triggered):                                 │
│  ──────────────────────────────────────                                 │
│                                                                          │
│  CASE D: count_3 matches one counter AND matches theoretical            │
│  └─► resolution_method = 'third_count_decisive'                         │
│  └─► final_qty = theoretical                                            │
│  └─► is_flagged = true (the wrong counter is flagged for review)        │
│  └─► flag_reason = 'counter_proven_wrong'                               │
│                                                                          │
│  CASE E: count_3 matches one counter, differs from theoretical          │
│  └─► resolution_method = 'third_count_decisive'                         │
│  └─► final_qty = agreed quantity (2 out of 3)                           │
│  └─► is_flagged = true                                                  │
│  └─► flag_reason = 'variance_confirmed'                                 │
│                                                                          │
│  CASE F: All three counts differ                                        │
│  └─► resolution_method = 'manual_override' (required)                   │
│  └─► Admin MUST set final_qty manually                                  │
│  └─► is_flagged = true                                                  │
│  └─► flag_reason = 'no_consensus'                                       │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 5.3 Core Service Class

```php
<?php

namespace App\Services\Inventory;

use App\Models\InventoryCounting;
use App\Models\InventoryCountingItem;
use App\Events\Inventory\CountSubmitted;
use App\Events\Inventory\CountingFinalized;
use Illuminate\Support\Facades\DB;

class InventoryCountingService
{
    /**
     * Submit a count for an item (by a counter)
     * 
     * CRITICAL: Counter must NOT see theoretical qty or other counts
     */
    public function submitCount(
        InventoryCountingItem $item,
        int $countNumber,
        float $quantity,
        ?string $notes = null
    ): void {
        $this->validateCounterCanSubmit($item, $countNumber);
        
        DB::transaction(function () use ($item, $countNumber, $quantity, $notes) {
            $column = "count_{$countNumber}_qty";
            $timestampColumn = "count_{$countNumber}_at";
            $notesColumn = "count_{$countNumber}_notes";
            
            $item->update([
                $column => $quantity,
                $timestampColumn => now(),
                $notesColumn => $notes,
            ]);
            
            // Record event for audit trail
            $this->recordEvent($item, 'count_submitted', [
                'count_number' => $countNumber,
                'quantity' => $quantity,
                'notes' => $notes,
            ]);
            
            // Check if this completes a counting phase
            $this->checkPhaseCompletion($item->counting);
        });
        
        event(new CountSubmitted($item, $countNumber));
    }
    
    /**
     * Run automatic reconciliation after counting phases
     */
    public function runReconciliation(InventoryCounting $counting): array
    {
        $results = [
            'auto_resolved' => 0,
            'needs_third_count' => 0,
            'needs_manual_review' => 0,
        ];
        
        foreach ($counting->items as $item) {
            $result = $this->reconcileItem($item);
            $results[$result]++;
        }
        
        return $results;
    }
    
    /**
     * Reconcile a single item based on count results
     */
    protected function reconcileItem(InventoryCountingItem $item): string
    {
        $theoretical = $item->theoretical_qty;
        $count1 = $item->count_1_qty;
        $count2 = $item->count_2_qty;
        $count3 = $item->count_3_qty;
        
        // Single count mode
        if (!$item->counting->requires_count_2) {
            if ($count1 == $theoretical) {
                $this->resolveItem($item, $count1, 'auto_all_match');
                return 'auto_resolved';
            }
            // Single count differs - flag for review
            $item->update([
                'is_flagged' => true,
                'flag_reason' => 'variance_from_theoretical',
            ]);
            return 'needs_manual_review';
        }
        
        // Double count mode
        if ($count1 !== null && $count2 !== null) {
            // Both counters agree
            if ($this->floatsEqual($count1, $count2)) {
                if ($this->floatsEqual($count1, $theoretical)) {
                    // All match - perfect
                    $this->resolveItem($item, $count1, 'auto_all_match');
                    return 'auto_resolved';
                } else {
                    // Counters agree but differs from theoretical
                    $this->resolveItem($item, $count1, 'auto_counters_agree');
                    $item->update([
                        'is_flagged' => true,
                        'flag_reason' => 'variance_from_theoretical',
                    ]);
                    return 'auto_resolved';
                }
            }
            
            // Counters disagree
            if ($this->floatsEqual($count1, $theoretical) || $this->floatsEqual($count2, $theoretical)) {
                // One matches theoretical - needs 3rd count
                $item->update([
                    'is_flagged' => true,
                    'flag_reason' => 'counter_disagreement',
                ]);
                return 'needs_third_count';
            }
            
            // Neither matches theoretical - admin decides
            $item->update([
                'is_flagged' => true,
                'flag_reason' => 'counter_disagreement_no_match',
            ]);
            return 'needs_manual_review';
        }
        
        // Third count mode (if triggered)
        if ($count3 !== null) {
            return $this->reconcileWithThirdCount($item, $theoretical, $count1, $count2, $count3);
        }
        
        return 'needs_manual_review';
    }
    
    /**
     * Reconcile with third count
     */
    protected function reconcileWithThirdCount(
        InventoryCountingItem $item,
        float $theoretical,
        float $count1,
        float $count2,
        float $count3
    ): string {
        // Find consensus (2 out of 3)
        $values = [$count1, $count2, $count3];
        $counts = array_count_values(array_map(fn($v) => (string)$v, $values));
        
        foreach ($counts as $value => $occurrences) {
            if ($occurrences >= 2) {
                $consensusQty = (float) $value;
                $this->resolveItem($item, $consensusQty, 'third_count_decisive');
                
                // Determine which counter was wrong
                $wrongCounter = null;
                if (!$this->floatsEqual($count1, $consensusQty)) $wrongCounter = 1;
                elseif (!$this->floatsEqual($count2, $consensusQty)) $wrongCounter = 2;
                elseif (!$this->floatsEqual($count3, $consensusQty)) $wrongCounter = 3;
                
                $item->update([
                    'is_flagged' => true,
                    'flag_reason' => $this->floatsEqual($consensusQty, $theoretical) 
                        ? "counter_{$wrongCounter}_proven_wrong"
                        : 'variance_confirmed',
                ]);
                
                return 'auto_resolved';
            }
        }
        
        // All three differ - needs manual override
        $item->update([
            'is_flagged' => true,
            'flag_reason' => 'no_consensus',
        ]);
        return 'needs_manual_review';
    }
    
    /**
     * Manually override a quantity (admin only)
     */
    public function manualOverride(
        InventoryCountingItem $item,
        float $quantity,
        string $notes,
        int $userId
    ): void {
        DB::transaction(function () use ($item, $quantity, $notes, $userId) {
            $item->update([
                'final_qty' => $quantity,
                'resolution_method' => 'manual_override',
                'resolution_notes' => $notes,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
                'is_flagged' => true,
                'flag_reason' => 'manual_override',
            ]);
            
            $this->recordEvent($item, 'manual_override', [
                'quantity' => $quantity,
                'notes' => $notes,
            ]);
        });
    }
    
    /**
     * Finalize counting and adjust stock
     */
    public function finalize(InventoryCounting $counting, int $userId): void
    {
        // Validate all items are resolved
        $unresolved = $counting->items()->whereNull('final_qty')->count();
        if ($unresolved > 0) {
            throw new \Exception("Cannot finalize: {$unresolved} items still unresolved");
        }
        
        DB::transaction(function () use ($counting, $userId) {
            // Create stock adjustments for items with variance
            foreach ($counting->items as $item) {
                $variance = $item->final_qty - $item->theoretical_qty;
                
                if ($variance != 0) {
                    $this->createStockAdjustment($item, $variance);
                }
            }
            
            // Update counting status
            $counting->update([
                'status' => 'finalized',
                'report_generated_at' => now(),
                'report_generated_by_user_id' => $userId,
            ]);
            
            // Update counter metrics (for anomaly detection)
            $this->updateCounterMetrics($counting);
        });
        
        event(new CountingFinalized($counting));
    }
    
    // Helper methods
    protected function floatsEqual(float $a, float $b, float $epsilon = 0.0001): bool
    {
        return abs($a - $b) < $epsilon;
    }
    
    protected function resolveItem(InventoryCountingItem $item, float $quantity, string $method): void
    {
        $item->update([
            'final_qty' => $quantity,
            'resolution_method' => $method,
            'resolved_at' => now(),
        ]);
    }
    
    // ... additional helper methods
}
```

---

## 6. Reconciliation Algorithm

### 6.1 Complete Decision Matrix

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          RECONCILIATION DECISION MATRIX                          │
├─────────┬─────────┬─────────┬────────────┬─────────────────────────────────────┤
│ Count 1 │ Count 2 │ Count 3 │ Theoretical│ Resolution                          │
├─────────┼─────────┼─────────┼────────────┼─────────────────────────────────────┤
│   10    │    -    │    -    │     10     │ ✅ auto_all_match, final=10         │
│   10    │    -    │    -    │     12     │ ⚠️ Flagged, needs review            │
├─────────┼─────────┼─────────┼────────────┼─────────────────────────────────────┤
│   10    │   10    │    -    │     10     │ ✅ auto_all_match, final=10         │
│   10    │   10    │    -    │     12     │ ⚠️ auto_counters_agree, final=10   │
│         │         │         │            │    Flagged: variance_from_theoretical│
├─────────┼─────────┼─────────┼────────────┼─────────────────────────────────────┤
│   10    │   12    │    -    │     10     │ ⚠️ Needs 3rd count (C1 matches)    │
│   10    │   12    │    -    │     12     │ ⚠️ Needs 3rd count (C2 matches)    │
│   10    │   12    │    -    │     15     │ ⚠️ Needs admin decision            │
├─────────┼─────────┼─────────┼────────────┼─────────────────────────────────────┤
│   10    │   12    │   10    │     10     │ ✅ third_count_decisive, final=10  │
│         │         │         │            │    Flagged: counter_2_proven_wrong  │
│   10    │   12    │   10    │     15     │ ⚠️ third_count_decisive, final=10 │
│         │         │         │            │    Flagged: variance_confirmed      │
│   10    │   12    │   11    │     10     │ ❌ no_consensus, needs manual       │
└─────────┴─────────┴─────────┴────────────┴─────────────────────────────────────┘
```

### 6.2 Tolerance Configuration

```php
// config/inventory.php

return [
    'counting' => [
        // Tolerance for considering quantities "equal"
        // Useful for items that might have minor weighing differences
        'equality_tolerance' => [
            'default' => 0.0001,  // For discrete items
            'by_weight' => 0.5,   // 0.5kg tolerance for weight-based items
            'by_volume' => 0.1,   // 0.1L tolerance for liquid items
        ],
        
        // Variance thresholds for flagging
        'variance_thresholds' => [
            'minor' => 0.02,      // 2% - logged but not flagged
            'significant' => 0.05, // 5% - flagged for review
            'critical' => 0.10,   // 10% - immediate notification
        ],
        
        // Time limits
        'default_deadline_hours' => 48,
        'max_counting_duration_days' => 7,
        
        // Counter restrictions
        'same_counter_sequential_delay_minutes' => 30, // Wait before same person can do 2nd count
    ],
];
```

---

## 7. Discrepancy Report

### 7.1 Report Structure

```json
{
  "report_id": "CNT-2024-00123",
  "generated_at": "2024-12-02T14:30:00Z",
  "generated_by": "John Admin",
  "counting_operation": {
    "id": 123,
    "scope": "warehouse",
    "warehouse": "Main Warehouse",
    "scheduled_period": {
      "start": "2024-12-01T08:00:00Z",
      "end": "2024-12-02T12:00:00Z"
    }
  },
  "summary": {
    "total_items_counted": 450,
    "items_no_variance": 380,
    "items_with_variance": 70,
    "variance_breakdown": {
      "auto_all_match": 380,
      "auto_counters_agree": 45,
      "third_count_decisive": 15,
      "manual_override": 10
    },
    "total_variance_value": {
      "positive": 1250.00,
      "negative": -3420.00,
      "net": -2170.00,
      "currency": "EUR"
    }
  },
  "flagged_items": [
    {
      "product": "Brake Pad Set - BMW E90",
      "sku": "BP-BMW-E90-F",
      "location": "A-12-3",
      "theoretical_qty": 24,
      "count_1": { "qty": 22, "counter": "Marie Counter", "at": "..." },
      "count_2": { "qty": 22, "counter": "Pierre Counter", "at": "..." },
      "count_3": null,
      "final_qty": 22,
      "variance": -2,
      "variance_value": -89.50,
      "resolution_method": "auto_counters_agree",
      "flag_reason": "variance_from_theoretical",
      "notes": null
    },
    {
      "product": "Oil Filter - Mercedes W204",
      "sku": "OF-MB-W204",
      "location": "B-05-1",
      "theoretical_qty": 50,
      "count_1": { "qty": 48, "counter": "Marie Counter", "at": "..." },
      "count_2": { "qty": 52, "counter": "Pierre Counter", "at": "..." },
      "count_3": { "qty": 50, "counter": "Ahmed Counter", "at": "..." },
      "final_qty": 50,
      "variance": 0,
      "resolution_method": "third_count_decisive",
      "flag_reason": "counter_disagreement_resolved",
      "notes": "Marie undercounted, Pierre overcounted"
    }
  ],
  "counter_performance": [
    {
      "counter": "Marie Counter",
      "items_counted": 225,
      "matched_other_counter": 210,
      "matched_theoretical": 205,
      "times_proven_wrong_by_3rd": 3,
      "accuracy_rate": 0.933
    }
  ]
}
```

### 7.2 Report Generation Query

```php
class DiscrepancyReportService
{
    public function generateReport(InventoryCounting $counting): array
    {
        $items = $counting->items()
            ->with(['product', 'variant', 'location', 'warehouse'])
            ->get();
        
        $flaggedItems = $items->where('is_flagged', true)
            ->sortByDesc(fn($item) => abs($item->final_qty - $item->theoretical_qty))
            ->map(fn($item) => $this->formatFlaggedItem($item));
        
        $counterPerformance = $this->calculateCounterPerformance($counting);
        
        return [
            'report_id' => 'CNT-' . date('Y') . '-' . str_pad($counting->id, 5, '0', STR_PAD_LEFT),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => auth()->user()->name,
            'counting_operation' => [
                'id' => $counting->id,
                'scope' => $counting->scope_type,
                'scheduled_period' => [
                    'start' => $counting->scheduled_start->toIso8601String(),
                    'end' => $counting->scheduled_end->toIso8601String(),
                ],
            ],
            'summary' => [
                'total_items_counted' => $items->count(),
                'items_no_variance' => $items->where('resolution_method', 'auto_all_match')->count(),
                'items_with_variance' => $items->where('resolution_method', '!=', 'auto_all_match')->count(),
                'variance_breakdown' => $items->groupBy('resolution_method')
                    ->map->count()
                    ->toArray(),
                'total_variance_value' => $this->calculateVarianceValue($items),
            ],
            'flagged_items' => $flaggedItems->values()->toArray(),
            'counter_performance' => $counterPerformance,
        ];
    }
}
```

---

## 8. API Endpoints

### 8.1 Counting Operations

```yaml
# Create counting operation
POST /api/v1/inventory/countings
Body:
  scope_type: "warehouse"
  scope_filters: { warehouse_ids: [1] }
  execution_mode: "parallel"
  requires_count_2: true
  requires_count_3: false
  count_1_user_id: 10
  count_2_user_id: 11
  scheduled_start: "2024-12-05T08:00:00Z"
  scheduled_end: "2024-12-06T18:00:00Z"
Response: 201 Created

# Get counting operation details (admin view)
GET /api/v1/inventory/countings/{id}
Response:
  - Full details including theoretical quantities
  - All count results (after completion)
  - Resolution status

# Get counting operation (counter view - BLIND)
GET /api/v1/inventory/countings/{id}/counter-view
Response:
  - Product info, location
  - NO theoretical quantities
  - NO other counters' results
  - Only own submissions

# Activate counting operation
POST /api/v1/inventory/countings/{id}/activate

# Cancel counting operation
POST /api/v1/inventory/countings/{id}/cancel
```

### 8.2 Counter Actions

```yaml
# Get assigned counting tasks (for mobile app)
GET /api/v1/inventory/countings/my-tasks
Query: status=pending|in_progress
Response:
  - List of assigned countings
  - Progress (counted/total items)
  - Deadline

# Get items to count (BLIND - no theoretical qty shown)
GET /api/v1/inventory/countings/{id}/items/to-count
Response:
  - product_id, product_name, sku, barcode
  - variant info if applicable
  - location info
  - image_url
  - unit_of_measure
  - already_counted: boolean

# Submit count for an item
POST /api/v1/inventory/countings/{id}/items/{item_id}/count
Body:
  quantity: 24
  notes: "Some items damaged, not counted"
Response:
  - success: true
  - items_remaining: 45

# Lookup product by barcode (for mobile scanning)
GET /api/v1/inventory/countings/{id}/lookup
Query: barcode=1234567890123
Response:
  - item_id (in this counting)
  - product info
  - location info
```

### 8.3 Supervisor Actions

```yaml
# Get reconciliation results
GET /api/v1/inventory/countings/{id}/reconciliation

# Add items to 3rd count
POST /api/v1/inventory/countings/{id}/trigger-third-count
Body:
  item_ids: [101, 102, 103]

# Manual override
POST /api/v1/inventory/countings/{id}/items/{item_id}/override
Body:
  final_qty: 22
  notes: "Verified in person, 2 units were in wrong bin"

# Generate discrepancy report
POST /api/v1/inventory/countings/{id}/generate-report

# Finalize and adjust stock
POST /api/v1/inventory/countings/{id}/finalize
```

---

## 9. Event Sourcing Integration

### 9.1 Events

```php
<?php

namespace App\Events\Inventory;

// Counting operation events
class CountingCreated extends InventoryCountingEvent {
    public function eventType(): string { return 'counting.created'; }
}

class CountingActivated extends InventoryCountingEvent {
    public function eventType(): string { return 'counting.activated'; }
}

class CountingCancelled extends InventoryCountingEvent {
    public function eventType(): string { return 'counting.cancelled'; }
}

// Count submission events (per item)
class CountSubmitted extends InventoryCountingItemEvent {
    public int $countNumber;
    public float $quantity;
    public ?string $notes;
    
    public function eventType(): string { return 'counting.item.count_submitted'; }
}

// Resolution events
class ItemAutoResolved extends InventoryCountingItemEvent {
    public string $resolutionMethod;
    public float $finalQty;
    
    public function eventType(): string { return 'counting.item.auto_resolved'; }
}

class ItemManuallyOverridden extends InventoryCountingItemEvent {
    public float $finalQty;
    public string $notes;
    public int $overriddenBy;
    
    public function eventType(): string { return 'counting.item.manual_override'; }
}

// Finalization
class CountingFinalized extends InventoryCountingEvent {
    public array $stockAdjustmentIds;
    
    public function eventType(): string { return 'counting.finalized'; }
}
```

### 9.2 Hash Chain for Audit Trail

```php
class InventoryCountingEventRecorder
{
    public function record(InventoryCounting $counting, string $eventType, array $data): void
    {
        $previousEvent = $counting->events()->latest()->first();
        $previousHash = $previousEvent?->event_hash ?? 'GENESIS';
        
        $eventData = [
            'event_type' => $eventType,
            'event_data' => $data,
            'timestamp' => now()->toIso8601String(),
            'user_id' => auth()->id(),
        ];
        
        $eventHash = hash('sha256', json_encode($eventData) . $previousHash);
        
        InventoryCountingEvent::create([
            'counting_id' => $counting->id,
            'item_id' => $data['item_id'] ?? null,
            'event_type' => $eventType,
            'event_data' => $data,
            'user_id' => auth()->id(),
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
        ]);
    }
}
```

---

## 10. Mobile App Specifications

### 10.1 React Native Screens

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     MOBILE APP SCREEN FLOW                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  [Home/Dashboard]                                                       │
│       │                                                                 │
│       ├── Notification Badge: "You have 2 counting tasks"              │
│       │                                                                 │
│       └──► [My Counting Tasks]                                         │
│                 │                                                       │
│                 ├── Task Card:                                          │
│                 │   "Warehouse A Count"                                 │
│                 │   Due: Dec 5, 2024                                    │
│                 │   Progress: 45/120 items                              │
│                 │   [Continue Counting]                                 │
│                 │                                                       │
│                 └──► [Counting Session]                                │
│                           │                                             │
│                           ├── [Scan Barcode] or [Browse List]          │
│                           │                                             │
│                           └──► [Item Count Entry]                      │
│                                     │                                   │
│                                     ├── Product: Brake Pad Set         │
│                                     ├── Location: A-12-3               │
│                                     ├── [Product Image]                │
│                                     ├── Quantity: [____] units         │
│                                     ├── Notes: [____________]          │
│                                     │                                   │
│                                     ├── [Save & Next]                  │
│                                     └── [Save & Return to List]        │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 10.2 Key Components

```typescript
// Types
interface CountingTask {
  id: number;
  name: string;
  warehouseName: string;
  deadline: string;
  totalItems: number;
  countedItems: number;
  status: 'pending' | 'in_progress' | 'completed' | 'overdue';
}

interface CountingItem {
  id: number;
  productId: number;
  productName: string;
  productSku: string;
  productBarcode: string;
  productImageUrl?: string;
  variantName?: string;
  locationCode: string;
  locationName: string;
  unitOfMeasure: string;
  isCounted: boolean;
  myCount?: number;  // Only if already submitted
}

// CRITICAL: These fields must NEVER be included in mobile API response
interface NEVER_SEND_TO_COUNTER {
  theoreticalQty: number;        // ❌ NEVER
  otherCounterResults: any[];    // ❌ NEVER
  systemStockLevel: number;      // ❌ NEVER
}
```

### 10.3 Offline Support

```typescript
// React Native offline-first architecture

interface OfflineCountEntry {
  itemId: number;
  quantity: number;
  notes?: string;
  countedAt: string;  // ISO timestamp
  synced: boolean;
}

// AsyncStorage schema
const OFFLINE_STORAGE_KEYS = {
  PENDING_COUNTS: 'inventory_pending_counts',
  CACHED_TASKS: 'inventory_cached_tasks',
  CACHED_ITEMS: 'inventory_cached_items_{taskId}',
};

// Sync strategy
class CountingSyncService {
  async syncPendingCounts(): Promise<SyncResult> {
    const pending = await this.getPendingCounts();
    const results = { synced: 0, failed: 0, conflicts: [] };
    
    for (const entry of pending) {
      try {
        await api.submitCount(entry.itemId, entry.quantity, entry.notes);
        await this.markAsSynced(entry);
        results.synced++;
      } catch (error) {
        if (error.code === 'ALREADY_COUNTED') {
          results.conflicts.push(entry);
        } else {
          results.failed++;
        }
      }
    }
    
    return results;
  }
}
```

### 10.4 Barcode Scanning

```typescript
import { Camera, useCameraDevice, useCodeScanner } from 'react-native-vision-camera';

function BarcodeScannerScreen({ countingId, onProductFound }) {
  const device = useCameraDevice('back');
  
  const codeScanner = useCodeScanner({
    codeTypes: ['ean-13', 'ean-8', 'code-128', 'qr'],
    onCodeScanned: async (codes) => {
      const barcode = codes[0]?.value;
      if (!barcode) return;
      
      try {
        const item = await api.lookupByBarcode(countingId, barcode);
        onProductFound(item);
      } catch (error) {
        if (error.code === 'NOT_IN_COUNT') {
          // Product exists but not in this counting scope
          Alert.alert('Product not in count', 'This product is not part of this counting operation.');
        } else if (error.code === 'NOT_FOUND') {
          // Unknown barcode - only allowed in full inventory mode
          handleUnknownBarcode(barcode);
        }
      }
    },
  });
  
  return (
    <Camera
      device={device}
      isActive={true}
      codeScanner={codeScanner}
      style={StyleSheet.absoluteFill}
    />
  );
}
```

---

## 11. Anomaly Detection

### 11.1 Metrics to Track

```sql
-- Counter behavior patterns to analyze
SELECT 
  u.id as counter_id,
  u.name as counter_name,
  
  -- Accuracy metrics
  COUNT(*) as total_items_counted,
  SUM(CASE WHEN ci.count_1_qty = ci.theoretical_qty THEN 1 ELSE 0 END)::float / COUNT(*) as accuracy_vs_theoretical,
  
  -- Agreement with other counters
  SUM(CASE WHEN ci.count_1_qty = ci.count_2_qty THEN 1 ELSE 0 END)::float / COUNT(*) as agreement_rate,
  
  -- Times proven wrong by 3rd count
  SUM(CASE WHEN ci.flag_reason LIKE '%counter_1_proven_wrong%' THEN 1 ELSE 0 END) as times_proven_wrong,
  
  -- Variance direction bias (always over or under?)
  AVG(ci.count_1_qty - ci.theoretical_qty) as avg_variance,
  
  -- Speed (too fast might indicate not actually counting)
  AVG(EXTRACT(EPOCH FROM (ci.count_1_at - lag_time))) as avg_seconds_per_item,
  
  -- Pattern detection
  COUNT(DISTINCT ci.counting_id) as total_counting_operations

FROM users u
JOIN inventory_counting_assignments ica ON u.id = ica.user_id
JOIN inventory_counting_items ci ON ica.counting_id = ci.counting_id
WHERE ica.count_number = 1
  AND ci.count_1_qty IS NOT NULL
GROUP BY u.id, u.name;
```

### 11.2 Anomaly Flags

| Anomaly Type | Description | Threshold | Action |
|--------------|-------------|-----------|--------|
| **Low accuracy** | Frequently differs from theoretical | < 70% | Alert supervisor |
| **High disagreement** | Rarely matches other counter | < 60% agreement | Investigate |
| **Systematic bias** | Always over/under counts | Avg variance > ±5% | Training needed |
| **Speed anomaly** | Counts too fast | < 5 sec/item | Possible fraud |
| **Prove wrong rate** | Often wrong in 3rd counts | > 30% | Remove from counts |
| **Category bias** | Poor accuracy in specific categories | Per-category analysis | Possible theft target |

### 11.3 Anomaly Detection Service

```php
class CounterAnomalyDetectionService
{
    public function analyzeCounter(int $userId, int $companyId): CounterAnalysis
    {
        $metrics = InventoryCounterMetrics::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('period_end', '>=', now()->subDays(90))
            ->get();
        
        $analysis = new CounterAnalysis($userId);
        
        // Accuracy check
        $accuracyRate = $this->calculateAccuracyRate($metrics);
        if ($accuracyRate < 0.70) {
            $analysis->addFlag('low_accuracy', $accuracyRate);
        }
        
        // Bias check
        $avgVariance = $this->calculateAvgVariance($metrics);
        if (abs($avgVariance) > 0.05) {
            $analysis->addFlag('systematic_bias', $avgVariance);
        }
        
        // Speed check
        $avgSpeed = $this->calculateAvgSpeed($metrics);
        if ($avgSpeed < 5) {
            $analysis->addFlag('too_fast', $avgSpeed);
        }
        
        // Proven wrong rate
        $provenWrongRate = $this->calculateProvenWrongRate($metrics);
        if ($provenWrongRate > 0.30) {
            $analysis->addFlag('high_error_rate', $provenWrongRate);
        }
        
        return $analysis;
    }
    
    public function generateMonthlyReport(int $companyId): array
    {
        $counters = User::whereHas('countingAssignments', function ($q) use ($companyId) {
            $q->whereHas('counting', fn($q) => $q->where('company_id', $companyId));
        })->get();
        
        return $counters->map(fn($counter) => [
            'user' => $counter,
            'analysis' => $this->analyzeCounter($counter->id, $companyId),
        ])->toArray();
    }
}
```

---

## 12. Implementation Phases

### Phase 1: Core Backend (Week 1-2)

**Tasks:**
1. Database migrations
2. Models and relationships
3. Core `InventoryCountingService`
4. Reconciliation algorithm
5. Event recording

**Verification:**
```bash
php artisan test --filter=InventoryCountingTest
php artisan test --filter=ReconciliationAlgorithmTest
```

### Phase 2: API Layer (Week 2-3)

**Tasks:**
1. REST API controllers
2. Request validation
3. Authorization policies
4. API documentation

**Verification:**
```bash
php artisan test --filter=InventoryCountingApiTest
# Postman/Insomnia collection tests
```

### Phase 3: Web Frontend (Week 3-4)

**Tasks:**
1. Counting operation management UI
2. Counter assignment interface
3. Reconciliation review screen
4. Discrepancy report viewer

**Verification:**
```bash
npm run test
npx playwright test --grep "inventory-counting"
```

### Phase 4: Mobile App (Week 4-6)

**Tasks:**
1. React Native screens
2. Barcode scanning
3. Offline support
4. Push notifications

**Verification:**
```bash
# iOS Simulator
npx react-native run-ios

# Android Emulator
npx react-native run-android

# E2E tests
npx detox test
```

### Phase 5: Anomaly Detection (Week 6-7)

**Tasks:**
1. Metrics aggregation jobs
2. Anomaly detection algorithms
3. Alert system
4. Reporting dashboard

**Verification:**
```bash
php artisan test --filter=AnomalyDetectionTest
```

---

## Appendix A: Glossary

| Term | Definition |
|------|------------|
| **Blind Count** | Counting without knowing the expected/theoretical quantity |
| **Theoretical Quantity** | System-recorded quantity before counting |
| **Variance** | Difference between counted and theoretical quantity |
| **Reconciliation** | Process of comparing counts and determining final values |
| **Third Count** | Additional count triggered when first two disagree |
| **Discrepancy Report** | Document listing all variances and their resolutions |

---

## Appendix B: Related Documents

- [AutoERP Architecture Overview](./architecture-overview.md)
- [Event Sourcing Implementation](./event-sourcing.md)
- [Mobile App Development Guide](./mobile-app-guide.md)
- [Stock Movement Module](./stock-movements.md)
