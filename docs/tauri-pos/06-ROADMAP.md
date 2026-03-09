# Tauri POS - Implementation Roadmap

---

## Phase 0: Project Scaffolding
**Goal:** Tauri 2 project set up, builds, and runs with React + TypeScript.

### Tasks
- [ ] Scaffold Tauri 2 project in `apps/pos/` with React + Vite + TypeScript
- [ ] Configure Tailwind CSS 4 with shared design tokens
- [ ] Set up i18n (react-i18next) with en/fr/ar namespaces
- [ ] Install and configure Tauri plugins: sql, store, http, notification, updater, window-state, log
- [ ] Set up SQLite database with initial schema (session, settings, sync_log)
- [ ] Create basic app shell: window, header bar, navigation
- [ ] Configure Tauri capabilities and permissions
- [ ] Set up build pipeline (dev + production)
- [ ] Import shared types from `packages/shared/types/`

### Deliverable
App launches, shows a shell with header and empty content area. SQLite database initializes.

---

## Phase 1: Authentication & Terminal Setup
**Goal:** User can log in and register the terminal.

### Backend Tasks
- [ ] Create `POST /api/v1/pos/terminals/register` endpoint
- [ ] Add terminal registration code generation in web admin

### Frontend Tasks
- [ ] Login screen with email/password
- [ ] API client with auth token management
- [ ] Terminal registration flow (enter code, link to backend terminal)
- [ ] Session persistence in SQLite
- [ ] Auto-login on app restart

### Deliverable
User logs in, terminal is registered and linked to backend. Session persists across restarts.

---

## Phase 2: Core POS Transaction
**Goal:** Full transaction lifecycle: browse menu, add to cart, checkout, print receipt.

### Backend Tasks
- [ ] Verify `GET /api/v1/active-menu` returns all data needed for local display
- [ ] Verify `GET /api/v1/pos/receipts/{id}` returns all fields for local receipt formatting
- [ ] Add `notes` column to `pos_receipts` and `pos_receipt_lines` (P1 prep)

### Frontend Tasks
- [ ] Menu display with category tabs and product grid
- [ ] Product search by name/code
- [ ] Cart store (Zustand) with add/remove/quantity/clear
- [ ] Modifier selection modal for composite items
- [ ] Consumption mode toggle (dine-in / takeout)
- [ ] Line-level discount input
- [ ] Transaction-level discount input
- [ ] Payment panel with totals display
- [ ] Quick cash payment with change calculation
- [ ] Advanced payments modal (split payments)
- [ ] Receipt creation via API
- [ ] Checkout success dialog

### Deliverable
Full transaction from menu browsing to payment and receipt creation. Online-only at this stage.

---

## Phase 3: Shift Management & Cash Drawer
**Goal:** Full shift lifecycle with cash drawer operations.

### Frontend Tasks
- [ ] Open shift modal with opening cash
- [ ] Shift dashboard with summary stats
- [ ] Cash deposit and payout modals
- [ ] Real-time cash drawer balance
- [ ] Close shift with cash count and variance
- [ ] Shift receipts list
- [ ] Auto-prompt shift close on app exit

### Deliverable
Complete shift management. Cashier opens shift, processes transactions, performs cash operations, closes shift.

---

## Phase 4: Hardware Integration
**Goal:** Receipt printing, cash drawer, barcode scanning.

### Rust Tasks
- [ ] ESC/POS receipt printer command module (USB/serial/network)
- [ ] Receipt template formatting in Rust (company header, lines, VAT, payments, footer, hash)
- [ ] Cash drawer open command (via printer or dedicated port)
- [ ] Barcode scanner USB HID event listener
- [ ] Printer discovery (enumerate available printers)

### Frontend Tasks
- [ ] Settings page: Printer configuration section
- [ ] Settings page: Cash drawer configuration section
- [ ] Auto-print receipt on checkout
- [ ] Auto-open cash drawer on cash payment
- [ ] Barcode scan → product lookup in cart
- [ ] Print test page button
- [ ] Kitchen printer configuration (optional second printer)
- [ ] Kitchen ticket formatting and routing

### Deliverable
Receipts print on thermal printer. Cash drawer opens automatically. Barcode scanner adds products to cart.

---

## Phase 5: Reports & Z-Report
**Goal:** X/Z reports generated and printed locally, with PDF export.

### Frontend Tasks
- [ ] X-Report generation and display
- [ ] X-Report print to thermal printer
- [ ] Z-Report generation and display
- [ ] Z-Report print to thermal printer
- [ ] Z-Report PDF export to filesystem (file picker dialog)
- [ ] Z-Report history (local SQLite cache)
- [ ] Sales summary by category (shift/day)
- [ ] Sales summary by hour (shift/day)
- [ ] Top products ranking

