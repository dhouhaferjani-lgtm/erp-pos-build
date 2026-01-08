# Location Context Remediation Plan

**Date:** 2025-12-27
**Status:** Planning Phase
**Priority:** High
**Scope:** Cross-Module (Company, Document, Inventory, Treasury, Identity)

---

## Executive Summary

The current location management system is **operationally implemented but contextually incomplete**. While locations work well for inventory operations (stock levels, movements, counting), there are critical gaps in:

1. **Context Management** - No LocationContext service (unlike CompanyContext)
2. **Permission Enforcement** - `allowed_location_ids` exists but is not validated
3. **Document Line Precision** - Lines cannot track which location they shipped from
4. **UI Availability** - Location selector not accessible in sales/document workflows
5. **Default Logic** - No backend enforcement of default location selection
6. **Audit Completeness** - Payment allocations and journal entries lack location tracking

This creates **operational ambiguity** in multi-location businesses and **blocks proper return note processing**.

---

## Current State Analysis

### What Works Well ✅

| Feature | Implementation Quality | Notes |
|---------|----------------------|-------|
| Inventory stock tracking | Excellent | Per-location stock levels with unique constraints |
| Stock movements | Excellent | Full location audit trail |
| Inventory counting | Excellent | Location-specific assignments |
| Frontend location store | Good | Auto-selects default, persists selection |
| Location model | Good | Rich metadata (address, GPS, POS settings) |
| Document header location | Acceptable | Nullable design supports central invoicing |

### Critical Gaps ❌

| Gap | Impact | Severity |
|-----|--------|----------|
| No LocationContext service | Inconsistent location handling across controllers | High |
| Document lines lack location_id | Cannot track split shipments | Critical |
| Location selector not in sales UI | Manual location assignment impossible | High |
| Permission validation missing | Users can access any location | Security Risk |
| Payment allocations lack location | Treasury reporting incomplete | Medium |
| Journal entries lack location | Cannot generate location P&L | Medium |
| No default location enforcement | Inconsistent fallback behavior | Medium |

---

## Problem Statement

### Scenario 1: Multi-Location Sales Order
**Current Behavior:**
```
Sales Order SO-001 has 3 lines:
  - Line 1: Product A, Qty 10 → Ships from Warehouse A
  - Line 2: Product B, Qty 5  → Ships from Warehouse B
  - Line 3: Product C, Qty 2  → Ships from Shop C

Problem: All lines inherit document.location_id (e.g., "Warehouse A")
Result: Stock movements show incorrect source locations for Lines 2 & 3
```

**Expected Behavior:**
```
Each line should have its own location_id:
  - Line 1: location_id = Warehouse A
  - Line 2: location_id = Warehouse B
  - Line 3: location_id = Shop C

Stock movements record actual source location per line.
```

### Scenario 2: Return Note Processing (Blocking Issue)
**Current Behavior:**
```
Customer returns items via Return Note RN-001
ReturnNoteService.confirm() tries to:
  1. Find location_id from document line → NULL (field doesn't exist)
  2. Cannot create stock movement without location
  3. Throws error: "Cannot receive stock: no location specified"

Workaround: Return always goes to document.location_id
Problem: What if user wants to receive at a different location?
```

**Expected Behavior:**
```
User creates return note:
  - Option 1: Explicitly select receive location per line
  - Option 2: Default to user's active location
  - Option 3: Default to document.location_id

Line has location_id → Stock movement succeeds
```

### Scenario 3: Sales User Cannot Set Location
**Current Behavior:**
```
User in Sales module creates invoice:
  - Location selector: Not visible in UI
  - document.location_id: Falls back to null
  - Stock reservation: Uses first available location (wrong)
  - Invoice prints: No location shown on PDF

User must switch to Inventory module to set active location,
then switch back to Sales to create document.
```

**Expected Behavior:**
```
Location selector always visible in document header:
  - Shows current active location
  - User can change before saving
  - Selection persists for session
  - Document stores explicit location
```

