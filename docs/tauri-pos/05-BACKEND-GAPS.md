# Backend API Gaps for Tauri POS

> What needs to be built or modified in the Laravel API to support the Tauri desktop POS.

---

## Priority Legend
- **P0** = Required before Tauri POS MVP launch
- **P1** = Required for full F&B experience
- **P2** = Nice-to-have, can be added later

---

## P0 - Required for MVP

### 1. Terminal Registration Endpoint
**Status:** Partially exists (Terminal CRUD exists, but no device-initiated registration)

**Gap:** The Tauri app needs to register itself as a Physical terminal from the device side.

**Needed:**
```
POST /api/v1/pos/terminals/register
Body: { device_id: string, device_name: string, type: 'physical' }
Response: { terminal: Terminal, registration_token: string }
```

The admin pre-creates a terminal in the web app, then the Tauri POS enters a registration code to link itself.

### 2. Offline Receipt Sync Endpoint
**Status:** Does not exist

**Gap:** The Tauri POS creates receipts locally while offline. It needs to batch-sync them to the API.

**Needed:**
```
POST /api/v1/pos/receipts/sync
Body: { receipts: ReceiptPayload[] }
Response: { results: { local_id: string, server_id: string, status: 'created' | 'duplicate' | 'error' }[] }
```

**Considerations:**
- Must handle duplicate detection (same receipt_number should not create duplicates)
- Must validate fiscal hash chain continuity
- Must process stock decrements for each receipt
- Must be idempotent (retry-safe)

### 3. Menu Sync Endpoint (Bulk Pull)
**Status:** RETIRED 2026-07-28 — a `GET /pos/sync/menu` route existed but had ZERO client callers ever (proof in commit `1b5a5e57e`); it was deleted and now 404s (pinned by `SyncReadRouteRetirementTest`). Clients use `GET /api/v1/active-menu`. Do NOT re-add a caller against this path.

**Gap:** Tauri needs to pull the full menu with last-modified timestamps for efficient caching.

**Needed:**
```
GET /api/v1/pos/sync/menu?since=2026-03-05T10:00:00Z
Response: { menu: ActiveMenuData, last_modified: string, etag: string }
```

Support `If-None-Match` / `304 Not Modified` for efficient polling.

### 4. Bulk Sync Pull Endpoint
**Status:** RETIRED 2026-07-28 — a `GET /pos/sync/pull` route existed but had ZERO client callers ever (proof in commit `1b5a5e57e`); it was deleted and now 404s (pinned by `SyncReadRouteRetirementTest`). Clients pull via `/products` (`pullProductsCore`). Do NOT re-add a caller against this path.

**Gap:** Single endpoint to pull all POS-relevant data for initial setup and periodic sync.

**Needed:**
```
GET /api/v1/pos/sync/pull?since=2026-03-05T10:00:00Z
Response: {
  menu: ActiveMenuData | null,
  payment_methods: PaymentMethod[],
  payment_repositories: PaymentRepository[],
  stock_levels: { product_id: string, available: number }[],
  settings: { discount_limits: ..., ... },
  last_sync: string
}
```

### 5. Receipt PDF Local Generation
**Status:** PDF is generated server-side via `ReceiptPdfService`

**Gap:** The Tauri POS needs to print receipts locally via ESC/POS thermal printers, not download PDFs from the server.

**Needed (API side):** No API change needed — receipt printing will happen locally in Rust using ESC/POS commands. But the receipt data structure must include all fields needed for local formatting:
- Company name, address, tax ID
- Receipt number, date, time
- Line items with modifiers, quantities, prices
- VAT breakdown
- Payment methods
- Fiscal hash (for NF525 compliance printing)

**Check:** Verify that `GET /api/v1/pos/receipts/{id}` returns all these fields.

### 6. Shift Sync for Offline Close
**Status:** Shift open/close exists but assumes online operation

**Gap:** If the Tauri POS closes a shift while offline, it needs to sync the close operation later.

**Needed:**
```
POST /api/v1/pos/shifts/sync-close
Body: { shift_id: string, closed_at: string, actual_cash: string, operations: CashDrawerOperation[] }
```

---

## P1 - Required for Full F&B

### 7. Order Notes on Receipt
**Status:** Receipt model has no `notes` or `order_notes` field

**Gap:** F&B needs per-receipt and per-line notes (e.g., "no onions", "extra hot").

**Needed:**
- Add `notes` column to `pos_receipts` table
- Add `notes` column to `pos_receipt_lines` table
- Update `StoreReceiptRequest` to accept notes
- Update `ReceiptCreationService` to persist notes

