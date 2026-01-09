# Web POS to Tauri 2 Compatibility Guide

**Date:** 2026-01-08
**Purpose:** Ensure web POS code is easily portable to Tauri 2 desktop application
**Target:** Windows desktop application (Tauri 2)

---

## Table of Contents

1. [Architecture for Cross-Platform Compatibility](#architecture-for-cross-platform-compatibility)
2. [Platform Abstraction Layer](#platform-abstraction-layer)
3. [Code Organization for Portability](#code-organization-for-portability)
4. [Tauri-Specific Features](#tauri-specific-features)
5. [Migration Checklist](#migration-checklist)

---

## 1. Architecture for Cross-Platform Compatibility

### Shared Code vs Platform-Specific Code

```
┌─────────────────────────────────────────────────────────────────┐
│                     SHARED CODE (90%)                            │
│  ✓ All React components (atoms, molecules, organisms)           │
│  ✓ Business logic hooks (useCart, useCheckout, etc.)            │
│  ✓ State management (Zustand stores, React Query)               │
│  ✓ Types and interfaces                                         │
│  ✓ Utility functions (calculations, formatters)                 │
│  ✓ UI styles (Tailwind CSS)                                     │
├─────────────────────────────────────────────────────────────────┤
│                  PLATFORM ADAPTERS (10%)                         │
│  ⚠️ API client (HTTP vs Tauri commands)                         │
│  ⚠️ Storage (localStorage vs Tauri store)                       │
│  ⚠️ Printer (browser print vs native printer)                   │
│  ⚠️ File operations (download vs Tauri fs)                      │
│  ⚠️ Offline sync (navigator.onLine vs Tauri events)             │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure for Portability

```
apps/
├── web/                          # Web application (Vite)
│   ├── src/
│   │   ├── features/pos/         # ✓ Shared POS components
│   │   ├── platform/             # ⚠️ Web-specific adapters
│   │   │   ├── api/              # HTTP client
│   │   │   ├── storage/          # localStorage wrapper
│   │   │   ├── printer/          # Browser print dialog
│   │   │   └── index.ts          # Platform exports
│   │   └── main.tsx
│   └── package.json
│
└── pos-desktop/                  # Tauri 2 application (NEW)
    ├── src/
    │   ├── features/pos/         # → Symlink to apps/web/src/features/pos
    │   ├── platform/             # ⚠️ Tauri-specific adapters
    │   │   ├── api/              # Tauri commands
    │   │   ├── storage/          # Tauri store plugin
    │   │   ├── printer/          # Native printer via Tauri
    │   │   └── index.ts          # Platform exports
    │   └── main.tsx
    ├── src-tauri/                # Rust backend
    │   ├── src/
    │   │   ├── commands/         # Tauri commands
    │   │   ├── printer.rs        # Native printer integration
    │   │   └── main.rs
    │   └── Cargo.toml
    └── package.json
```

---

## 2. Platform Abstraction Layer

### Creating Platform-Agnostic Components

**Rule:** Components should NEVER import platform-specific code directly.

**❌ BAD (Direct platform dependency):**

```typescript
// DON'T DO THIS!
import { apiPost } from '@/lib/api' // Web-specific

export function CheckoutButton() {
  const handleClick = async () => {
    await apiPost('/pos/receipts', data) // ❌ Hard-coded to web API
  }
}
```

**✅ GOOD (Platform abstraction):**

```typescript
// DO THIS!
import { usePlatform } from '@/platform' // Injected at runtime

export function CheckoutButton() {
  const { api } = usePlatform() // Platform-agnostic

  const handleClick = async () => {
    await api.createReceipt(data) // ✓ Works on web and desktop
  }
}
```

### Platform Interface Definition

```typescript
// apps/web/src/platform/types.ts

export interface IPlatformAPI {
  // Receipt operations
  createReceipt(data: CreateReceiptRequest): Promise<Receipt>
  getReceipts(filters: ReceiptFilters): Promise<Receipt[]>
  getReceiptById(id: string): Promise<Receipt>

  // Shift operations
  openShift(data: OpenShiftRequest): Promise<Shift>
  closeShift(shiftId: string, data: CloseShiftRequest): Promise<Shift>
  getCurrentShift(terminalId: string): Promise<Shift | null>

  // Product operations
  searchProducts(query: string, filters: ProductFilters): Promise<Product[]>
  getProductById(id: string): Promise<Product>

  // ... all API operations
}

export interface IPlatformStorage {
  get<T>(key: string): Promise<T | null>
  set<T>(key: string, value: T): Promise<void>
  remove(key: string): Promise<void>
  clear(): Promise<void>
}

export interface IPlatformPrinter {
  print(receipt: Receipt): Promise<void>
  printReport(report: XReport | ZReport): Promise<void>
  isAvailable(): Promise<boolean>
  getDefaultPrinter(): Promise<string>
}

export interface IPlatformFileSystem {
  saveFile(filename: string, content: string | Blob): Promise<void>
  openFile(filters?: FileFilter[]): Promise<File | null>
  exportPDF(receipt: Receipt): Promise<void>
}

export interface IPlatformSync {
  isOnline(): boolean
  onStatusChange(callback: (online: boolean) => void): () => void
  syncNow(): Promise<void>
}

export interface IPlatform {
  api: IPlatformAPI
  storage: IPlatformStorage
  printer: IPlatformPrinter
  fs: IPlatformFileSystem
  sync: IPlatformSync
  type: 'web' | 'desktop'
}
```

### Web Platform Implementation

```typescript
// apps/web/src/platform/web/index.ts

import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api'
import type { IPlatform, IPlatformAPI, IPlatformStorage, IPlatformPrinter } from '../types'

class WebAPI implements IPlatformAPI {
  async createReceipt(data: CreateReceiptRequest): Promise<Receipt> {
    return apiPost<Receipt>('/pos/receipts', data)
  }

  async getReceipts(filters: ReceiptFilters): Promise<Receipt[]> {
    return apiGet<Receipt[]>('/pos/receipts', { params: filters })
  }

  async openShift(data: OpenShiftRequest): Promise<Shift> {
    return apiPost<Shift>('/pos/shifts/open', data)
  }

  // ... all other API methods
}

class WebStorage implements IPlatformStorage {
  async get<T>(key: string): Promise<T | null> {
    const value = localStorage.getItem(key)
    return value ? JSON.parse(value) : null
  }

  async set<T>(key: string, value: T): Promise<void> {
    localStorage.setItem(key, JSON.stringify(value))
  }

  async remove(key: string): Promise<void> {
    localStorage.removeItem(key)
  }

  async clear(): Promise<void> {
    localStorage.clear()
  }
}

class WebPrinter implements IPlatformPrinter {
  async print(receipt: Receipt): Promise<void> {
    // Use browser print dialog
    window.print()
  }

  async printReport(report: XReport | ZReport): Promise<void> {
    window.print()
  }

  async isAvailable(): Promise<boolean> {
    return true // Browser print always available
  }

  async getDefaultPrinter(): Promise<string> {
    return 'Browser Print'
  }
}

class WebFileSystem implements IPlatformFileSystem {
  async saveFile(filename: string, content: string | Blob): Promise<void> {
    const blob = typeof content === 'string'
      ? new Blob([content], { type: 'text/plain' })
      : content

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  }

  async openFile(filters?: FileFilter[]): Promise<File | null> {
    return new Promise((resolve) => {
      const input = document.createElement('input')
      input.type = 'file'
      if (filters) {
        input.accept = filters.map(f => f.extensions).join(',')
      }
      input.onchange = (e) => {
        const file = (e.target as HTMLInputElement).files?.[0]
        resolve(file || null)
      }
      input.click()
    })
  }

  async exportPDF(receipt: Receipt): Promise<void> {
    // Generate PDF and download
    const pdfBlob = await generateReceiptPDF(receipt)
    await this.saveFile(`receipt-${receipt.receipt_number}.pdf`, pdfBlob)
  }
}

class WebSync implements IPlatformSync {
  isOnline(): boolean {
    return navigator.onLine
  }

  onStatusChange(callback: (online: boolean) => void): () => void {
    const handler = () => callback(navigator.onLine)
    window.addEventListener('online', handler)
    window.addEventListener('offline', handler)

    return () => {
      window.removeEventListener('online', handler)
      window.removeEventListener('offline', handler)
    }
  }

  async syncNow(): Promise<void> {
    // Not applicable for web (always online)
  }
}

export const webPlatform: IPlatform = {
  api: new WebAPI(),
  storage: new WebStorage(),
  printer: new WebPrinter(),
  fs: new WebFileSystem(),
  sync: new WebSync(),
  type: 'web'
}
```

### Tauri Platform Implementation

```typescript
// apps/pos-desktop/src/platform/tauri/index.ts

import { invoke } from '@tauri-apps/api/core'
import { Store } from '@tauri-apps/plugin-store'
import type { IPlatform, IPlatformAPI, IPlatformStorage, IPlatformPrinter } from '../types'

class TauriAPI implements IPlatformAPI {
  async createReceipt(data: CreateReceiptRequest): Promise<Receipt> {
    // Call Tauri command instead of HTTP
    return invoke<Receipt>('create_receipt', { data })
  }

  async getReceipts(filters: ReceiptFilters): Promise<Receipt[]> {
    return invoke<Receipt[]>('get_receipts', { filters })
  }

  async openShift(data: OpenShiftRequest): Promise<Shift> {
    return invoke<Shift>('open_shift', { data })
  }

  // ... all other API methods
}

class TauriStorage implements IPlatformStorage {
  private store = new Store('pos-store.json')

  async get<T>(key: string): Promise<T | null> {
    return await this.store.get<T>(key)
  }

  async set<T>(key: string, value: T): Promise<void> {
    await this.store.set(key, value)
    await this.store.save()
  }

  async remove(key: string): Promise<void> {
    await this.store.delete(key)
    await this.store.save()
  }

  async clear(): Promise<void> {
    await this.store.clear()
    await this.store.save()
  }
}

class TauriPrinter implements IPlatformPrinter {
  async print(receipt: Receipt): Promise<void> {
    // Call Tauri command to print via native printer
    await invoke('print_receipt', { receipt })
  }

  async printReport(report: XReport | ZReport): Promise<void> {
    await invoke('print_report', { report })
  }

  async isAvailable(): Promise<boolean> {
    return invoke<boolean>('is_printer_available')
  }

  async getDefaultPrinter(): Promise<string> {
    return invoke<string>('get_default_printer')
  }
}

class TauriFileSystem implements IPlatformFileSystem {
  async saveFile(filename: string, content: string | Blob): Promise<void> {
    const contentStr = typeof content === 'string'
      ? content
      : await content.text()

    await invoke('save_file', { filename, content: contentStr })
  }

  async openFile(filters?: FileFilter[]): Promise<File | null> {
    const path = await invoke<string | null>('open_file_dialog', { filters })
    if (!path) return null

    const content = await invoke<string>('read_file', { path })
    return new File([content], path.split('/').pop()!)
  }

  async exportPDF(receipt: Receipt): Promise<void> {
    await invoke('export_receipt_pdf', { receipt })
  }
}

class TauriSync implements IPlatformSync {
  private listeners: Array<(online: boolean) => void> = []

  constructor() {
    // Listen to Tauri events for network status
    listen<boolean>('network-status-changed', (event) => {
      this.listeners.forEach(cb => cb(event.payload))
    })
  }

  isOnline(): boolean {
    // Can cache this or query Rust
    return true // TODO: Implement
  }

  onStatusChange(callback: (online: boolean) => void): () => void {
    this.listeners.push(callback)
    return () => {
      this.listeners = this.listeners.filter(cb => cb !== callback)
    }
  }

  async syncNow(): Promise<void> {
    await invoke('sync_receipts')
  }
}

export const tauriPlatform: IPlatform = {
  api: new TauriAPI(),
  storage: new TauriStorage(),
  printer: new TauriPrinter(),
  fs: new TauriFileSystem(),
  sync: new TauriSync(),
  type: 'desktop'
}
```

### Platform Provider (React Context)

```typescript
// apps/web/src/platform/PlatformProvider.tsx

import React, { createContext, useContext } from 'react'
import type { IPlatform } from './types'
import { webPlatform } from './web'
// import { tauriPlatform } from './tauri' // For desktop app

const PlatformContext = createContext<IPlatform | null>(null)

interface PlatformProviderProps {
  platform: IPlatform
  children: React.ReactNode
}

export function PlatformProvider({ platform, children }: PlatformProviderProps) {
  return (
    <PlatformContext.Provider value={platform}>
      {children}
    </PlatformContext.Provider>
  )
}

export function usePlatform(): IPlatform {
  const platform = useContext(PlatformContext)
  if (!platform) {
    throw new Error('usePlatform must be used within PlatformProvider')
  }
  return platform
}

// Convenience hooks
export function usePlatformAPI() {
  return usePlatform().api
}

export function usePlatformStorage() {
  return usePlatform().storage
}

export function usePlatformPrinter() {
  return usePlatform().printer
}

export function usePlatformFS() {
  return usePlatform().fs
}

export function usePlatformSync() {
  return usePlatform().sync
}
```

### Usage in Components

```typescript
// apps/web/src/features/pos/organisms/CheckoutModal/CheckoutModal.tsx

import { usePlatformAPI, usePlatformPrinter } from '@/platform'

export function CheckoutModal() {
  const api = usePlatformAPI() // Platform-agnostic!
  const printer = usePlatformPrinter()

  const handleCheckout = async (data: PaymentData) => {
    // Works on both web and desktop
    const receipt = await api.createReceipt({
      shift_id: currentShift.id,
      lines: cart.items,
      payments: data.payments
    })

    // Print receipt (uses browser print on web, native on desktop)
    if (data.printReceipt) {
      await printer.print(receipt)
    }

    toast.success('Sale completed!')
    navigate('/pos')
  }

  return <CheckoutForm onSubmit={handleCheckout} />
}
```

---

## 3. Code Organization for Portability

### Shared Components (100% Portable)

```
apps/web/src/features/pos/
├── atoms/                    # ✓ Pure React, zero platform deps
│   ├── POSButton.tsx
│   ├── MoneyInput.tsx
│   └── StockBadge.tsx
│
├── molecules/                # ✓ Pure React, zero platform deps
│   ├── ProductCard/
│   ├── CartLineItem/
│   └── CustomerDisplayCard/
│
├── organisms/                # ✓ Uses platform hooks, still portable
│   ├── ProductGrid/
│   │   ├── ProductGrid.tsx           # Uses usePlatformAPI
│   │   ├── useProductGrid.ts         # Uses usePlatformAPI
│   │   └── index.ts
│   ├── CheckoutModal/
│   │   ├── CheckoutModal.tsx         # Uses usePlatformAPI + printer
│   │   └── index.ts
│   └── Calculator/
│       └── Calculator.tsx            # Pure React, no platform deps
│
├── templates/                # ✓ Layout only, portable
│   └── POSLayout/
│
├── pages/                    # ✓ Orchestration, uses platform hooks
│   ├── ShiftDashboardPage/
│   └── POSPage/
│
├── hooks/                    # ✓ Mostly portable (uses platform hooks)
│   ├── useCart.ts                    # Pure client state (Zustand)
│   ├── useCheckout.ts                # Uses usePlatformAPI
│   ├── useCurrentShift.ts            # Uses usePlatformAPI
│   └── useKeyboardShortcuts.ts       # Pure React
│
├── utils/                    # ✓ 100% portable (pure functions)
│   ├── calculations.ts
│   ├── formatters.ts
│   └── validators.ts
│
└── types/                    # ✓ 100% portable (just types)
    ├── cart.ts
    ├── shift.ts
    └── receipt.ts
```

### Migration Script (Symlink for Tauri App)

```bash
#!/bin/bash
# scripts/setup-tauri-app.sh

# Create Tauri app directory
mkdir -p apps/pos-desktop/src

# Symlink shared POS code
ln -s ../../web/src/features/pos apps/pos-desktop/src/features

# Copy Tauri-specific platform adapters
cp -r apps/web/src/platform/tauri apps/pos-desktop/src/platform

# Copy types (shared)
ln -s ../../web/src/types apps/pos-desktop/src/types

echo "✓ Tauri app structure ready"
echo "✓ Shared POS code symlinked"
echo "✓ Platform adapters copied"
```

---

## 4. Tauri-Specific Features

### Rust Commands for POS Operations

```rust
// apps/pos-desktop/src-tauri/src/commands/shift.rs

use tauri::State;
use serde::{Deserialize, Serialize};

#[derive(Debug, Serialize, Deserialize)]
pub struct OpenShiftRequest {
    terminal_id: String,
    opening_cash: String,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct Shift {
    id: String,
    shift_number: i32,
    opening_cash: String,
    status: String,
    opened_at: String,
}

#[tauri::command]
pub async fn open_shift(
    data: OpenShiftRequest,
    db: State<'_, Database>,
) -> Result<Shift, String> {
    // Call backend API via reqwest
    let client = reqwest::Client::new();
    let response = client
        .post("http://localhost:8000/api/v1/pos/shifts/open")
        .json(&data)
        .send()
        .await
        .map_err(|e| e.to_string())?;

    let shift: Shift = response
        .json()
        .await
        .map_err(|e| e.to_string())?;

    // Store in local SQLite for offline access
    db.insert_shift(&shift)
        .await
        .map_err(|e| e.to_string())?;

    Ok(shift)
}

#[tauri::command]
pub async fn get_current_shift(
    terminal_id: String,
    db: State<'_, Database>,
) -> Result<Option<Shift>, String> {
    // Try to fetch from server first
    match fetch_current_shift_from_server(&terminal_id).await {
        Ok(shift) => Ok(Some(shift)),
        Err(_) => {
            // Fallback to local database if offline
            db.get_current_shift(&terminal_id)
                .await
                .map_err(|e| e.to_string())
        }
    }
}
```

### Native Printer Integration

```rust
// apps/pos-desktop/src-tauri/src/printer.rs

use tauri::State;
use serde::{Deserialize, Serialize};

#[derive(Debug, Serialize, Deserialize)]
pub struct Receipt {
    receipt_number: String,
    total_amount: String,
    lines: Vec<ReceiptLine>,
    // ... other fields
}

#[tauri::command]
pub async fn print_receipt(receipt: Receipt) -> Result<(), String> {
    // Use OS-specific printer API
    #[cfg(target_os = "windows")]
    {
        windows_print(&receipt).map_err(|e| e.to_string())
    }

    #[cfg(target_os = "macos")]
    {
        macos_print(&receipt).map_err(|e| e.to_string())
    }

    #[cfg(target_os = "linux")]
    {
        linux_print(&receipt).map_err(|e| e.to_string())
    }
}

#[cfg(target_os = "windows")]
fn windows_print(receipt: &Receipt) -> Result<(), Box<dyn std::error::Error>> {
    // Use Windows Print API or ESC/POS commands via USB
    use escpos::printer::Printer;
    use escpos::usb::UsbDevice;

    let device = UsbDevice::new(0x04b8, 0x0e15)?; // Epson printer
    let mut printer = Printer::new(device);

    printer.init()?;
    printer.text("RECEIPT")?;
    printer.feed(1)?;
    printer.text(&format!("No: {}", receipt.receipt_number))?;
    printer.text(&format!("Total: {}", receipt.total_amount))?;
    // ... format receipt
    printer.cut()?;

    Ok(())
}

#[tauri::command]
pub async fn is_printer_available() -> Result<bool, String> {
    // Check if printer is connected
    #[cfg(target_os = "windows")]
    {
        use escpos::usb::UsbDevice;
        Ok(UsbDevice::new(0x04b8, 0x0e15).is_ok())
    }

    #[cfg(not(target_os = "windows"))]
    {
        Ok(false) // TODO: Implement for other platforms
    }
}

#[tauri::command]
pub async fn get_default_printer() -> Result<String, String> {
    // Get system default printer name
    Ok("Epson TM-T20II".to_string()) // TODO: Query system
}
```

### Local Database (SQLite)

```rust
// apps/pos-desktop/src-tauri/src/database.rs

use rusqlite::{Connection, Result};
use serde::{Deserialize, Serialize};

pub struct Database {
    conn: Connection,
}

impl Database {
    pub fn new(path: &str) -> Result<Self> {
        let conn = Connection::open(path)?;

        // Create tables
        conn.execute(
            "CREATE TABLE IF NOT EXISTS shifts (
                id TEXT PRIMARY KEY,
                terminal_id TEXT NOT NULL,
                shift_number INTEGER NOT NULL,
                opening_cash TEXT NOT NULL,
                status TEXT NOT NULL,
                opened_at TEXT NOT NULL
            )",
            [],
        )?;

        conn.execute(
            "CREATE TABLE IF NOT EXISTS receipts (
                id TEXT PRIMARY KEY,
                shift_id TEXT NOT NULL,
                receipt_number TEXT NOT NULL,
                total_amount TEXT NOT NULL,
                created_at TEXT NOT NULL,
                synced INTEGER DEFAULT 0
            )",
            [],
        )?;

        Ok(Self { conn })
    }

    pub fn insert_shift(&self, shift: &Shift) -> Result<()> {
        self.conn.execute(
            "INSERT INTO shifts (id, terminal_id, shift_number, opening_cash, status, opened_at)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
            [
                &shift.id,
                &shift.terminal_id,
                &shift.shift_number.to_string(),
                &shift.opening_cash,
                &shift.status,
                &shift.opened_at,
            ],
        )?;
        Ok(())
    }

    pub fn get_current_shift(&self, terminal_id: &str) -> Result<Option<Shift>> {
        let mut stmt = self.conn.prepare(
            "SELECT id, terminal_id, shift_number, opening_cash, status, opened_at
             FROM shifts
             WHERE terminal_id = ?1 AND status = 'OPEN'
             LIMIT 1"
        )?;

        let shift = stmt.query_row([terminal_id], |row| {
            Ok(Shift {
                id: row.get(0)?,
                terminal_id: row.get(1)?,
                shift_number: row.get(2)?,
                opening_cash: row.get(3)?,
                status: row.get(4)?,
                opened_at: row.get(5)?,
            })
        }).optional()?;

        Ok(shift)
    }

    pub fn get_unsynced_receipts(&self) -> Result<Vec<Receipt>> {
        let mut stmt = self.conn.prepare(
            "SELECT id, shift_id, receipt_number, total_amount, created_at
             FROM receipts
             WHERE synced = 0"
        )?;

        let receipts = stmt.query_map([], |row| {
            Ok(Receipt {
                id: row.get(0)?,
                shift_id: row.get(1)?,
                receipt_number: row.get(2)?,
                total_amount: row.get(3)?,
                created_at: row.get(4)?,
            })
        })?
        .collect::<Result<Vec<_>>>()?;

        Ok(receipts)
    }

    pub fn mark_receipt_synced(&self, receipt_id: &str) -> Result<()> {
        self.conn.execute(
            "UPDATE receipts SET synced = 1 WHERE id = ?1",
            [receipt_id],
        )?;
        Ok(())
    }
}
```

### Offline Sync Strategy

```rust
// apps/pos-desktop/src-tauri/src/sync.rs

use tauri::Emitter;

pub struct SyncService {
    db: Database,
    api_url: String,
}

impl SyncService {
    pub async fn sync_receipts(&self, app_handle: tauri::AppHandle) -> Result<(), String> {
        // Get unsynced receipts from local database
        let receipts = self.db
            .get_unsynced_receipts()
            .map_err(|e| e.to_string())?;

        if receipts.is_empty() {
            return Ok(());
        }

        // Try to sync each receipt
        for receipt in receipts {
            match self.sync_single_receipt(&receipt).await {
                Ok(_) => {
                    self.db
                        .mark_receipt_synced(&receipt.id)
                        .map_err(|e| e.to_string())?;

                    // Emit event to frontend
                    app_handle
                        .emit("receipt-synced", &receipt.id)
                        .map_err(|e| e.to_string())?;
                }
                Err(e) => {
                    eprintln!("Failed to sync receipt {}: {}", receipt.id, e);
                    // Continue with next receipt
                }
            }
        }

        Ok(())
    }

    async fn sync_single_receipt(&self, receipt: &Receipt) -> Result<(), Box<dyn std::error::Error>> {
        let client = reqwest::Client::new();
        let response = client
            .post(&format!("{}/api/v1/pos/receipts/sync", self.api_url))
            .json(receipt)
            .send()
            .await?;

        if !response.status().is_success() {
            return Err(format!("Server returned {}", response.status()).into());
        }

        Ok(())
    }

    pub async fn start_background_sync(&self, app_handle: tauri::AppHandle) {
        let sync_service = self.clone();

        tokio::spawn(async move {
            loop {
                // Sync every 5 minutes
                tokio::time::sleep(tokio::time::Duration::from_secs(300)).await;

                if let Err(e) = sync_service.sync_receipts(app_handle.clone()).await {
                    eprintln!("Background sync failed: {}", e);
                }
            }
        });
    }
}
```

---

## 5. Migration Checklist

### When Ready to Create Tauri App

- [ ] **Step 1:** Run setup script
  ```bash
  ./scripts/setup-tauri-app.sh
  ```

- [ ] **Step 2:** Initialize Tauri 2 app
  ```bash
  cd apps/pos-desktop
  pnpm create tauri-app
  ```

- [ ] **Step 3:** Copy Tauri platform adapters
  ```bash
  cp -r ../web/src/platform/tauri src/platform/
  ```

- [ ] **Step 4:** Update main.tsx to use tauriPlatform
  ```typescript
  // apps/pos-desktop/src/main.tsx
  import { tauriPlatform } from './platform/tauri'

  root.render(
    <PlatformProvider platform={tauriPlatform}>
      <App />
    </PlatformProvider>
  )
  ```

- [ ] **Step 5:** Implement Rust commands
  - [ ] Shift commands (open, close, get current)
  - [ ] Receipt commands (create, list, get)
  - [ ] Product commands (search, get)
  - [ ] Sync commands (sync receipts, check status)
  - [ ] Printer commands (print, is available)

- [ ] **Step 6:** Implement local SQLite database
  - [ ] Create database module
  - [ ] Define schema (shifts, receipts, products)
  - [ ] Implement CRUD operations

- [ ] **Step 7:** Test offline functionality
  - [ ] Create receipt while offline
  - [ ] Verify stored in local database
  - [ ] Reconnect and verify sync

- [ ] **Step 8:** Test native printer
  - [ ] Connect ESC/POS printer
  - [ ] Print test receipt
  - [ ] Verify formatting

- [ ] **Step 9:** Build and test Windows executable
  ```bash
  pnpm tauri build
  ```

### Verification Tests

- [ ] Web app still works (no regressions)
- [ ] Desktop app launches successfully
- [ ] Offline receipt creation works
- [ ] Sync to server works when online
- [ ] Native printer works
- [ ] All UI components render correctly
- [ ] Keyboard shortcuts work
- [ ] Calculator works
- [ ] All business logic behaves identically

---

## Summary

**Key Principles for Tauri Compatibility:**

1. ✅ **Pure React components** - No platform-specific code
2. ✅ **Platform abstraction layer** - Use `usePlatform()` hooks
3. ✅ **Interface-based design** - Define `IPlatform` interface
4. ✅ **Shared code via symlinks** - One codebase, two platforms
5. ✅ **Offline-first architecture** - Works without network
6. ✅ **Local SQLite for desktop** - Persistent storage
7. ✅ **Native printer integration** - ESC/POS via Rust
8. ✅ **Background sync** - Auto-sync when online

**Migration Effort:** ~2-3 weeks after web app is complete and tested.

---

*Document End*
