# Inventory Counting Feature Improvement Plan

> **Status:** Planning Phase
> **Created:** 2025-12-16
> **Last Updated:** 2025-12-16

---

## Executive Summary

This document outlines the comprehensive improvement plan for the Inventory Counting feature, addressing critical UX issues, missing product selection functionality, and planning for mobile application integration with offline capabilities.

---

## Current State Analysis

### What Works
- ✅ Multi-step wizard flow (Scope → Configuration → Assignment → Review)
- ✅ Blind counting architecture (counters don't see theoretical quantities)
- ✅ Dual/triple count support with reconciliation logic
- ✅ Backend validation and domain logic complete
- ✅ Hash chain ready for audit trail
- ✅ Translation structure in place (EN/FR)

### Critical Issues Identified

#### 1. **Translation Gaps**
**Problem:** Several common UI elements reference missing translation keys:
- `common.next` → Not defined in `common.json`
- `common.previous` → Not defined in `common.json`
- `common.viewAll` → Exists but inconsistently used
- `common.back` → Exists but needs verification

**Impact:** Breaks i18n in production, shows raw keys to users

**Root Cause:** Translation keys added to components without updating translation files

---

#### 2. **User Selection UX Failure**
**Current Implementation:**
```tsx
<input
  type="number"
  value={data.count_1_user_id || ''}
  onChange={(e) => {
    const value = parseInt(e.target.value, 10)
    if (value) {
      onChange({ count_1_user_id: value })
    }
  }}
  placeholder={t('counting.create.selectUserPlaceholder')}
/>
```

**Problems:**
- Users must know/enter numeric user IDs
- No search capability
- No display of user names, roles, or availability
- Prone to errors (wrong ID, non-existent user)
- Terrible UX for managers assigning work

**Expected Behavior:**
- Searchable dropdown/combobox
- Display: Name, Email, Role
- Filter by active status
- Keyboard navigation
- Recent selections prioritized

---

#### 3. **Missing Product Selection**
**The Critical Gap:**

When user selects scope type "Product" or "Product + Location", the wizard immediately moves to Configuration step WITHOUT letting them choose WHICH products to count.

**Current Flow:**
```
Scope Selection (Product) → Configuration → Assignment → Review → Create
                ↓
        (No product picker!)
```

**Expected Flow:**
```
Scope Selection (Product)
    ↓
Product Selector (NEW STEP)
    - Search by name, SKU, barcode, QR code
    - Multi-select with cart UI
    - Show current stock levels as preview
    - Bulk add by category/supplier
    ↓
Configuration → Assignment → Review → Create
```

**Backend Support:**
The backend ALREADY supports this via `scope_filters.product_ids`:

```php
// CreateCountingRequest.php lines 34-35
'scope_filters.product_ids' => ['sometimes', 'array'],
'scope_filters.product_ids.*' => ['string', 'exists:products,id'],
```

But the frontend never populates this field!

---

#### 4. **No Location Granularity for Product + Location**
Similar to products, when user selects "Product + Location" scope, they should:
1. Select specific products (multi-select)
2. Select ONE specific location (single-select)

Currently, only `scope_filters.location_id` is sent, but no UI to set it.

---

## Improvement Plan

### Phase 1: Critical Fixes (Must-Have Before Production)

#### 1.1 Fix Translation Gaps
**Files to Update:**
- `apps/web/src/locales/en/common.json`
- `apps/web/src/locales/fr/common.json`

**Keys to Add:**
```json
// EN
{
  "next": "Next",
  "previous": "Previous",
  "creating": "Creating..."
}

// FR
{
  "next": "Suivant",
  "previous": "Précédent",
  "creating": "Création..."
}
```

**Verification:**
Run through entire counting wizard in both languages, verify no `common.` keys show raw.

---

#### 1.2 Replace User ID Inputs with Searchable Selectors

**New Component: `UserSelector.tsx`**

```tsx
interface UserSelectorProps {
  value: string | null
  onChange: (userId: string) => void
  label: string
  required?: boolean
  excludeUserIds?: string[] // Don't show already-assigned users
}

export function UserSelector({
  value,
  onChange,
  label,
  required,
  excludeUserIds = []
}: UserSelectorProps) {
  const { data: users, isLoading } = useUsers({ status: 'active' })

  const filteredUsers = users?.filter(
    u => !excludeUserIds.includes(u.id)
  )

  return (
    <Combobox value={value} onChange={onChange}>
      <ComboboxLabel>{label}</ComboboxLabel>
      <ComboboxInput
        displayValue={(userId: string) =>
          users?.find(u => u.id === userId)?.name ?? ''
        }
      />
      <ComboboxOptions>
        {filteredUsers?.map(user => (
          <ComboboxOption key={user.id} value={user.id}>
            <div>
              <div className="font-medium">{user.name}</div>
              <div className="text-sm text-gray-500">{user.email}</div>
            </div>
          </ComboboxOption>
        ))}
      </ComboboxOptions>
    </Combobox>
  )
}
```

**API Endpoint Required:**
```
GET /api/v1/users?status=active&role=counter
```

Returns:
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "warehouse_staff",
      "avatar_url": "https://..."
    }
  ]
}
```

**Update `AssignmentStep` Component:**
Replace all `<input type="number">` with `<UserSelector>`.

---

#### 1.3 Add Product Selection Step

**New Component: `ProductSelectorStep.tsx`**

**Features:**
- **Search Modes:**
  - Text search (name, SKU)
  - Barcode scanner integration (Web Barcode API)
  - QR code scanner (WebRTC camera)
  - CSV bulk import (paste SKUs)

- **Display:**
  - Product card with image, name, SKU, current stock
  - Selected products shown in cart-style list
  - Quick remove from selection
  - Show count: "12 products selected"

- **Filtering:**
  - By category
  - By supplier
  - Low stock only
  - Out of stock only

**Implementation:**

```tsx
function ProductSelectorStep({
  selectedProductIds,
  onChange
}: ProductSelectorStepProps) {
  const [search, setSearch] = useState('')
  const [scanMode, setScanMode] = useState<'text' | 'barcode' | 'qr'>('text')

  const { data: products } = useProducts({
    search,
    perPage: 50
  })

  const handleToggleProduct = (productId: string) => {
    if (selectedProductIds.includes(productId)) {
      onChange(selectedProductIds.filter(id => id !== productId))
    } else {
      onChange([...selectedProductIds, productId])
    }
  }

  const handleBarcodeDetected = (barcode: string) => {
    // Look up product by barcode
    // Auto-add to selection
  }

  return (
    <div>
      <h2>Select Products to Count</h2>

      {/* Search Bar with Mode Switcher */}
      <div className="flex gap-2">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder={
            scanMode === 'text'
              ? 'Search by name or SKU...'
              : 'Scan barcode or QR code'
          }
        />
        <Button onClick={() => setScanMode('barcode')}>
          <BarcodeIcon />
        </Button>
        <Button onClick={() => setScanMode('qr')}>
          <QrCodeIcon />
        </Button>
      </div>

      {/* Selected Products Cart */}
      <div className="bg-blue-50 p-4 rounded-lg mb-4">
        <div className="font-medium">
          {selectedProductIds.length} products selected
        </div>
        <div className="flex flex-wrap gap-2 mt-2">
          {selectedProductIds.map(id => (
            <ProductChip
              key={id}
              productId={id}
              onRemove={() => handleToggleProduct(id)}
            />
          ))}
        </div>
      </div>

      {/* Product Grid */}
      <div className="grid grid-cols-2 gap-4">
        {products?.map(product => (
          <ProductCard
            key={product.id}
            product={product}
            selected={selectedProductIds.includes(product.id)}
            onToggle={handleToggleProduct}
          />
        ))}
      </div>
    </div>
  )
}
```

**Barcode Scanning:**
Use `@ericblade/quagga2` for barcode detection:
```tsx
import Quagga from '@ericblade/quagga2'

