# Mobile-Initiated Counting Operations - Implementation Plan

> **Feature:** Allow managers to create and build counting operations directly from mobile app
> **Created:** 2025-12-16
> **Status:** ✅ IMPLEMENTED (2025-12-16)
> **Complexity:** Medium (3-4 weeks)
> **Priority:** High Value-Add

---

## ✅ Implementation Status (Updated: 2025-12-16)

**COMPLETED:**
- ✅ Phase 1: Backend API (6 new endpoints)
- ✅ Phase 2: Mobile UI (5 new screens + draft store)
- ✅ Phase 3: Web Integration (mobile badge + filters)

**FUTURE ENHANCEMENTS:**
- ⏳ Phase 4: Advanced background sync for drafts
- ⏳ Phase 5: Full offline-first architecture

### What Works Now

**Mobile App:**
- ✅ Create draft counting operations
- ✅ Scan products to add incrementally
- ✅ Edit title and instructions
- ✅ Assign counter settings
- ✅ Activate draft to live counting
- ✅ Two-tab interface (My Tasks | My Drafts)

**Backend API:**
- ✅ `POST /inventory/countings/drafts` - Create draft
- ✅ `GET /inventory/countings/my-drafts` - List user's drafts
- ✅ `POST /inventory/countings/{id}/add-product` - Add by barcode
- ✅ `DELETE /inventory/countings/{id}/products/{product}` - Remove product
- ✅ `PATCH /inventory/countings/{id}/draft` - Update draft
- ✅ `POST /inventory/countings/{id}/activate-draft` - Activate

**Web Integration:**
- ✅ Mobile badge on counting list (📱 icon)
- ✅ Filter: "All Sources | Mobile Only | Web Only"
- ✅ Shows custom title when created on mobile
- ✅ TypeScript types updated with new fields

### Files Modified/Created

**Backend:**
- `database/migrations/2025_12_16_120000_add_mobile_initiation_to_inventory_countings.php`
- `app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php`
- `app/Modules/Inventory/Presentation/Requests/AddProductToCountingRequest.php`
- `app/Modules/Inventory/Presentation/Requests/UpdateDraftCountingRequest.php`
- `app/Modules/Inventory/Presentation/Requests/ActivateCountingRequest.php`
- `app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php` (6 new methods)
- `app/Modules/Inventory/Presentation/routes.php` (6 new routes)

**Mobile:**
- `src/features/counting/store/draftCountingStore.ts` (NEW)
- `src/features/counting/api/countingApi.ts` (extended)
- `src/features/counting/api/queries.ts` (6 new hooks)
- `app/(app)/tasks.tsx` (added drafts tab)
- `app/(app)/counting/create-draft.tsx` (NEW)
- `app/(app)/counting/draft/[id]/index.tsx` (NEW)
- `app/(app)/counting/draft/[id]/scan.tsx` (NEW)
- `app/(app)/counting/draft/[id]/assign.tsx` (NEW)

**Web:**
- `src/features/inventory-counting/types.ts` (updated InventoryCounting + CountingFilters)
- `src/features/inventory-counting/pages/CountingListPage.tsx` (mobile badge + filter)

---

## Executive Summary

Enable warehouse managers to initiate counting operations while physically in the warehouse, building the product list incrementally by scanning items over multiple days, then finalizing when ready. Full bidirectional sync with web application.

### Business Value

**Problem Solved:**
- Manager notices discrepancy while walking warehouse → must return to office to create count
- Ad-hoc spot checks require pre-planning (selecting products in web first)
- Suspicions about specific products can't be acted on immediately

**Solution:**
- Mobile-initiated counts with incremental product scanning
- Multi-day draft mode (save progress, come back later)
- Full offline support with automatic sync
- Seamless web integration (appears in web UI automatically)

**ROI:**
- Faster response to discrepancies (minutes vs hours)
- More frequent spot checks → better inventory accuracy
- Reduced theft/loss (immediate investigation capability)

---

## Use Case Scenarios

### Scenario 1: Suspicious Discrepancy
```
1. Manager walking warehouse, notices brake pad shelf looks emptier than expected
2. Opens mobile app → "New Count" → Scan barcode
3. Adds 3-4 related brake pad products by scanning
4. Assigns warehouse staff member as counter
5. Saves draft → Staff completes count within 2 hours
6. Manager reviews results in web → Confirms 15% shortage → Investigation triggered
```