### Scenario 4: Permission Violation
**Current Behavior:**
```
User membership has:
  allowed_location_ids: ["warehouse-a", "shop-1"]

User views inventory at "warehouse-b":
  - No validation occurs
  - User sees unauthorized stock levels
  - Can create stock movements at any location

Database has allowed_location_ids but middleware ignores it.
```

**Expected Behavior:**
```
Middleware validates location access:
  - Request has location_id parameter
  - Check user.allowed_location_ids
  - Reject if not authorized
  - Log access attempt for audit
```

---

## Proposed Architecture

### Phase 1: Core Infrastructure (Backend)

#### 1.1 Create LocationContext Service

**File:** `app/Modules/Company/Services/LocationContext.php`

```php
/**
 * Manages the current active location for the request/session.
 *
 * Similar to CompanyContext but scoped per-operation rather than system-wide.
 */
class LocationContext
{
    private ?string $locationId = null;

    /**
     * Set active location for this request.
     *
     * Called by middleware or controller to establish location scope.
     */
    public function setLocationId(string $locationId): void;

    /**
     * Get current active location ID.
     *
     * Returns null if no location set (e.g., central invoicing scenario).
     */
    public function getLocationId(): ?string;

    /**
     * Get current active location ID or throw exception.
     *
     * Use when operation absolutely requires location context.
     */
    public function requireLocationId(): string;

    /**
     * Get current active location model or throw exception.
     */
    public function requireLocation(): Location;

    /**
     * Get default location for current company.
     *
     * Queries Location::where('company_id', ...)
     *                  ->where('is_default', true)
     *                  ->first()
     *
     * Falls back to first active location if no default.
     */
    public function getDefaultLocation(): ?Location;

    /**
     * Resolve location ID with fallback chain:
     * 1. Explicit location_id parameter (if provided)
     * 2. Current active location (from context)
     * 3. Default location for company
     * 4. null (if central invoicing or no locations exist)
     */
    public function resolveLocationId(?string $explicitLocationId = null): ?string;

    /**
     * Clear location context (for testing or session end).
     */
    public function clear(): void;
}
```

**Registration:**
```php
// app/Providers/AppServiceProvider.php
$this->app->singleton(LocationContext::class);
```

**Usage in Controllers:**
```php
public function __construct(
    private readonly LocationContext $locationContext
) {}

public function store(Request $request): JsonResponse
{
    // Resolve location with fallback chain
    $locationId = $this->locationContext->resolveLocationId(
        $request->input('location_id')
    );

    Document::create([
        'location_id' => $locationId,
        // ...
    ]);
}
```

---

#### 1.2 Create Location Permission Middleware

**File:** `app/Modules/Identity/Presentation/Middleware/ValidateLocationAccess.php`

```php
/**
 * Validates user has permission to access the requested location.
 *
 * Checks UserCompanyMembership.allowed_location_ids against request location.
 */
class ValidateLocationAccess
{
    /**
     * Handle an incoming request.
     *
     * Extracts location_id from:
     * - Route parameter: /api/v1/inventory/{location}
     * - Query parameter: ?location_id=uuid
     * - Request body: {"location_id": "uuid"}
     *
     * If location_id found:
     *   1. Load user's membership
     *   2. Check allowed_location_ids (null = all locations)
     *   3. Reject if not in allowed list
     *   4. Set LocationContext for downstream use
     *
     * If no location_id:
     *   - Allow (operation may not be location-scoped)
     */
    public function handle(Request $request, Closure $next): Response;

    /**
     * Extract location ID from request.
     */
    private function extractLocationId(Request $request): ?string;

    /**
     * Check if user can access location.
     */
    private function canAccessLocation(User $user, string $locationId): bool;
}
```

**Route Registration:**
```php
// Apply to routes that require location validation
Route::middleware(['auth:sanctum', SetPermissionsTeam::class, ValidateLocationAccess::class])
    ->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        // ...
    });
```

---

#### 1.3 Add location_id to document_lines Table

**Migration:** `2025_12_27_000001_add_location_id_to_document_lines_table.php`

