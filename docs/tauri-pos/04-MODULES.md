# Tauri POS Modules

> Breakdown of each module/section in the Tauri desktop POS application.

---

## 1. Authentication & Session

**Purpose:** Login, session persistence, terminal registration.

### Screens
| Screen | Description |
|--------|-------------|
| Login | Email/password login against API |
| Terminal Registration | First-run: link this device to a backend Terminal record |

### Behavior
- Auth token stored in local SQLite
- Auto-login on app start if token is valid
- Terminal ID persisted permanently after first registration
- Session timeout configurable in settings

### API Dependencies
- `POST /api/v1/login` (existing)
- `POST /api/v1/pos/terminals` or `GET /api/v1/pos/terminals/{id}` (existing)

---

## 2. Sales Screen (Main POS)

**Purpose:** The primary transaction interface. Product/menu browsing, cart management, checkout.

### Layout
```
┌──────────────────────────────────────────────────────────┐
│  Header: Shift info │ Terminal │ Clock │ User │ Settings │
├────────────────────────────────┬─────────────────────────┤
│                                │                         │
│   Menu Categories (tabs)       │   Cart                  │
│                                │                         │
│   ┌─────┐ ┌─────┐ ┌─────┐    │   ├─ Item 1  x2  $10   │
│   │     │ │     │ │     │    │   │  └ Oat Milk +$0.50  │
│   │ Item│ │ Item│ │ Item│    │   ├─ Item 2  x1  $8    │
│   │     │ │     │ │     │    │   │                      │
│   └─────┘ └─────┘ └─────┘    │   │                      │
│   ┌─────┐ ┌─────┐ ┌─────┐    │   ├──────────────────── │
│   │     │ │     │ │     │    │   │  Subtotal    $18.50  │
│   │ Item│ │ Item│ │ Item│    │   │  Tax          $1.85  │
│   │     │ │     │ │     │    │   │  Discount    -$2.00  │
│   └─────┘ └─────┘ └─────┘    │   │  TOTAL      $18.35  │
│                                │   │                      │
│  [Search bar] [Scan barcode]   │   │  [Pay Cash] [Pay..] │
└────────────────────────────────┴─────────────────────────┘
```

### Features
- Menu-driven product grid with category tabs
- Search by name or code
- Barcode scanning (USB HID via Rust IPC)
- Modifier selection modal for composite items
- Consumption mode toggle (dine-in / takeout)
- Cart with quantity +/-, removal, line discounts
- Transaction-level discount
- Customer lookup (loyalty member)
- Quick cash payment (auto-calculate change)
- Advanced split payments modal
- Receipt printing on checkout
- Cash drawer auto-open on cash payment

### State
- `cartStore` (Zustand): items, quantities, modifiers, discounts, customer
- Active menu from `menuStore` (cached from API)

---

## 3. Payments

**Purpose:** Process payments for the current cart.

### Modes
| Mode | Description |
|------|-------------|
| Quick Cash | Single cash payment, change calculation, cash tendered modal |
| Advanced | Split across multiple methods (cash, card, voucher, loyalty reward) |

### Features
- Payment method grid (from API, cached locally)
- Payment repository selection (cash register, safe, bank account)
- Card payment: last 4 digits, authorization code
- Voucher: serial number tracking
- Loyalty reward redemption
- Coupon code entry (manual or barcode scan)
- Overpayment handling (change vs credit)
- Cash tendered modal with denomination buttons
- Receipt auto-print after successful payment
- Cash drawer auto-open for cash payments

### API Dependencies
- `POST /api/v1/pos/receipts` (create receipt)
- `POST /api/v1/pos/receipts/{id}/payments` (process payments)
- `GET /api/v1/payment-methods` (cached)
- `GET /api/v1/payment-repositories` (cached)

---

## 4. Shift Management

**Purpose:** Open/close shifts, cash drawer operations, mid-shift reporting.

### Screens
| Screen | Description |
|--------|-------------|
| Open Shift | Enter opening cash amount |
| Shift Dashboard | Current shift summary, cash balance, operations |
| Close Shift | Enter counted cash, see variance, confirm close |
| Shift Receipts | List of receipts in current shift |

### Features
- Open shift with opening cash balance
- Real-time cash drawer balance tracking
- Cash deposits (safe drops)
- Cash payouts (petty cash, refunds)
- X-Report generation (mid-shift snapshot)
- Shift close with counted cash and variance display
- Auto-prompt to close shift on app exit (if shift open)

### API Dependencies
- `POST /api/v1/pos/shifts/open`
- `POST /api/v1/pos/shifts/{id}/close`
- `GET /api/v1/pos/shifts/current/{terminalId}`
- `POST /api/v1/pos/cash-drawer/deposit`
- `POST /api/v1/pos/cash-drawer/payout`
- `GET /api/v1/pos/cash-drawer/{shiftId}/balance`

---

## 5. Reports

**Purpose:** X-Reports, Z-Reports, and local sales analytics. Unlike web POS where reports are just API-triggered, the Tauri POS generates and stores reports locally with PDF export.

### Screens
| Screen | Description |
|--------|-------------|
| X-Report | Generate and display mid-shift snapshot |
| Z-Report | Generate, display, print, and export end-of-day report |
| Z-Report History | Browse past Z-reports for this terminal |
| Sales Summary | Daily/shift sales breakdown by category, hour, product |