const startBarcodeScanner = (onDetected: (code: string) => void) => {
  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: document.querySelector('#scanner-container')
    },
    decoder: {
      readers: ['ean_reader', 'code_128_reader', 'code_39_reader']
    }
  }, (err) => {
    if (!err) {
      Quagga.start()
      Quagga.onDetected((result) => {
        onDetected(result.codeResult.code)
      })
    }
  })
}
```

---

#### 1.4 Update Wizard Steps

**Add Step Between Scope and Configuration:**

```tsx
const STEPS = [
  'scope',
  'selection', // NEW: Product/Location selection
  'configuration',
  'assignment',
  'review'
] as const
```

**Conditional Rendering:**
- Show "selection" step ONLY when:
  - `scope_type === 'product'` → Show product selector
  - `scope_type === 'product_location'` → Show product + location selector
  - `scope_type === 'location'` → Show location multi-selector
  - `scope_type === 'category'` → Show category selector
  - `scope_type === 'warehouse'` → Show warehouse selector
  - `scope_type === 'full_inventory'` → Skip selection step

**Updated Flow:**
```
Full Inventory:  Scope → Configuration → Assignment → Review
Product:         Scope → Product Selection → Configuration → Assignment → Review
Location:        Scope → Location Selection → Configuration → Assignment → Review
Category:        Scope → Category Selection → Configuration → Assignment → Review
```

---

### Phase 2: Mobile Application Integration

#### 2.1 Mobile Counting App Architecture

**Technology Stack:**
- React Native + Expo (per CLAUDE.md)
- Offline-first with SQLite local database
- Sync via background tasks
- Barcode scanner using `expo-barcode-scanner`

**Data Synchronization Strategy:**

```
┌─────────────────────────────────────────────────────────┐
│                    WEB APPLICATION                      │
│  (Counting Creation & Review)                           │
└────────────────┬────────────────────────────────────────┘
                 │
                 │ 1. Create counting operation
                 │    POST /api/v1/inventory/countings
                 ↓