### Scenario 2: Multi-Day Audit
```
Day 1:
- Manager starts "High-Value Items Audit"
- Scans 20 expensive products throughout the day
- Saves draft (50% complete)

Day 2:
- Resumes count from "My Drafts"
- Scans remaining 18 products
- Assigns 2 counters for dual counting
- Finalizes → Counting operation goes live

Day 3:
- Counters complete → Manager reviews in web
```

### Scenario 3: Cycle Count Rotation
```
- Mobile app suggests products for weekly cycle count (backend logic)
- Manager accepts suggestion or modifies list by scanning
- Creates count with 1-click → assigned to rotating counter
- System tracks cycle count compliance per product
```

---

## Architecture Design

### High-Level Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    MOBILE APP (MANAGER)                     │
│  1. Create New Count                                        │
│  2. Scan products → Build list incrementally                │
│  3. Assign counters (optional - can defer)                  │
│  4. Save as Draft OR Activate immediately                   │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ POST /countings (status: draft)
                 │ POST /countings/{id}/add-product (incremental)
                 │ PATCH /countings/{id} (update)
                 │ POST /countings/{id}/activate
                 ↓
┌─────────────────────────────────────────────────────────────┐
│                    API SERVER                               │
│  - Creates InventoryCounting (status: draft)               │
│  - Stores creator_id, created_on_mobile: true              │
│  - Validates manager role permission                        │
│  - Tracks product additions with timestamps                 │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ Sync to web
                 ↓
┌─────────────────────────────────────────────────────────────┐
│                    WEB APPLICATION                          │
│  - Shows mobile-created counts with 📱 badge               │
│  - Allows editing (add/remove products, change config)      │
│  - Shows "Created by John Doe (Mobile)" metadata           │
│  - Full wizard available for finalization                  │
└─────────────────────────────────────────────────────────────┘
```

### State Machine

```
┌─────────┐  Mobile: Save Draft   ┌─────────────┐
│  (none) │───────────────────────>│    DRAFT    │
└─────────┘                        │             │
                                   │ Mobile/Web  │
                Mobile: Activate   │ can edit    │
            ┌──────────────────────┤             │
            │                      └─────┬───────┘
            │                            │
            │                            │ Web: Activate
            │                            │ Mobile: Finalize
            ↓                            ↓
  ┌──────────────────┐         ┌──────────────────┐
  │  SCHEDULED       │<────────│   ACTIVATED      │
  │  (Assigned)      │         │  (Counting Live) │
  └──────────────────┘         └──────────────────┘
            │                            │
            │ Counters complete          │
            ↓                            ↓
  ┌──────────────────────────────────────────────┐
  │           PENDING_REVIEW                     │
  │  (Manager reviews in Web or Mobile)          │
  └──────────────────┬───────────────────────────┘
                     │ Approve
                     ↓
            ┌────────────────┐
            │   FINALIZED    │
            │ (Stock Adjusted)│
            └────────────────┘
