# Tauri POS Architecture

## Project Location

```
apps/erp/
├── apps/
│   ├── api/          # Laravel backend (existing)
│   ├── web/          # React web app (existing)
│   └── pos/          # Tauri 2 desktop POS (NEW)
│       ├── src/              # React frontend
│       ├── src-tauri/        # Rust backend
│       ├── package.json
│       ├── vite.config.ts
│       └── tsconfig.json
```

## Tauri 2 Project Structure

```
apps/pos/
├── src/                          # React frontend (TypeScript)
│   ├── assets/
│   ├── components/               # Shared UI components
│   │   ├── atoms/
│   │   ├── molecules/
│   │   └── organisms/
│   ├── features/                 # Feature modules
│   │   ├── auth/                 # Login, session management
│   │   ├── sales/                # Main POS transaction screen
│   │   ├── menu/                 # Menu display and navigation
│   │   ├── cart/                 # Cart state and UI
│   │   ├── payments/             # Payment processing
│   │   ├── shifts/               # Shift management
│   │   ├── reports/              # X/Z reports, sales analytics
│   │   ├── loyalty/              # Loyalty integration
│   │   ├── settings/             # Hardware and app settings
│   │   └── sync/                 # Offline sync engine
│   ├── hooks/                    # Global hooks
│   ├── lib/
│   │   ├── api.ts                # HTTP client (Tauri fetch)
│   │   ├── db.ts                 # SQLite access layer
│   │   ├── hardware.ts           # Hardware abstraction (IPC)
│   │   └── sync.ts               # Sync orchestrator
│   ├── stores/                   # Zustand stores
│   │   ├── cartStore.ts
│   │   ├── sessionStore.ts
│   │   ├── settingsStore.ts
│   │   └── syncStore.ts
│   ├── types/                    # Shared from packages/shared/types
│   ├── locales/                  # i18n (en, fr, ar)
│   ├── App.tsx
│   ├── main.tsx
│   └── index.css
│
├── src-tauri/                    # Rust backend
│   ├── src/
│   │   ├── main.rs
│   │   ├── lib.rs
│   │   ├── commands/             # Tauri IPC commands
│   │   │   ├── mod.rs
│   │   │   ├── printer.rs        # ESC/POS printing
│   │   │   ├── cash_drawer.rs    # Cash drawer open
│   │   │   ├── scanner.rs        # Barcode scanner events
│   │   │   └── filesystem.rs     # Report PDF export
│   │   ├── hardware/             # Hardware abstraction
│   │   │   ├── mod.rs
│   │   │   ├── serial.rs         # Serial port communication
│   │   │   ├── usb.rs            # USB device access
│   │   │   └── network.rs        # Network printer discovery
│   │   └── db/                   # SQLite schema and migrations
│   │       ├── mod.rs
│   │       ├── schema.rs
│   │       └── migrations.rs
│   ├── Cargo.toml
│   ├── tauri.conf.json
│   ├── capabilities/
│   │   └── default.json
│   └── icons/
│
├── package.json
├── vite.config.ts
├── tsconfig.json
├── tailwind.config.ts
└── README.md
```

## Key Architecture Decisions

### 1. Offline-First with Sync

```
┌─────────────────────────────────────────┐
│              Tauri POS App              │
│                                         │
│  ┌──────────┐    ┌──────────────────┐  │
│  │  React   │◄──►│   Zustand Store   │  │
│  │   UI     │    │   (in-memory)     │  │
│  └──────────┘    └────────┬─────────┘  │
│                           │             │
│                    ┌──────▼──────┐      │
│                    │   SQLite    │      │
│                    │  (local DB) │      │
│                    └──────┬──────┘      │
│                           │             │
│                    ┌──────▼──────┐      │
│                    │ Sync Engine │      │
│                    └──────┬──────┘      │
└───────────────────────────┼─────────────┘
                            │
                     ┌──────▼──────┐
                     │  AutoERP    │
                     │  Laravel API│
                     └─────────────┘
```