┌─────────────────────────────────────────────────────────┐
│                    API SERVER                           │
│  - Creates InventoryCounting record                     │
│  - Generates InventoryCountingItems                     │
│  - Assigns counters                                     │
│  - Status: "scheduled" → "count_1_in_progress"         │
└────────────────┬────────────────────────────────────────┘
                 │
                 │ 2. Mobile app syncs assigned tasks
                 │    GET /api/v1/inventory/countings/my-tasks
                 ↓
┌─────────────────────────────────────────────────────────┐
│               MOBILE APPLICATION                        │
│  - Downloads counting assignments                       │
│  - Downloads product metadata ONLY:                     │
│    * product.id, name, sku, barcode                    │
│    * location.id, name, code                           │
│    * counting.instructions                             │
│  - NO theoretical quantities (blind counting!)         │
│  - Stores in local SQLite                              │
└────────────────┬────────────────────────────────────────┘
                 │
                 │ 3. Counter performs offline counting
                 │    (Scans barcodes, enters quantities)
                 │
                 │ 4. Periodic sync of counted items
                 │    PATCH /api/v1/inventory/countings/{id}/items/{itemId}
                 │    { "count_1_qty": 42, "count_1_at": "2025-12-16T14:30:00Z" }
                 ↓
┌─────────────────────────────────────────────────────────┐
│                    API SERVER                           │
│  - Updates InventoryCountingItem.count_1_qty           │
│  - Recalculates progress                               │
│  - Notifies manager via websocket (optional)           │
└────────────────┬────────────────────────────────────────┘
                 │
                 │ 5. Manager reviews on web
                 │    GET /api/v1/inventory/countings/{id}
                 ↓