```php
Schema::table('document_lines', function (Blueprint $table) {
    // Add location_id as nullable (inherits from document if not specified)
    $table->uuid('location_id')
        ->nullable()
        ->after('document_id');

    // Foreign key to locations table
    $table->foreign('location_id')
        ->references('id')
        ->on('locations')
        ->nullOnDelete();

    // Index for location-based queries
    $table->index('location_id');
});
```

**Model Update:** `app/Modules/Document/Domain/DocumentLine.php`

```php
protected $fillable = [
    'document_id',
    'product_id',
    'location_id',  // NEW
    'line_number',
    // ...
];

/**
 * Get the location this line ships from/receives to.
 */
public function location(): BelongsTo
{
    return $this->belongsTo(Location::class);
}

/**
 * Resolve effective location for this line.
 *
 * Falls back to parent document location if line doesn't specify.
 */
public function getEffectiveLocationId(): ?string
{
    return $this->location_id ?? $this->document->location_id;
}
```

---

#### 1.4 Add location_id to payment_allocations Table

**Migration:** `2025_12_27_000002_add_location_id_to_payment_allocations_table.php`

```php
Schema::table('payment_allocations', function (Blueprint $table) {
    $table->uuid('location_id')
        ->nullable()
        ->after('payment_id');

    $table->foreign('location_id')
        ->references('id')
        ->on('locations')
        ->nullOnDelete();

    $table->index('location_id');
});
```

**Purpose:**
- Treasury reporting by location
- Payment reconciliation per location
- Multi-location cash flow analysis

---

#### 1.5 Add location_id to journal_entries Table

**Migration:** `2025_12_27_000003_add_location_id_to_journal_entries_table.php`

```php
Schema::table('journal_entries', function (Blueprint $table) {
    $table->uuid('location_id')
        ->nullable()
        ->after('company_id');

    $table->foreign('location_id')
        ->references('id')
        ->on('locations')
        ->nullOnDelete();

    $table->index(['company_id', 'location_id']); // Multi-column index for reporting
});
```

**Purpose:**
- Location-specific P&L statements
- Location-based balance sheets
- Inter-location transfer accounting

---

### Phase 2: Service Layer Enhancements

#### 2.1 Update DocumentService to Handle Line Locations

**File:** `app/Modules/Document/Application/Services/DocumentService.php` (hypothetical)

```php
/**
 * Create document with location-aware lines.
 */
public function createDocument(DocumentData $data): Document
{
    return DB::transaction(function () use ($data) {
        $document = Document::create([
            'location_id' => $data->locationId ?? $this->locationContext->getDefaultLocation()?->id,
            // ...
        ]);

        foreach ($data->lines as $lineData) {
            DocumentLine::create([
                'document_id' => $document->id,
                'location_id' => $lineData->locationId ?? $document->location_id,
                // Falls back to document location if line doesn't specify
                // ...
            ]);
        }

        return $document;
    });
}
```

---

#### 2.2 Update ReturnNoteService to Use Line Locations

**File:** `app/Modules/Document/Domain/Services/ReturnNoteService.php`

**Current (Broken):**
```php
public function confirm(Document $returnNote): Document
{
    foreach ($returnNote->lines as $line) {
        $location = ???; // No way to get location!

        $this->stockService->receiveStock(
            productId: $line->product_id,
            quantity: $line->quantity,
            locationId: ???, // BLOCKED
        );
    }
}
```

**Fixed:**
```php
public function confirm(Document $returnNote): Document
{
    foreach ($returnNote->lines as $line) {
        // Use line's effective location (line.location_id ?? document.location_id)
        $locationId = $line->getEffectiveLocationId();

        if (!$locationId) {
            throw new \DomainException(
                "Cannot receive stock for line {$line->id}: no location specified"
            );
        }

        $this->stockService->receiveStock(
            productId: $line->product_id,
            quantity: $line->quantity,
            locationId: $locationId,
            reference: $returnNote,
        );
    }
}
```

---

#### 2.3 Update StockService to Validate Location Access

**File:** `app/Modules/Inventory/Application/Services/StockService.php`