```

---

## API Changes Required

### 1. Create Count (Mobile)

**Endpoint:** `POST /api/v1/inventory/countings`

**Request:**
```json
{
  "title": "Brake Pads Spot Check",
  "scope_type": "product",
  "created_on_mobile": true,
  "status": "draft",
  "scope_filters": {
    "product_ids": []  // Empty initially
  },
  "allow_unexpected_items": true,
  "requires_count_2": false,
  "execution_mode": "sequential"
}
```

**Response:**
```json
{
  "data": {
    "id": 123,
    "uuid": "abc-def",
    "status": "draft",
    "created_by": {
      "id": 456,
      "name": "John Doe"
    },
    "created_at": "2025-12-16T10:00:00Z",
    "product_count": 0
  }
}
```

**Authorization:** Requires `manager` or `admin` role

---

### 2. Add Product to Draft Count

**Endpoint:** `POST /api/v1/inventory/countings/{id}/add-product`

**Request:**
```json
{
  "barcode": "1234567890123",
  "location_id": 42  // Optional - detected from user's current location
}
```

**Response:**
```json
{
  "data": {
    "counting_item_id": 789,
    "product": {
      "id": 100,
      "name": "Brake Pad Set Front",
      "sku": "BP-FR-001"
    },
    "location": {
      "id": 42,
      "name": "Warehouse A - Shelf 3B"
    },
    "added_at": "2025-12-16T10:05:00Z"
  }
}
```

**Business Logic:**
- Lookup product by barcode
- If not found → return 404 with suggestion to add product first
- If already in count → return 409 (duplicate)
- Create InventoryCountingItem with `theoretical_qty` = null (will be calculated when activated)
- If location not specified → use product's default location

---

### 3. Remove Product from Draft Count

**Endpoint:** `DELETE /api/v1/inventory/countings/{id}/products/{productId}`

**Response:** `204 No Content`

---

### 4. Update Draft Count

**Endpoint:** `PATCH /api/v1/inventory/countings/{id}`

**Request:**
```json
{
  "title": "Updated Title",
  "instructions": "Count all items in Section A",
  "scheduled_start": "2025-12-17T08:00:00Z",
  "scheduled_end": "2025-12-17T17:00:00Z",
  "count_1_user_id": "uuid-user-1",
  "count_2_user_id": "uuid-user-2",
  "requires_count_2": true
}
```

**Authorization:** Only creator or admins can edit draft

---

### 5. Activate Draft Count

**Endpoint:** `POST /api/v1/inventory/countings/{id}/activate`

**Business Logic:**
1. Validate at least 1 product in count
2. Validate at least 1 counter assigned
3. Calculate theoretical quantities from current stock levels
4. Set status to `scheduled` or `count_1_in_progress`
5. Send notifications to assigned counters

**Request:**
```json
{
  "activate_immediately": true  // If false → status: scheduled
}
```

**Response:**
```json
{
  "data": {
    "id": 123,
    "status": "count_1_in_progress",
    "items_count": 15,
    "assigned_counters": [
      { "id": "uuid", "name": "Staff Member 1" }
    ]
  }
}
```

---

### 6. Get My Draft Counts

**Endpoint:** `GET /api/v1/inventory/countings/my-drafts`

**Response:**
```json
{
  "data": [
    {
      "id": 123,
      "uuid": "abc-def",
      "title": "Brake Pads Spot Check",
      "status": "draft",
      "product_count": 8,
      "created_at": "2025-12-16T10:00:00Z",
      "last_modified_at": "2025-12-16T14:30:00Z"
    }
  ]
}
```

---

## Mobile UI Design

### New Screens

#### 1. Counts Overview Screen (Redesigned)

```
╔═════════════════════════════════════════════╗
║  🔢 Counting Operations                    ║
╠═════════════════════════════════════════════╣
║  Tabs: [ My Tasks ] [ My Drafts ] [ All ]  ║
║                                             ║
║  ─── My Drafts ───────────────────────────  ║
║                                             ║
║  📝 Brake Pads Spot Check                  ║
║  🟡 Draft - 8 products                     ║
║  Last edited: 2 hours ago                  ║
║  [Continue Editing]                        ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  📝 High-Value Items Audit                 ║
║  🟡 Draft - 35 products                    ║
║  Last edited: Yesterday                    ║
║  [Continue Editing]                        ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  [➕ New Count]                            ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

#### 2. Create Count Screen

```
╔═════════════════════════════════════════════╗
║  ← New Count                               ║
╠═════════════════════════════════════════════╣
║                                             ║
║  Title (optional):                         ║
║  ┌───────────────────────────────────────┐ ║
║  │ Spot Check - Section A                │ ║
║  └───────────────────────────────────────┘ ║
║                                             ║
║  Instructions (optional):                  ║
║  ┌───────────────────────────────────────┐ ║
║  │ Count all brake-related products      │ ║
║  └───────────────────────────────────────┘ ║
║                                             ║
║  Counting Mode:                            ║
║  ⚫ Single Count (quick spot check)        ║
║  ○ Dual Count (important items)           ║
║                                             ║
║  ─── Products (0) ─────────────────────    ║
║                                             ║
║  [📷 Scan to Add Products]                ║
║                                             ║
║  (Empty - scan products to get started)    ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║       [Save Draft]  [Start Counting →]     ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

#### 3. Draft Edit Screen

```
╔═════════════════════════════════════════════╗
║  ← Brake Pads Spot Check                   ║
║  🟡 Draft                                   ║
╠═════════════════════════════════════════════╣
║                                             ║
║  📦 Products (8)         [📷 Add More]     ║
║                                             ║
║  Brake Pad Set Front                       ║
║  SKU: BP-FR-001 | WH-A-3B                  ║
║  Added 2 hours ago                    [×]  ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  Brake Pad Set Rear                        ║
║  SKU: BP-RR-001 | WH-A-3C                  ║
║  Added 2 hours ago                    [×]  ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  ... 6 more products ...                   ║
║                                             ║
║  ═════════════════════════════════════════  ║
║                                             ║
║  👥 Assigned Counters                      ║
║  John Smith (Primary)              [Edit]  ║
║  Not assigned (Secondary)          [Add]   ║
║                                             ║
║  ═════════════════════════════════════════  ║
║                                             ║
║  ⚙️ Settings                        [Edit]  ║
║  Mode: Single Count                        ║
║  Allow unexpected items: Yes               ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║       [Save Changes]  [Activate Count →]   ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