┌─────────────────────────────────────────────────────────┐
│                    WEB APPLICATION                      │
│  - Shows reconciliation table                          │
│  - Flags discrepancies                                 │
│  - Triggers 3rd count if needed                        │
│  - Finalizes → creates stock adjustments               │
└─────────────────────────────────────────────────────────┘
```

---

#### 2.2 Mobile App Data Requirements

**Minimal Payload for Offline Counting:**

For a counting operation with 500 products:

```json
{
  "counting_id": "uuid",
  "my_count_number": 1,
  "instructions": "Count all items in Section A...",
  "deadline": "2025-12-20T17:00:00Z",
  "items": [
    {
      "item_id": "uuid",
      "product": {
        "id": "uuid",
        "name": "Brake Pad Set Front",
        "sku": "BP-FR-001",
        "barcode": "1234567890123"
      },
      "location": {
        "id": "uuid",
        "name": "Warehouse A - Shelf 3B",
        "code": "WH-A-3B"
      }
      // NO theoretical_qty!
      // NO count_2_qty, count_3_qty!
    },
    // ... 499 more items
  ]
}
```

**Estimated Size:**
- 500 items × ~200 bytes = 100 KB
- Even 5,000 items = 1 MB (perfectly fine for mobile)

**What to EXCLUDE (Heavy Data):**
- ❌ Product images
- ❌ Full product descriptions
- ❌ Historical stock movements
- ❌ Pricing information
- ❌ Supplier/customer data
- ❌ Other users' count results

---

#### 2.3 Offline Counting UX Flow (Mobile)

**Screen 1: My Tasks**
```
╔═════════════════════════════════════════════╗
║  🔢 My Counting Tasks                      ║
╠═════════════════════════════════════════════╣
║                                             ║
║  📦 Warehouse A Full Count                 ║
║  🟡 Count 1 In Progress                    ║
║  Progress: 42 / 500 items (8%)             ║
║  Deadline: Dec 20, 5:00 PM (3 days left)   ║
║  [Continue Counting]                       ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  📦 Brake Parts - Section B                ║
║  🟢 Ready to Start                         ║
║  Progress: 0 / 85 items (0%)               ║
║  Deadline: Dec 18, 12:00 PM (Tomorrow)     ║
║  [Start Counting]                          ║
║                                             ║
╚═════════════════════════════════════════════╝
```

**Screen 2: Counting Interface**
```
╔═════════════════════════════════════════════╗
║  Warehouse A Full Count                    ║
║  📊 42 / 500 counted (8%)                  ║
╠═════════════════════════════════════════════╣
║                                             ║
║  🔍 [________________] 📷 Scan              ║
║      Search or scan barcode                ║
║                                             ║
║  ─── Next Items ──────────────────────────  ║
║                                             ║
║  Brake Pad Set Front                       ║
║  SKU: BP-FR-001 | WH-A-3B                  ║
║  Barcode: 1234567890123                    ║
║  Counted: ✅ 12 units (5 min ago)          ║
║                                             ║
║  ─────────────────────────────────────────  ║
║                                             ║
║  Oil Filter - Standard                     ║
║  SKU: OF-STD-002 | WH-A-3C                 ║
║  Barcode: 9876543210987                    ║
║  [Count This Item →]                       ║
║                                             ║
╚═════════════════════════════════════════════╝
```

**Screen 3: Item Counting**
```
╔═════════════════════════════════════════════╗
║  Oil Filter - Standard                     ║
║  SKU: OF-STD-002                           ║
║  Location: WH-A-3C                         ║
╠═════════════════════════════════════════════╣
║                                             ║
║  Quantity Counted:                         ║
║                                             ║
║   ┌─────────────────────┐                  ║
║   │       [  24  ]      │ (Large input)    ║
║   └─────────────────────┘                  ║
║                                             ║
║   [  1  ] [  5  ] [ 10  ] [ 50  ]         ║
║      (Quick increment buttons)             ║
║                                             ║
║  Notes (optional):                         ║
║  ┌───────────────────────────────────────┐ ║
║  │ Damaged box, 2 units set aside        │ ║
║  └───────────────────────────────────────┘ ║
║                                             ║
║            [Cancel]  [Save Count]          ║
║                                             ║
╚═════════════════════════════════════════════╝
```

---

#### 2.4 Sync Strategy

**Optimistic UI Updates:**
- Count saved to SQLite immediately
- Background job attempts API sync every 30 seconds
- Show sync status indicator (🟢 synced | 🟡 pending | 🔴 offline)

**Conflict Resolution:**
- If item already counted by another user's count (parallel mode), accept both
- If same user re-counts (sequential mode), overwrite previous count
- Server validates and returns conflicts, mobile shows alert

**Background Sync Implementation:**
```tsx
// Mobile: useCountingSync.ts
import * as BackgroundFetch from 'expo-background-fetch'
import * as TaskManager from 'expo-task-manager'