```php
public function reserveStock(
    string $productId,
    string $quantity,
    string $locationId,
): void {
    // Validate location exists and user has access
    $location = Location::findOrFail($locationId);

    if (!$this->canAccessLocation(auth()->user(), $location)) {
        throw new UnauthorizedException(
            "You do not have permission to access location: {$location->name}"
        );
    }

    // Existing stock reservation logic
    // ...
}

private function canAccessLocation(User $user, Location $location): bool
{
    $membership = $user->companyMemberships()
        ->where('company_id', $location->company_id)
        ->first();

    if (!$membership) {
        return false;
    }

    // null = access all locations
    if ($membership->allowed_location_ids === null) {
        return true;
    }

    return in_array($location->id, $membership->allowed_location_ids);
}
```

---

### Phase 3: Frontend Enhancements

#### 3.1 Create Universal LocationSelector Component

**File:** `apps/web/src/components/ui/LocationSelector.tsx`

```tsx
interface LocationSelectorProps {
  value: string | null;
  onChange: (locationId: string | null) => void;
  required?: boolean;
  disabled?: boolean;
  placeholder?: string;
  showDefault?: boolean;  // Highlight default location
  filterType?: LocationType[];  // Filter by shop, warehouse, etc.
}

/**
 * Universal location selector for use across all modules.
 *
 * Features:
 * - Auto-fetches locations for current company
 * - Shows location type badges
 * - Highlights default location
 * - Search/filter by name or code
 * - Respects user's allowed_location_ids
 * - Emits onChange with location ID
 */
export function LocationSelector(props: LocationSelectorProps) {
  const { locations } = useLocations();
  const { currentLocationId, setCurrentLocationId } = useLocationStore();

  // Filter by user permissions
  const allowedLocations = useMemo(() => {
    // TODO: Filter by user.allowedLocationIds
    return locations;
  }, [locations]);

  return (
    <Combobox
      options={allowedLocations}
      value={props.value ?? currentLocationId}
      onChange={(id) => {
        props.onChange(id);
        setCurrentLocationId(id); // Update global context
      }}
      // ...
    />
  );
}
```

---

#### 3.2 Add Location Selector to Document Forms

**File:** `apps/web/src/features/documents/components/DocumentForm.tsx`

**Add to Header Section:**
```tsx
<div className="grid grid-cols-2 gap-4">
  {/* Existing fields: partner, date, currency */}
  <PartnerSelector value={partner} onChange={setPartner} />
  <DatePicker value={date} onChange={setDate} />

  {/* NEW: Location Selector */}
  <LocationSelector
    value={locationId}
    onChange={setLocationId}
    required={false}
    placeholder={t('documents.selectLocation')}
    showDefault={true}
  />
</div>
```

**Add to Line Items (Optional Per-Line Location):**
```tsx
<TableRow>
  <TableCell>Product</TableCell>
  <TableCell>Quantity</TableCell>
  <TableCell>Price</TableCell>

  {/* NEW: Optional per-line location */}
  <TableCell>
    <LocationSelector
      value={line.locationId}
      onChange={(id) => updateLine(index, { locationId: id })}
      placeholder={t('documents.sameAsHeader')}
      showDefault={false}
    />
  </TableCell>
</TableRow>
```

---

#### 3.3 Add Location Indicator to Document List

**File:** `apps/web/src/features/documents/components/DocumentList.tsx`

```tsx
<Table>
  <TableHeader>
    <TableRow>
      <TableHead>Number</TableHead>
      <TableHead>Partner</TableHead>
      <TableHead>Date</TableHead>
      <TableHead>Location</TableHead>  {/* NEW */}
      <TableHead>Total</TableHead>
      <TableHead>Status</TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {documents.map((doc) => (
      <TableRow key={doc.id}>
        <TableCell>{doc.documentNumber}</TableCell>
        <TableCell>{doc.partner.name}</TableCell>
        <TableCell>{formatDate(doc.documentDate)}</TableCell>

        {/* NEW: Location badge */}
        <TableCell>
          {doc.location ? (
            <LocationBadge location={doc.location} />
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </TableCell>

        <TableCell>{formatCurrency(doc.total)}</TableCell>
        <TableCell><StatusBadge status={doc.status} /></TableCell>
      </TableRow>
    ))}
  </TableBody>
</Table>
```

---