### 8. Modifier Selection Persistence
**Status:** Modifiers are stored as JSONB snapshot on receipt lines, but the exact selected modifiers may not be fully captured

**Gap:** Verify and ensure that the `modifiers` JSONB on `pos_receipt_lines` captures:
- Modifier group ID and name
- Selected modifier ID, name, and price adjustment
- Whether the modifier was required or optional

**Check:** Read `ReceiptLine` model's `modifiers` cast and `StoreReceiptRequest` validation.

### 9. Kitchen Ticket Data
**Status:** No KDS (Kitchen Display System) support exists

**Gap:** When a receipt is created with F&B items, the backend should emit an event that can be consumed by a KDS or kitchen printer.

**Needed (Phase 1 - Print-only):**
- No new API endpoint needed initially
- The Tauri POS will format and print kitchen tickets locally based on receipt line data
- Each line's `category` determines which printer it routes to

**Needed (Phase 2 - KDS):**
```
# New Order Management Module
POST /api/v1/orders                    # Create order (before payment)
GET  /api/v1/orders/pending            # KDS: get pending orders
PATCH /api/v1/orders/{id}/status       # KDS: mark preparing/ready
GET  /api/v1/orders/{id}               # Get order details
```

### 10. Table Management Module
**Status:** Referenced in Vertical.php (`compatibleExtras: ['Tables']`) but module does not exist

**Gap:** Restaurant vertical needs table assignment.

**Needed (new module):**
```
# Tables Module
GET    /api/v1/tables                  # List tables with status
POST   /api/v1/tables                  # Create table
PATCH  /api/v1/tables/{id}             # Update table
DELETE /api/v1/tables/{id}             # Delete table
PATCH  /api/v1/tables/{id}/assign      # Assign order to table
PATCH  /api/v1/tables/{id}/release     # Release table

# Table data on receipt
- Add `table_id` and `table_number` to pos_receipts
- Add `covers` (number of guests) to pos_receipts
```

### 11. Service Charge
**Status:** Not implemented

**Gap:** Restaurants may apply automatic service charges.

**Needed:**
- Add `service_charge_rate` to company/terminal settings
- Add `service_charge_amount` to receipts
- Include in total calculation
- Configurable: automatic vs optional

---

## P2 - Nice to Have

### 12. Customer Display Data
**Status:** Not applicable to web POS

**Gap:** Tauri POS may drive a customer-facing display showing current cart items and total.

**Needed:** No API changes — this is handled entirely in the Tauri frontend/Rust backend via secondary window or serial display.

### 13. Stock Level Alerts
**Status:** Stock levels exist but no alert/threshold system

**Gap:** POS should show when items are low/out of stock.

**Needed:**
- Add `low_stock_threshold` to product/stock_level
- Include in sync pull response
- Frontend shows badge/warning

### 14. Happy Hour / Time-Based Pricing
**Status:** Menu has `active_from`/`active_until` time ranges but no time-based pricing

**Gap:** Different prices at different times of day.

**Needed:**
- Price schedules on menu category items
- Or separate pricing rules evaluated at checkout

### 15. Split Bill
**Status:** Not implemented

**Gap:** Split a single receipt across multiple bills (by seat or by item).

**Needed:**
- `POST /api/v1/pos/receipts/{id}/split` with split configuration
- Creates multiple receipts from one, maintaining fiscal compliance

### 16. Order Recall / Duplicate
**Status:** Not implemented

**Gap:** Re-create a previous order for a returning customer.

**Needed:**
- `POST /api/v1/pos/receipts/{id}/duplicate` — creates new cart from receipt lines

---

## Summary Priority Matrix

| # | Feature | Priority | Effort | Module |
|---|---------|----------|--------|--------|
| 1 | Terminal registration | P0 | Small | POS |
| 2 | Offline receipt sync | P0 | Large | POS |
| 3 | Menu sync with caching | P0 | Medium | Menu/POS |
| 4 | Bulk sync pull | P0 | Medium | POS |
| 5 | Receipt data for local print | P0 | Small | POS |
| 6 | Offline shift sync | P0 | Medium | POS |
| 7 | Order notes | P1 | Small | POS |
| 8 | Modifier persistence check | P1 | Small | POS |
| 9 | Kitchen ticket support | P1 | Medium | POS |
| 10 | Table management | P1 | Large | New Module |
| 11 | Service charge | P1 | Medium | POS |
| 12 | Customer display | P2 | Small | Tauri only |
| 13 | Stock level alerts | P2 | Small | Inventory |
| 14 | Time-based pricing | P2 | Medium | Menu/Pricing |
| 15 | Split bill | P2 | Large | POS |
| 16 | Order recall | P2 | Small | POS |