const BACKGROUND_SYNC_TASK = 'COUNTING_SYNC'

TaskManager.defineTask(BACKGROUND_SYNC_TASK, async () => {
  const pendingCounts = await db.getPendingCounts()

  for (const count of pendingCounts) {
    try {
      await api.updateCountingItem(count.countingId, count.itemId, {
        [`count_${count.countNumber}_qty`]: count.quantity,
        [`count_${count.countNumber}_at`]: count.countedAt,
        notes: count.notes
      })
      await db.markCountSynced(count.id)
    } catch (error) {
      // Will retry on next run
      console.error('Sync failed:', error)
    }
  }

  return BackgroundFetch.BackgroundFetchResult.NewData
})

BackgroundFetch.registerTaskAsync(BACKGROUND_SYNC_TASK, {
  minimumInterval: 30, // seconds
  stopOnTerminate: false,
  startOnBoot: true
})
```

---

#### 2.5 Barcode Scanning (Mobile)

**Using Expo Barcode Scanner:**

```tsx
import { BarCodeScanner } from 'expo-barcode-scanner'

function BarcodeScannerScreen() {
  const [hasPermission, setHasPermission] = useState<boolean | null>(null)

  useEffect(() => {
    (async () => {
      const { status } = await BarCodeScanner.requestPermissionsAsync()
      setHasPermission(status === 'granted')
    })()
  }, [])

  const handleBarCodeScanned = ({ type, data }: BarCodeEvent) => {
    // Look up product in local SQLite by barcode
    const product = await db.getProductByBarcode(data)

    if (product) {
      // Navigate to counting screen for this product
      navigation.navigate('CountItem', { itemId: product.counting_item_id })
    } else {
      // Unexpected item!
      if (counting.allow_unexpected_items) {
        // Show dialog to add new item
        showUnexpectedItemDialog(data)
      } else {
        Alert.alert('Item Not Found', `Barcode ${data} is not in this count`)
      }
    }
  }

  return (
    <BarCodeScanner
      onBarCodeScanned={handleBarCodeScanned}
      style={StyleSheet.absoluteFillObject}
    />
  )
}
```

---

### Phase 3: Advanced Features (Nice-to-Have)

#### 3.1 Voice-to-Count
- Mobile app listens for voice input: "Twelve" → auto-fills 12
- Hands-free counting for warehouse staff on ladders

#### 3.2 Photo Evidence
- Optional: Take photo of counted items
- Attach to counting item for audit trail
- Useful for discrepancy investigation

#### 3.3 Location Verification
- Use GPS or BLE beacons to verify counter is physically at location
- Prevent fraud (counting from home)

#### 3.4 Real-Time Collaboration
- WebSocket updates show other counters' progress
- Manager dashboard shows live map of counters

#### 3.5 AI Assistance
- Computer vision to count items from photo (future)
- OCR for reading handwritten stock tags

---

## Implementation Roadmap

### Sprint 1: Translation & UX Fixes (3 days)
- ✅ Day 1: Add missing translation keys (EN/FR)
- ✅ Day 2: Build `UserSelector` component + API endpoint
- ✅ Day 3: Replace user ID inputs, test full wizard

### Sprint 2: Product Selection (5 days)
- ✅ Day 1-2: Build `ProductSelectorStep` component
- ✅ Day 3: Integrate barcode scanning (web)
- ✅ Day 4: Add conditional step logic to wizard
- ✅ Day 5: Update backend validation, test end-to-end

### Sprint 3: Location/Category Selectors (3 days)
- ✅ Day 1: Build `LocationSelectorStep`
- ✅ Day 2: Build `CategorySelectorStep`
- ✅ Day 3: Wire up all scope type variations

### Sprint 4: Mobile App Foundation (1 week)
- ✅ Day 1-2: Set up React Native + Expo project
- ✅ Day 3-4: Build offline SQLite schema + sync logic
- ✅ Day 5: Implement "My Tasks" screen
- ✅ Day 6-7: Build counting interface with barcode scanner

### Sprint 5: Mobile Sync & Testing (1 week)
- ✅ Day 1-3: Background sync implementation
- ✅ Day 4-5: Offline conflict resolution
- ✅ Day 6-7: Integration testing (web + mobile)

---

## Technical Specifications

### API Endpoints to Add/Modify

#### GET /api/v1/users
**Query Params:**
- `status`: active | inactive
- `role`: optional filter by role
- `search`: text search on name/email

**Response:**
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "warehouse_staff",
      "avatar_url": "https://...",
      "last_active_at": "2025-12-16T10:00:00Z"
    }
  ]
}
```