#### 4. Scan to Add Screen

```
╔═════════════════════════════════════════════╗
║  Scan Products                        [ × ] ║
╠═════════════════════════════════════════════╣
║                                             ║
║  ┌─────────────────────────────────────┐   ║
║  │                                     │   ║
║  │         [CAMERA VIEW]               │   ║
║  │                                     │   ║
║  │     ┌─────────────────┐             │   ║
║  │     │  Scan Frame     │             │   ║
║  │     └─────────────────┘             │   ║
║  │                                     │   ║
║  │  Position barcode in frame          │   ║
║  │                                     │   ║
║  └─────────────────────────────────────┘   ║
║                                             ║
║  Recently Added (3):                       ║
║  ✓ Brake Pad Set Front                     ║
║  ✓ Oil Filter Standard                     ║
║  ✓ Air Filter Premium                      ║
║                                             ║
║  [⌨️ Enter Barcode Manually]              ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

#### 5. Assign Counter Screen

```
╔═════════════════════════════════════════════╗
║  ← Assign Counter                          ║
╠═════════════════════════════════════════════╣
║                                             ║
║  🔍 [_____________] 🔍                     ║
║      Search users...                       ║
║                                             ║
║  ─── Warehouse Staff ─────────────────────  ║
║                                             ║
║  👤 John Smith                             ║
║     Warehouse Manager                      ║
║     Last active: 5 min ago                 ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  👤 Sarah Johnson                          ║
║     Inventory Clerk                        ║
║     Last active: 1 hour ago                ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  👤 Mike Davis                             ║
║     Warehouse Associate                    ║
║     Last active: Today                     ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

## Database Schema Changes

### Add Columns to `inventory_countings`

```sql
ALTER TABLE inventory_countings
ADD COLUMN created_on_mobile BOOLEAN DEFAULT FALSE,
ADD COLUMN created_by_user_id UUID REFERENCES users(id),
ADD COLUMN last_modified_at TIMESTAMP,
ADD COLUMN last_modified_by_user_id UUID REFERENCES users(id);

-- Index for draft queries
CREATE INDEX idx_countings_status_creator
ON inventory_countings(status, created_by_user_id)
WHERE status = 'draft';
```

### Add Tracking Table (Optional)

```sql
CREATE TABLE inventory_counting_product_additions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  counting_id UUID REFERENCES inventory_countings(id) ON DELETE CASCADE,
  product_id UUID REFERENCES products(id),
  added_by_user_id UUID REFERENCES users(id),
  added_at TIMESTAMP DEFAULT now(),
  barcode_scanned TEXT,
  location_id UUID REFERENCES locations(id)
);

-- Useful for audit: "Who added this product and when?"
```

---

## Mobile State Management

### Zustand Store Extension

```typescript
// src/features/counting/store/draftCountingStore.ts
interface DraftCounting {
  id: number | null  // null = not yet saved to server
  localId: string    // UUID for offline identification
  title: string
  instructions: string
  productIds: string[]
  assignedCounters: {
    count_1_user_id?: string
    count_2_user_id?: string
  }
  settings: {
    requiresCount2: boolean
    allowUnexpectedItems: boolean
  }
  status: 'draft' | 'syncing' | 'synced'
  createdAt: string
  lastModifiedAt: string
}

interface DraftCountingState {
  drafts: DraftCounting[]
  activeDraft: DraftCounting | null

  createDraft: (title: string) => void
  addProduct: (draftId: string, productId: string) => void
  removeProduct: (draftId: string, productId: string) => void
  updateDraft: (draftId: string, updates: Partial<DraftCounting>) => void
  syncDraft: (draftId: string) => Promise<void>
  activateDraft: (draftId: string) => Promise<void>
  deleteDraft: (draftId: string) => void
}
```