**Sync strategy:**
- All writes go to local SQLite first
- Background sync pushes to API when online
- Pull-based sync for menu, stock levels, settings
- Conflict resolution: server-authoritative for master data, client-authoritative for receipts (with sequence)

### 2. Hardware Integration via Rust IPC

```
React Frontend  ──IPC──►  Rust Commands  ──►  Hardware
     │                        │
  invoke('print_receipt',     │
    { data, printer })        ├──► Serial Port (receipt printer)
                              ├──► USB (cash drawer)
                              ├──► Network (kitchen printer)
                              └──► HID (barcode scanner events)
```

Hardware commands are exposed as Tauri IPC commands in Rust. The React frontend calls them via `invoke()`.

### 3. State Management

| Store | Purpose | Persistence |
|-------|---------|-------------|
| `cartStore` | Current transaction cart | In-memory (cleared on checkout) |
| `sessionStore` | Auth token, terminal, shift | SQLite + memory |
| `settingsStore` | Hardware config, preferences | SQLite + memory |
| `syncStore` | Sync queue status, last sync | SQLite + memory |
| `menuStore` | Cached active menu | SQLite + memory |

### 4. Tauri 2 Plugins

| Plugin | Purpose |
|--------|---------|
| `tauri-plugin-sql` | SQLite database for offline storage |
| `tauri-plugin-store` | Key-value persistence (settings) |
| `tauri-plugin-http` | API communication |
| `tauri-plugin-notification` | System notifications (sync errors, alerts) |
| `tauri-plugin-os` | OS info for terminal identification |
| `tauri-plugin-updater` | Auto-updates |
| `tauri-plugin-window-state` | Remember window position/size |
| `tauri-plugin-log` | Structured logging |

### 5. Tauri Capabilities (Security)

```json
// src-tauri/capabilities/default.json
{
  "identifier": "default",
  "windows": ["main"],
  "permissions": [
    "core:default",
    "sql:default",
    "store:default",
    "http:default",
    "notification:default",
    "os:default",
    "updater:default",
    "window-state:default",
    "log:default"
  ]
}
```

### 6. Build & Distribution

| Platform | Format | Auto-update |
|----------|--------|-------------|
| Windows | MSI / NSIS installer | Yes (Tauri updater) |
| macOS | DMG / .app | Yes (Tauri updater) |
| Linux | AppImage / .deb | Yes (Tauri updater) |

## Shared Code Strategy

### Phase 1 (Initial)
- Types imported directly from `packages/shared/types/`
- Design system tokens shared via Tailwind config
- No component sharing yet

### Phase 2 (Later)
- Extract common POS components into `packages/pos-ui/`
- Share between `apps/web` and `apps/pos`
- Components: POSButton, MoneyInput, StockBadge, etc.

## Local SQLite Schema (Simplified)

```sql
-- Cached menu data
CREATE TABLE cached_menu (
  id TEXT PRIMARY KEY,
  data TEXT NOT NULL,        -- JSON blob of ActiveMenuData
  fetched_at TEXT NOT NULL,
  expires_at TEXT NOT NULL
);

-- Local receipts (offline queue)
CREATE TABLE local_receipts (
  id TEXT PRIMARY KEY,
  receipt_data TEXT NOT NULL,  -- Full receipt payload JSON
  status TEXT NOT NULL,        -- 'pending' | 'synced' | 'failed'
  created_at TEXT NOT NULL,
  synced_at TEXT,
  error TEXT,
  retry_count INTEGER DEFAULT 0
);

-- Session data
CREATE TABLE session (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Settings
CREATE TABLE settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Sync log
CREATE TABLE sync_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL,
  entity_id TEXT NOT NULL,
  action TEXT NOT NULL,
  status TEXT NOT NULL,
  timestamp TEXT NOT NULL,
  error TEXT
);
```