#### GET /api/v1/products
**Already exists, but verify support for:**
- `search`: full-text search (name, SKU)
- `barcode`: exact match
- `category_id`: filter
- `low_stock`: boolean filter
- `per_page`: pagination

#### GET /api/v1/inventory/countings/my-tasks
**For mobile app:**
```json
{
  "data": [
    {
      "counting_id": "uuid",
      "status": "count_1_in_progress",
      "my_count_number": 1,
      "instructions": "...",
      "deadline": "2025-12-20T17:00:00Z",
      "progress": { "counted": 42, "total": 500, "percentage": 8.4 },
      "items": [
        {
          "item_id": "uuid",
          "product": { "id": "uuid", "name": "...", "sku": "...", "barcode": "..." },
          "location": { "id": "uuid", "name": "...", "code": "..." },
          "my_count": null, // if not yet counted
          "my_count_at": null
        }
      ]
    }
  ]
}
```

**CRITICAL:** This endpoint must NEVER return:
- `theoretical_qty`
- Other counters' results (`count_2_qty`, `count_3_qty`)
- Pricing data
- Heavy product details (descriptions, images)

#### PATCH /api/v1/inventory/countings/{id}/items/{itemId}
**Update count for single item:**
```json
{
  "count_1_qty": 42,
  "count_1_at": "2025-12-16T14:30:00Z",
  "notes": "Optional notes"
}
```

**Validation:**
- User must be assigned as counter for this count number
- Counting must be in correct status (e.g., `count_1_in_progress`)
- Prevent modifying other counters' results

---

## Data Model Changes

### None Required for Phase 1-2
All necessary columns already exist in `inventory_countings` and `inventory_counting_items` tables.

### Phase 4 (Future): Mobile-Specific Tables

```sql
-- Track sync status for mobile app
CREATE TABLE counting_item_sync_queue (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  counting_item_id UUID REFERENCES inventory_counting_items(id),
  user_id UUID REFERENCES users(id),
  count_number INTEGER CHECK (count_number IN (1, 2, 3)),
  quantity DECIMAL(10, 2),
  counted_at TIMESTAMP,
  notes TEXT,
  synced_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT now()
);

CREATE INDEX idx_sync_queue_pending
  ON counting_item_sync_queue(user_id)
  WHERE synced_at IS NULL;
```

---

## Testing Strategy

### Unit Tests
- `UserSelector` component
- `ProductSelectorStep` component
- Barcode scanning logic
- Sync queue management

### Integration Tests
- Full wizard flow with product selection
- API validation for `scope_filters`
- Mobile sync conflict resolution

### E2E Tests (Playwright)
1. **Happy Path:**
   - Create counting for 5 specific products
   - Assign 2 counters
   - Simulate mobile counting (API calls)
   - Review discrepancies
   - Finalize

2. **Edge Cases:**
   - No products selected → validation error
   - Same user assigned to multiple counts → force sequential mode
   - Offline mobile count → sync when back online

### Manual Testing Checklist
- [ ] Scan barcode on web (using phone camera)
- [ ] Scan barcode on mobile app
- [ ] Offline counting (airplane mode)
- [ ] Sync conflicts (two devices counting same item)
- [ ] French translation completeness
- [ ] RTL layout (future Arabic support)

---

## Security Considerations

### Blind Counting Enforcement
**Critical:** The `counterView` endpoint (line 137 in `InventoryCountingController.php`) must NEVER leak:
- Theoretical quantities
- Other counters' results

**Audit:**
Review lines 155-177 to verify no sensitive data in response.

### Mobile App Authentication
- JWT tokens stored in secure storage (Expo SecureStore)
- Refresh tokens for offline use
- Automatic logout after 7 days of inactivity