#### 3.4 Create LocationBadge Component

**File:** `apps/web/src/components/ui/LocationBadge.tsx`

```tsx
interface LocationBadgeProps {
  location: Location;
  showType?: boolean;
}

export function LocationBadge({ location, showType = true }: LocationBadgeProps) {
  const typeIcons = {
    shop: Store,
    warehouse: Warehouse,
    office: Building,
    mobile: Truck,
  };

  const Icon = typeIcons[location.type];

  return (
    <Badge variant="secondary" className="gap-1">
      {showType && <Icon className="h-3 w-3" />}
      {location.name}
      {location.isDefault && (
        <Star className="h-3 w-3 fill-yellow-400 text-yellow-400" />
      )}
    </Badge>
  );
}
```

---

#### 3.5 Add Active Location Indicator to Layout

**File:** `apps/web/src/components/layouts/DashboardLayout.tsx`

**Add to Top Bar (next to company selector):**
```tsx
<div className="flex items-center gap-4">
  {/* Existing company selector */}
  <CompanySelector />

  {/* NEW: Active location indicator */}
  <Popover>
    <PopoverTrigger asChild>
      <Button variant="outline" size="sm" className="gap-2">
        <MapPin className="h-4 w-4" />
        {currentLocation ? (
          <span>{currentLocation.name}</span>
        ) : (
          <span className="text-muted-foreground">No location</span>
        )}
      </Button>
    </PopoverTrigger>
    <PopoverContent className="w-80">
      <div className="space-y-2">
        <h4 className="font-medium">Select Active Location</h4>
        <LocationSelector
          value={currentLocationId}
          onChange={setCurrentLocationId}
          placeholder="Choose location..."
        />
        <p className="text-sm text-muted-foreground">
          This location will be used for new documents and stock operations.
        </p>
      </div>
    </PopoverContent>
  </Popover>
</div>
```

---

### Phase 4: Validation & Reporting

#### 4.1 Add Location Validation Rules

**File:** `app/Rules/ValidLocationAccess.php`

```php
/**
 * Validation rule: User must have access to the specified location.
 */
class ValidLocationAccess implements Rule
{
    public function passes($attribute, $value): bool
    {
        if (!$value) {
            return true; // null is allowed (central invoicing)
        }

        $location = Location::find($value);
        if (!$location) {
            return false;
        }

        $user = auth()->user();
        $membership = $user->companyMemberships()
            ->where('company_id', $location->company_id)
            ->first();

        if (!$membership) {
            return false;
        }

        // null = all locations allowed
        if ($membership->allowed_location_ids === null) {
            return true;
        }

        return in_array($value, $membership->allowed_location_ids);
    }

    public function message(): string
    {
        return 'You do not have permission to access this location.';
    }
}
```

**Usage in Requests:**
```php
// app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php
public function rules(): array
{
    return [
        'location_id' => ['nullable', 'uuid', 'exists:locations,id', new ValidLocationAccess],
        // ...
    ];
}
```

---

#### 4.2 Location-Based Reporting Queries

**Examples for Future Implementation:**

```php
// Location P&L
JournalEntry::where('company_id', $companyId)
    ->where('location_id', $locationId)
    ->whereBetween('entry_date', [$start, $end])
    ->with('lines.account')
    ->get();

// Location Cash Flow
PaymentAllocation::where('location_id', $locationId)
    ->whereBetween('allocated_at', [$start, $end])
    ->sum('amount_allocated');

// Location Stock Valuation
StockLevel::where('location_id', $locationId)
    ->with('product')
    ->get()
    ->sum(fn($level) => $level->quantity * $level->product->cost_price);
```

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1)
**Goal:** Establish core infrastructure without breaking existing functionality

| Task | Deliverable | Time | Dependency |
|------|-------------|------|------------|
| P1.1 | Create LocationContext service | 4h | None |
| P1.2 | Register LocationContext in AppServiceProvider | 1h | P1.1 |
| P1.3 | Add location_id to document_lines migration | 2h | None |
| P1.4 | Add location_id to payment_allocations migration | 1h | None |
| P1.5 | Add location_id to journal_entries migration | 1h | None |
| P1.6 | Update DocumentLine model with location relation | 2h | P1.3 |
| P1.7 | Run migrations and verify schema | 1h | P1.3-P1.5 |
| **Total** | **12h (1.5 days)** | | |