### Features
- **X-Report**: generate anytime, non-destructive, printable
- **Z-Report**: end-of-day closing, fiscal hash chain, printable, PDF export to filesystem
- **Z-Report PDF**: generate locally, save to chosen folder, auto-name with date
- **Sales by category**: pie chart or table breakdown
- **Sales by hour**: bar chart showing hourly distribution
- **Top products**: ranked list of best sellers for the shift/day
- **Local history**: browse Z-reports generated on this terminal

### API Dependencies
- `POST /api/v1/pos/reports/x`
- `POST /api/v1/pos/reports/z`
- `GET /api/v1/pos/reports/z` (list)

### Local Storage
- Z-report data cached in SQLite for offline browsing
- PDF files saved to filesystem via Rust IPC

---

## 6. Loyalty

**Purpose:** Customer loyalty integration at the POS.

### Features
- Member lookup by phone number or loyalty card scan
- Display current balance, tier, and stamp card progress
- Points earning preview before checkout
- Reward selection and redemption as payment/discount
- Stamp card tracking
- Enroll new members at POS

### API Dependencies
- `POST /api/v1/loyalty/pos/member-lookup`
- `POST /api/v1/loyalty/pos/preview-earning`
- `GET /api/v1/loyalty/pos/rewards/{memberId}`
- `POST /api/v1/loyalty/pos/redeem`
- `POST /api/v1/loyalty/pos/earn`

---

## 7. Settings

**Purpose:** Hardware configuration, app preferences, terminal settings. This is unique to the Tauri POS and has no web equivalent.

### Sections

#### 7.1 Printer Settings
| Setting | Description |
|---------|-------------|
| Receipt Printer | Select from detected printers (USB/serial/network) |
| Kitchen Printer | Optional second printer for kitchen tickets |
| Paper Width | 58mm or 80mm |
| Auto-Print | Print receipt automatically after checkout |
| Print Test | Send test page to verify configuration |
| Logo | Upload receipt header logo |
| Footer Text | Customizable receipt footer (legal text, WiFi, etc.) |

#### 7.2 Cash Drawer
| Setting | Description |
|---------|-------------|
| Connection | Via printer (ESC/POS command) or dedicated port |
| Auto-Open | Open drawer on cash payment |
| Open Command | Custom ESC/POS command if non-standard |

#### 7.3 Display
| Setting | Description |
|---------|-------------|
| Fullscreen | Toggle fullscreen/windowed mode |
| Kiosk Mode | Lock to POS only (no window controls) |
| Theme | Light / Dark / System |
| Font Size | Normal / Large (accessibility) |
| Screen Layout | Product grid size (small/medium/large cards) |

#### 7.4 Network & Sync
| Setting | Description |
|---------|-------------|
| Server URL | AutoERP API base URL |
| Sync Interval | How often to push/pull (30s, 1m, 5m) |
| Connection Status | Online/offline indicator |
| Force Sync | Manual sync trigger |
| Sync Log | View recent sync activity and errors |

#### 7.5 Terminal
| Setting | Description |
|---------|-------------|
| Terminal Code | Read-only, from backend registration |
| Terminal Name | Display name |
| Location | Assigned location |

#### 7.6 Sound
| Setting | Description |
|---------|-------------|
| Transaction Sound | Enable/disable checkout sound |
| Alert Sound | Enable/disable error/warning sounds |
| Scanner Beep | Enable/disable scan confirmation |

#### 7.7 Language & Locale
| Setting | Description |
|---------|-------------|
| Language | en, fr, ar |
| Currency Format | Symbol, decimal places, position |
| Date Format | dd/MM/yyyy or MM/dd/yyyy |

---

## 8. Offline & Sync

**Purpose:** Enable the POS to operate without internet and sync when connectivity returns.

### Offline Capabilities
| Feature | Offline Support | Notes |
|---------|----------------|-------|
| View menu | Yes | Cached in SQLite |
| Create receipts | Yes | Stored locally with local hash chain |
| Process payments | Yes | Queued for sync |
| Cash operations | Yes | Queued for sync |
| Open/close shift | Yes | Queued for sync |
| X-Report | Partial | Local data only |
| Z-Report | Partial | Local data only, synced later |
| Loyalty lookup | No | Requires API |
| Promotions | Cached | Rules cached, evaluated locally |

### Sync Flow
```
1. App starts → check connectivity
2. If online:
   a. Pull latest menu, stock levels, payment methods, settings
   b. Push any pending local receipts/operations
   c. Start background sync timer
3. If offline:
   a. Use cached data
   b. Queue all writes locally
   c. Show offline indicator
   d. Retry sync on connectivity change
4. On receipt creation:
   a. Save to local SQLite with status='pending'
   b. If online, immediately push to API
   c. On success, update status='synced'
   d. On failure, keep as 'pending', increment retry_count
```

### Conflict Resolution
- **Menu changes**: server-authoritative, full replace on sync
- **Receipts**: client-authoritative (each terminal has unique sequence)
- **Stock levels**: server-authoritative (approximate locally)
- **Settings**: server-authoritative for business rules, local for hardware

---

## 9. Receipt Void

**Purpose:** Void a previously created receipt.

### Features
- Search receipts by number or browse current shift receipts
- Display receipt details before voiding
- Require void reason
- Permission check (may require manager override)
- Reverse stock movements
- Reverse cash drawer balance
- Print void receipt
- Sync void to API

---

## 10. Calculator

**Purpose:** On-screen calculator for quick math (change calculation, etc.)

### Features
- Floating modal, accessible from any screen
- Basic operations: +, -, *, /
- Keyboard shortcut to open (Ctrl+K)
- Copy result to clipboard or paste into active field