### Sync Data Integrity
- Hash check on sync payload to prevent tampering
- Server validates user permission before accepting count
- Log all sync attempts (success/failure) for audit

---

## Performance Optimization

### Mobile App
- Lazy load counting items (paginate 50 at a time)
- SQLite indexes on `barcode`, `sku` for fast lookup
- Compress sync payloads with gzip
- Cache product images (if added later) with TTL

### Web App
- Debounce product search (300ms)
- Virtual scrolling for large product lists (react-window)
- Memoize product cards to prevent re-renders

---

## Open Questions / Decisions Needed

1. **Product Selection Limit:**
   - Should we cap max products per counting? (e.g., 10,000)
   - What happens if user selects all 50,000 products?

2. **Mobile App Distribution:**
   - Internal TestFlight/Play Console
   - Or build APK/IPA for sideloading?

3. **Barcode Scanner Licensing:**
   - Quagga2 is LGPL (web)
   - Expo Barcode Scanner is MIT (mobile)
   - Both acceptable?

4. **Offline Duration:**
   - Max 7 days offline before forcing re-sync?
   - Or allow indefinite offline with manual sync?

5. **Voice-to-Count:**
   - Priority for Phase 3?
   - Or push to Phase 4?

---

## Success Metrics

### Before Launch
- [ ] Zero translation keys showing raw in UI
- [ ] 100% of test scenarios passing
- [ ] Mobile app tested on iOS + Android
- [ ] Offline sync works in airplane mode
- [ ] Barcode scanning accuracy > 95%

### Post-Launch (First Month)
- Average time to create counting operation: < 2 minutes
- Mobile app crash rate: < 1%
- Sync conflicts: < 5% of counts
- User satisfaction (survey): > 4.5/5

---

## Appendix A: Barcode Standards Supported

### Mobile App (Expo Barcode Scanner)
- EAN-13 (European Article Number)
- EAN-8
- UPC-A (Universal Product Code)
- UPC-E
- Code 39
- Code 93
- Code 128
- ITF (Interleaved 2 of 5)
- QR Code
- Data Matrix

### Web App (Quagga2)
- EAN-13
- EAN-8
- Code 39
- Code 128
- UPC-A
- UPC-E

---

## Appendix B: SQLite Schema (Mobile)

```sql
-- Local SQLite database schema for mobile app

CREATE TABLE countings (
  id TEXT PRIMARY KEY,
  status TEXT NOT NULL,
  my_count_number INTEGER NOT NULL,
  instructions TEXT,
  deadline TEXT,
  synced_at TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE counting_items (
  id TEXT PRIMARY KEY,
  counting_id TEXT NOT NULL,
  product_id TEXT NOT NULL,
  product_name TEXT NOT NULL,
  product_sku TEXT NOT NULL,
  product_barcode TEXT,
  location_id TEXT NOT NULL,
  location_name TEXT NOT NULL,
  location_code TEXT,
  my_count_qty REAL,
  my_count_at TEXT,
  notes TEXT,
  synced_at TEXT,
  FOREIGN KEY (counting_id) REFERENCES countings(id)
);

CREATE INDEX idx_counting_items_barcode
  ON counting_items(product_barcode);

CREATE INDEX idx_counting_items_sku
  ON counting_items(product_sku);

CREATE INDEX idx_counting_items_pending_sync
  ON counting_items(counting_id)
  WHERE my_count_qty IS NOT NULL AND synced_at IS NULL;
```

---

## Appendix C: Translation Keys Audit

### Missing Keys (MUST ADD)
```json
// common.json
{
  "next": "Next",
  "previous": "Previous",
  "creating": "Creating..."
}
```

### Existing Keys (Verify Usage)
- ✅ `common.back` → Used in CreateCountingPage.tsx:90
- ✅ `common.viewAll` → Used but needs consistent placement
- ✅ `common.yes` → Used in ReviewStep
- ✅ `common.no` → Used in ReviewStep

---

**Document Status:** Ready for Review
**Next Steps:** Stakeholder approval → Begin Sprint 1

---

*Last Updated: 2025-12-16*
*Author: Development Team*
*Reviewers: Product, Engineering, Mobile Team*