**Verification:**
- [ ] LocationContext service is registered and injectable
- [ ] document_lines table has location_id column (nullable)
- [ ] payment_allocations table has location_id column (nullable)
- [ ] journal_entries table has location_id column (nullable)
- [ ] DocumentLine model has `location()` relation
- [ ] Existing tests still pass (no breaking changes)

---

### Phase 2: Service Layer Integration (Week 2)
**Goal:** Update services to use location context with proper fallbacks

| Task | Deliverable | Time | Dependency |
|------|-------------|------|------------|
| P2.1 | Update ReturnNoteService to use line locations | 3h | P1.6 |
| P2.2 | Update DocumentService to accept line locations | 4h | P1.6 |
| P2.3 | Update StockService to validate location access | 3h | P1.1 |
| P2.4 | Update CreditNoteService to copy line locations | 2h | P1.6 |
| P2.5 | Update GL posting to include location context | 4h | P1.5 |
| P2.6 | Update payment allocation to store location | 2h | P1.4 |
| **Total** | **18h (2.25 days)** | | |

**Verification:**
- [ ] Return notes can be confirmed (test passes)
- [ ] Document creation accepts location_id per line
- [ ] Stock operations validate user has location access
- [ ] Credit notes copy source line locations
- [ ] Journal entries include location_id when applicable
- [ ] Payment allocations store location from invoice

---

### Phase 3: Permission Enforcement (Week 3)
**Goal:** Implement location-based access control

| Task | Deliverable | Time | Dependency |
|------|-------------|------|------------|
| P3.1 | Create ValidateLocationAccess middleware | 4h | P1.1 |
| P3.2 | Create ValidLocationAccess validation rule | 2h | None |
| P3.3 | Apply middleware to document routes | 1h | P3.1 |
| P3.4 | Apply middleware to inventory routes | 1h | P3.1 |
| P3.5 | Apply middleware to treasury routes | 1h | P3.1 |
| P3.6 | Add validation rule to all location_id fields | 2h | P3.2 |
| P3.7 | Test permission enforcement | 3h | P3.1-P3.6 |
| **Total** | **14h (1.75 days)** | | |

**Verification:**
- [ ] Middleware validates location access on protected routes
- [ ] Users with restricted locations cannot access others
- [ ] Validation rule prevents unauthorized location selection
- [ ] Audit logs record location access attempts
- [ ] Tests cover permission enforcement scenarios

---

### Phase 4: Frontend UI Integration (Week 4)
**Goal:** Make location selection available everywhere it's needed

| Task | Deliverable | Time | Dependency |
|------|-------------|------|------------|
| P4.1 | Create universal LocationSelector component | 4h | None |
| P4.2 | Create LocationBadge component | 2h | None |
| P4.3 | Add LocationSelector to DocumentForm header | 2h | P4.1 |
| P4.4 | Add optional LocationSelector to line items | 3h | P4.1 |
| P4.5 | Add location column to DocumentList | 2h | P4.2 |
| P4.6 | Add active location indicator to DashboardLayout | 3h | P4.1 |
| P4.7 | Update document API to accept line locations | 2h | P2.2 |
| P4.8 | Add location filter to document list queries | 2h | None |
| **Total** | **20h (2.5 days)** | | |

**Verification:**
- [ ] Location selector visible in all document forms
- [ ] Users can optionally set location per line
- [ ] Document list shows location badges
- [ ] Active location indicator in top bar
- [ ] Location selection persists across navigation
- [ ] API accepts and validates line locations

---

### Phase 5: Testing & Refinement (Week 5)
**Goal:** Comprehensive testing and edge case handling