### Rust Tasks
- [ ] PDF generation for Z-reports (via Rust PDF library or Tauri IPC)
- [ ] File save dialog integration
- [ ] Z-report data caching in SQLite

### Deliverable
Z-reports are generated, printed, and saved as PDF files locally. Sales analytics visible for current shift.

---

## Phase 6: Offline Mode & Sync
**Goal:** POS operates without internet. Syncs when connectivity returns.

### Backend Tasks
- [ ] Create `POST /api/v1/pos/receipts/sync` (batch receipt sync with idempotency)
- [ ] Create `GET /api/v1/pos/sync/pull` (bulk data pull with timestamps)
- [ ] Create `POST /api/v1/pos/shifts/sync-close` (offline shift close sync)
- [ ] Add menu sync with ETag/If-None-Match support

### Frontend Tasks
- [ ] Offline indicator in header
- [ ] Local receipt creation with SQLite persistence
- [ ] Local fiscal hash chain (offline receipts maintain chain integrity)
- [ ] Sync queue UI (pending items count, last sync time)
- [ ] Background sync timer (configurable interval)
- [ ] Connectivity detection and auto-sync on reconnect
- [ ] Menu caching in SQLite with expiry
- [ ] Payment methods and settings caching
- [ ] Sync error handling and retry logic
- [ ] Sync log viewer in settings
- [ ] Force sync button

### Deliverable
POS works fully offline. Receipts are created locally and synced when online. Menu and settings are cached.

---

## Phase 7: Loyalty Integration
**Goal:** Customer loyalty at the POS.

### Frontend Tasks
- [ ] Member lookup by phone number
- [ ] Loyalty card barcode scan → member lookup
- [ ] Member badge display (name, tier, balance, stamps)
- [ ] Points earning preview before checkout
- [ ] Reward selection in payment flow
- [ ] Stamp card progress display
- [ ] New member enrollment at POS

### Deliverable
Cashier can look up members, see points/rewards, and apply loyalty benefits to transactions.

---

## Phase 8: Settings & Configuration
**Goal:** Complete settings UI for all hardware and app preferences.

### Frontend Tasks
- [ ] Settings page with tabbed sections
- [ ] Printer settings (receipt + kitchen)
- [ ] Cash drawer settings
- [ ] Display settings (fullscreen, kiosk, theme, font size, grid size)
- [ ] Network/sync settings (server URL, sync interval)
- [ ] Terminal info (read-only)
- [ ] Sound settings
- [ ] Language/locale settings
- [ ] Receipt template customization (logo, footer)
- [ ] About page (version, update check)

### Rust Tasks
- [ ] Settings persistence in SQLite/Store
- [ ] Fullscreen/kiosk mode toggle
- [ ] Auto-update integration (Tauri updater)

### Deliverable
All settings configurable through the UI. App can be customized for any deployment.

---

## Phase 9: F&B Enhancements (Post-MVP)

### Table Management
- [ ] Backend: Create Tables module (models, migration, CRUD, routes)
- [ ] Frontend: Table map/grid view
- [ ] Table assignment on order
- [ ] Table status indicators (free, occupied, reserved)
- [ ] Transfer items between tables
- [ ] Table-linked receipt creation

### Kitchen Display System (KDS)
- [ ] Backend: Order management endpoints (pending, status update)
- [ ] Frontend: KDS view (separate window or tab)
- [ ] Real-time order queue with status
- [ ] Order ready notification to POS
- [ ] Course management (starters, mains, desserts)

### Service Charge
- [ ] Backend: Service charge on receipt
- [ ] Frontend: Configurable auto-service charge
- [ ] Display on receipt and totals

---

## Phase Summary

| Phase | Scope | Dependencies |
|-------|-------|-------------|
| 0 | Scaffolding | None |
| 1 | Auth & Terminal | Phase 0 |
| 2 | Core POS | Phase 1 |
| 3 | Shifts & Cash | Phase 2 |
| 4 | Hardware | Phase 2 |
| 5 | Reports | Phase 3 |
| 6 | Offline & Sync | Phase 2 + backend work |
| 7 | Loyalty | Phase 2 |
| 8 | Settings | Phase 4 (hardware settings need hardware) |
| 9 | F&B Enhancements | Phase 2 + backend work |

Phases 4-8 can be worked on in parallel after Phase 2 is complete. Phase 6 requires backend API work (sync endpoints).