---

## Implementation Roadmap

### Phase 1: Backend API (5 days)

**Day 1-2: Database & API Endpoints**
- Add columns to `inventory_countings` table
- Implement POST `/countings` (create draft)
- Implement POST `/countings/{id}/add-product`
- Implement DELETE `/countings/{id}/products/{productId}`
- Add role-based authorization middleware

**Day 3: Draft Management**
- Implement PATCH `/countings/{id}` (update draft)
- Implement GET `/countings/my-drafts`
- Implement POST `/countings/{id}/activate`
- Add validation logic (min 1 product, min 1 counter)

**Day 4: Testing**
- PHPUnit tests for all new endpoints
- Test role permissions (manager only)
- Test draft → active transition
- Test concurrent editing scenarios

**Day 5: Integration**
- Update web API client types
- Test web can see mobile-created counts
- Verify web can edit mobile drafts

**Verification:** `php artisan test --filter=DraftCounting`

---

### Phase 2: Mobile UI (7 days)

**Day 1-2: Navigation & State**
- Add "My Drafts" tab to Counts screen
- Create `draftCountingStore.ts` Zustand store
- Add API client functions for draft operations
- Create React Query hooks (`useDraftCountings`, `useCreateDraft`, etc.)

**Day 3-4: Create Count Flow**
- Build "New Count" screen (title, instructions, settings)
- Build "Scan to Add" screen (reuse existing barcode scanner)
- Implement product addition with confirmation feedback
- Add product list display with remove option

**Day 5: Draft Management**
- Build "Draft Edit" screen
- Implement counter assignment (user selector)
- Add settings editor (dual count toggle, etc.)
- Implement save draft functionality

**Day 6: Activation**
- Build activation confirmation dialog
- Implement "Activate Count" flow
- Add validation (products, counters)
- Show success with navigation to active count

**Day 7: Polish & Testing**
- Empty states (no drafts, no products)
- Loading states during sync
- Error handling (network failures)
- Offline support (queue draft creations)

**Verification:** `npm run typecheck && expo start`

---

### Phase 3: Web Integration (3 days)

**Day 1: List View**
- Add 📱 badge for mobile-created counts
- Add "Created by X (Mobile)" metadata display
- Add filter: "Mobile-initiated" toggle
- Sort by creation date (most recent first)

**Day 2: Detail View**
- Show creation source in detail page
- Show product addition timeline (audit log)
- Allow editing mobile drafts from web
- Prevent conflicts with optimistic locking

**Day 3: Testing**
- E2E test: Mobile creates → Web sees
- E2E test: Mobile draft → Web edits → Mobile syncs
- Test concurrent editing scenarios
- Test activation from both platforms

**Verification:** Web UI shows mobile counts correctly

---

### Phase 4: Sync & Offline (3 days)

**Day 1: Offline Draft Creation**
- Store drafts locally if offline
- Queue sync when online
- Handle server-generated ID merging

**Day 2: Background Sync**
- Extend `useBackgroundSync` for drafts
- Sync draft changes periodically
- Conflict resolution (last-write-wins with warnings)

**Day 3: Edge Cases**
- Draft deleted on server while editing on mobile
- Product deleted while in draft
- User reassigned role (loses manager access)
- Network timeout during activation

**Verification:** Test all offline→online scenarios

---

### Phase 5: Polish & Launch (2 days)

**Day 1: UX Polish**
- Add haptic feedback on product scan
- Add success animations
- Add undo option for product removal
- Add "Quick Start" tutorial for new users

**Day 2: Documentation & Deploy**
- Update user documentation
- Create demo video
- Deploy to staging
- User acceptance testing
- Deploy to production

---

## Complexity Assessment

### Easy (1-2 days each)
- ✅ Backend API endpoints (REST CRUD)
- ✅ Database schema changes
- ✅ Mobile Zustand store
- ✅ Barcode scanning integration (already exists)

### Medium (3-5 days each)
- ⚠️ Mobile UI screens (5 new screens)
- ⚠️ User selector on mobile
- ⚠️ Web integration (badges, filters)
- ⚠️ Draft sync logic

### Hard (5-7 days each)
- ⚠️ Offline draft creation with conflict resolution
- ⚠️ Bidirectional sync (web ↔ mobile)
- ⚠️ Concurrent editing prevention