| Task | Deliverable | Time | Dependency |
|------|-------------|------|------------|
| P5.1 | Write integration tests for location context | 4h | All phases |
| P5.2 | Write tests for permission enforcement | 3h | P3.x |
| P5.3 | Write tests for multi-location document creation | 3h | P2.x, P4.x |
| P5.4 | Write tests for return note location handling | 2h | P2.1 |
| P5.5 | Test default location fallback scenarios | 2h | P1.1 |
| P5.6 | Performance test location-scoped queries | 2h | All phases |
| P5.7 | User acceptance testing (UAT) | 4h | All phases |
| P5.8 | Bug fixes and refinements | 8h | P5.7 |
| **Total** | **28h (3.5 days)** | | |

**Verification:**
- [ ] All unit tests passing
- [ ] All integration tests passing
- [ ] Permission tests cover unauthorized access
- [ ] Multi-location workflows tested end-to-end
- [ ] Return note test no longer skipped
- [ ] Location queries perform well (< 100ms)
- [ ] UAT sign-off from product owner

---

## Total Implementation Timeline

| Phase | Duration | Cumulative |
|-------|----------|------------|
| Phase 1: Foundation | 1.5 days | 1.5 days |
| Phase 2: Service Layer | 2.25 days | 3.75 days |
| Phase 3: Permissions | 1.75 days | 5.5 days |
| Phase 4: Frontend UI | 2.5 days | 8 days |
| Phase 5: Testing | 3.5 days | 11.5 days |
| **Buffer (20%)** | 2.5 days | **14 days (2.8 weeks)** |

**Estimated Completion:** 3 weeks (with buffer)

---

## Success Criteria

### Functional Requirements ✅

- [ ] Users can select location when creating any document
- [ ] Users can optionally set location per document line
- [ ] Return notes can be confirmed with proper stock receipt
- [ ] Location selector is available in sales, inventory, and treasury modules
- [ ] Default location is auto-selected when user has single location
- [ ] Multi-location businesses can track operations per location

### Technical Requirements ✅

- [ ] LocationContext service is singleton and injectable
- [ ] Location validation middleware protects all location-scoped routes
- [ ] document_lines, payment_allocations, journal_entries have location_id
- [ ] Stock movements record accurate source/destination locations
- [ ] Journal entries enable location-based P&L reporting
- [ ] Payment allocations support location-based treasury reports

### Security Requirements ✅

- [ ] Users cannot access locations outside their allowed_location_ids
- [ ] Middleware validates location access on every request
- [ ] Validation rules prevent unauthorized location selection
- [ ] Audit logs record location access and changes
- [ ] Permission checks occur at both middleware and service layers

### User Experience Requirements ✅

- [ ] Location selector is intuitive and consistent across modules
- [ ] Active location indicator always visible in UI
- [ ] Default location highlighted with visual indicator
- [ ] Location changes persist across browser sessions
- [ ] Users can switch active location without leaving current page
- [ ] Location badges show location type icons

---

## Rollout Strategy

### Stage 1: Soft Launch (Internal Testing)
**Duration:** 1 week
**Audience:** Development team, QA team

- Deploy to staging environment
- Enable for test companies only
- Gather feedback on UI/UX
- Identify edge cases
- Performance benchmarking

### Stage 2: Beta Release (Pilot Customers)
**Duration:** 2 weeks
**Audience:** 3-5 multi-location pilot customers

- Deploy to production with feature flag
- Enable for selected customers
- Daily check-ins for feedback
- Monitor error rates and performance
- Prepare support documentation

### Stage 3: General Availability
**Duration:** Ongoing
**Audience:** All customers

- Remove feature flag
- Announce in release notes
- Publish user documentation
- Train support team
- Monitor adoption metrics

---

## Risks & Mitigation

| Risk | Impact | Probability | Mitigation |
|------|--------|-------------|------------|
| Breaking changes to existing documents | High | Low | Nullable location_id, extensive testing |
| Performance degradation on location queries | Medium | Medium | Add indexes, query optimization |
| User confusion with location selection | Medium | Medium | Clear UI, contextual help, defaults |
| Permission lockout (user loses access) | High | Low | Admin override, audit trail |
| Migration failures on large datasets | Medium | Low | Test on staging, rollback plan |
| Frontend state sync issues | Medium | Medium | Zustand persistence, React Query cache |

---

## Open Questions

1. **Location Hierarchy:** Should locations support parent/child relationships (e.g., Shop 1 → Warehouse A)?
2. **Inter-Location Transfers:** How should stock transfers between locations be recorded? New document type?
3. **Central Invoicing:** Should central office be able to invoice without location? (Currently: yes, nullable design)
4. **Location Switching:** Should changing active location reload current page data or just affect new operations?
5. **Default Location Creation:** Should company creation automatically create a "Main Location"? (Recommended: yes)
6. **Location Archives:** Can locations be soft-deleted or should they always remain for historical accuracy?
7. **Multi-Location Pricing:** Should products have different prices per location? (Future feature)

---

## Dependencies

### External Dependencies
- None (all changes are internal to AutoERP)

### Module Dependencies
| Module | Dependency Type | Reason |
|--------|----------------|--------|
| Company | Core | Location model lives here |
| Identity | Core | Permission validation |
| Document | Consumer | Uses location context |
| Inventory | Consumer | Uses location context |
| Treasury | Consumer | Uses location context |
| Accounting | Consumer | Location-based GL reporting |

---

## Documentation Requirements

### Developer Documentation
- [ ] LocationContext API reference
- [ ] Migration guide for adding location to new tables
- [ ] Permission middleware usage guide
- [ ] Location validation rule examples
- [ ] Service layer location handling patterns

### User Documentation
- [ ] How to set up locations
- [ ] How to assign users to locations
- [ ] How to select location in documents
- [ ] How to run location-based reports
- [ ] Troubleshooting location access issues

### API Documentation
- [ ] Update OpenAPI spec with location_id fields
- [ ] Document location validation responses
- [ ] Add location filter parameters to endpoints

---

## Appendix A: Alternative Approaches Considered

### Approach 1: Location as Header-Only (Current State)
**Pros:** Simple, works for single-location businesses
**Cons:** Cannot handle split shipments, return note processing blocked
**Decision:** Rejected - insufficient for multi-location scenarios

### Approach 2: Location per Line (Proposed)
**Pros:** Maximum flexibility, handles all scenarios
**Cons:** More complex UI, more database columns
**Decision:** **Accepted** - best balance of flexibility and complexity

### Approach 3: Location as Separate Entity Relationship
**Pros:** Clean relational model
**Cons:** Additional join overhead, complex queries
**Decision:** Rejected - performance concerns

---

## Appendix B: Database Schema Changes Summary

### New Columns

| Table | Column | Type | Nullable | Index | Foreign Key |
|-------|--------|------|----------|-------|-------------|
| document_lines | location_id | UUID | Yes | Yes | locations.id |
| payment_allocations | location_id | UUID | Yes | Yes | locations.id |
| journal_entries | location_id | UUID | Yes | Yes (composite) | locations.id |

### Expected Data Volume Impact

**document_lines:**
- Current rows: ~50,000 (average company)
- Storage increase: 16 bytes × 50,000 = ~800 KB per company
- Index size: ~400 KB per company

**payment_allocations:**
- Current rows: ~10,000 (average company)
- Storage increase: 16 bytes × 10,000 = ~160 KB per company

**journal_entries:**
- Current rows: ~100,000 (average company)
- Storage increase: 16 bytes × 100,000 = ~1.6 MB per company

**Total per company:** ~3 MB additional storage (negligible)

---

## Appendix C: Frontend Component Architecture

```
LocationManagement/
├── components/
│   ├── LocationSelector.tsx       (Universal selector)
│   ├── LocationBadge.tsx          (Display component)
│   ├── LocationSelectorMulti.tsx  (Multi-select for inventory)
│   └── ActiveLocationIndicator.tsx (Top bar widget)
├── hooks/
│   ├── useLocations.ts            (React Query hook)
│   ├── useLocationStore.ts        (Zustand store)
│   └── useLocationPermissions.ts  (Permission checker)
├── api/
│   └── locations.ts               (API client)
└── types/
    └── location.ts                (TypeScript types)
```

---

**Document Status:** Draft - Pending Approval
**Next Review:** After stakeholder feedback
**Owner:** Development Team
**Stakeholders:** Product, Engineering, Support