**Total Estimated Effort:** 20 days (4 weeks)

**Recommended Team:**
- 1 Backend Developer (API + Database)
- 1 Mobile Developer (React Native UI)
- 1 Full-Stack Developer (Web integration + Sync logic)

---

## Risk Analysis

### Technical Risks

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| **Conflict Resolution** | High | High | Implement optimistic locking with version numbers |
| **Offline Sync Complexity** | Medium | High | Start with simple last-write-wins, enhance later |
| **Performance (Large Drafts)** | Low | Medium | Paginate product lists, lazy load |
| **Role Permission Edge Cases** | Medium | Low | Comprehensive test coverage |

### Business Risks

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| **Low Adoption** | Low | High | User training + demo video |
| **Workflow Confusion** | Medium | Medium | Clear UI labels, tooltips |
| **Audit Compliance** | Low | High | Full audit trail in DB |

---

## Success Metrics

### KPIs (After 1 Month)

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Mobile-initiated counts** | >30% of all counts | Count `created_on_mobile = true` |
| **Draft→Active conversion** | >80% | Drafts activated / Drafts created |
| **Time to create count** | <2 min avg | Track from create to activate |
| **Manager satisfaction** | >4.5/5 | Post-feature survey |

### Success Criteria

- ✅ At least 5 managers actively using mobile initiation
- ✅ Zero data loss incidents (offline→online sync)
- ✅ <1% error rate on product additions
- ✅ Web UI shows mobile counts seamlessly

---

## Future Enhancements (Post-MVP)

### Phase 2 Features (If Successful)

1. **Smart Suggestions**
   - AI suggests products for cycle counting
   - Based on: last count date, high variance history, high value

2. **Location Intelligence**
   - Use phone GPS/Bluetooth to detect location
   - Auto-assign location to scanned products
   - Show "You're in Section A-3B" hint

3. **Voice Notes**
   - Record voice note per product
   - "Damaged packaging, set aside 2 units"
   - Auto-transcribe for reports

4. **Photo Attachments**
   - Take photo of shelf before counting
   - Attach to counting operation for audit

5. **Batch Operations**
   - "Add all products in Section A"
   - "Add all products from Supplier X"
   - "Add all low-stock items"

6. **Collaborative Drafts**
   - Multiple managers build same draft
   - Real-time updates (WebSocket)
   - "John added 5 products while you were scanning"

7. **Templates**
   - Save draft as template
   - "Monthly High-Value Audit" template
   - 1-click create from template

---

## Technical Dependencies

### Required Before Starting

- ✅ Backend: User role system (exists)
- ✅ Mobile: Barcode scanner (exists)
- ✅ Mobile: Offline storage (exists)
- ✅ Mobile: Background sync (just implemented!)
- ⚠️ Backend: Optimistic locking mechanism (need to add)

### Nice to Have

- User location tracking (for auto-location assignment)
- Push notifications (alert counter when assigned)
- WebSocket for real-time updates

---

## Open Questions

1. **Draft Expiration:** Should drafts auto-delete after X days of inactivity?
   - Recommendation: 30 days with warning at 25 days

2. **Max Products per Draft:** Any limit?
   - Recommendation: 500 products (performance threshold)

3. **Concurrent Editing:** Allow web and mobile to edit same draft?
   - Recommendation: Last-write-wins with conflict warning

4. **Assignment:** Can manager assign themselves?
   - Recommendation: Yes, but warn if creator = counter

5. **Activation:** Require all fields (counters, deadline)?
   - Recommendation: Only require: ≥1 product, ≥1 counter

---

## Conclusion

**Complexity Rating:** ⭐⭐⭐ (Medium)

**Recommended Approach:**
1. Start with MVP (Phase 1-3: Backend + Mobile + Web)
2. Deploy to pilot group (5 managers)
3. Gather feedback for 2 weeks
4. Iterate on UX issues
5. Add offline sync (Phase 4)
6. Full rollout

**Total Timeline:** 4 weeks for MVP, +2 weeks for polish and pilot

**Team Size:** 3 developers (Backend, Mobile, Full-Stack)

**Risk Level:** Medium (mainly sync complexity)

**Business Value:** High (immediate ROI on manager time savings)

---

*Document Version: 1.0*
*Last Updated: 2025-12-16*
*Status: Ready for Review & Approval*
