# Windows USB Printer Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Windows Winspool raw printing so USB thermal printers (Rongta, Epson, etc.) that register as USB Printer Class devices are discoverable and usable on Windows.

**Architecture:** Add a `Windows` variant to `PrinterConnectionType` and a new `windows.rs` transport module (behind `#[cfg(target_os = "windows")]`) that uses Win32 `OpenPrinterW`/`WritePrinter`/`ClosePrinter` to send raw ESC/POS bytes. The existing ESC/POS builder and receipt template are unchanged — only the transport layer is new.

**Tech Stack:** Rust `windows` crate (v0.58+, `Win32_Graphics_Printing` + `Win32_Foundation`), Tauri 2, React/TypeScript frontend.

**Spec:** `docs/superpowers/specs/2026-03-22-windows-usb-printer-support.md`

---

### Task 1: Add `Windows` variant to `PrinterConnectionType` and `PrintError`

**Files:**
- Modify: `apps/pos/src-tauri/src/printing/mod.rs`

This task adds the enum variants on all platforms. The `Windows` connection type must be unconditional (not behind `#[cfg]`) because persisted frontend configs may reference it and serde needs to deserialize it.

- [ ] **Step 1: Add `Windows` variant to `PrinterConnectionType`**

In `apps/pos/src-tauri/src/printing/mod.rs`, add `Windows` to the enum:

```rust
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum PrinterConnectionType {
    Usb,
    Network,
    Windows,
}
```

- [ ] **Step 2: Add `Windows` variant to `PrintError`**

In the same file, add the error variant (consistent with `Usb(String)` and `Network(String)`):

```rust
#[derive(Debug, thiserror::Error)]
pub enum PrintError {
    #[error("USB error: {0}")]
    Usb(String),
    #[error("Network error: {0}")]
    Network(String),
    #[error("Windows printing error: {0}")]
    Windows(String),
    #[error("Template error: {0}")]
    Template(String),
    #[error("No printer configured")]
    NoPrinter,
    #[error("Printer not found: {0}")]
    PrinterNotFound(String),
}
```

- [ ] **Step 3: Add `Windows` arm to `send_to_printer`**

Update the `send_to_printer` function to route `Windows` type. On Windows, it calls `windows::send()`. On other platforms, it returns an error:

```rust
pub async fn send_to_printer(
    connection_type: &PrinterConnectionType,
    address: &str,
    data: &[u8],
) -> Result<(), PrintError> {
    match connection_type {
        PrinterConnectionType::Usb => usb::send(address, data),
        PrinterConnectionType::Network => network::send(address, data).await,
        #[cfg(target_os = "windows")]
        PrinterConnectionType::Windows => windows::send(address, data).await,
        #[cfg(not(target_os = "windows"))]
        PrinterConnectionType::Windows => Err(PrintError::Windows(
            "Windows printing is only available on Windows".to_string(),
        )),
    }
}
```

- [ ] **Step 4: Add conditional module declaration**

At the top of `mod.rs`, add:

```rust
#[cfg(target_os = "windows")]
pub mod windows;
```

- [ ] **Step 5: Verify it compiles**

Run: `cd apps/pos/src-tauri && cargo check`

Note: This will fail until Task 2 creates `windows.rs` (on Windows). On macOS/Linux it should compile since the module is `#[cfg]`-gated. If building on macOS/Linux, verify compilation passes. If on Windows, proceed to Task 2 before checking.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src-tauri/src/printing/mod.rs
git commit -m "feat(printing): add Windows connection type and error variant"
```

---

### Task 2: Create `windows.rs` — Winspool discover and send

**Files:**
- Create: `apps/pos/src-tauri/src/printing/windows.rs`
- Modify: `apps/pos/src-tauri/Cargo.toml`

This is the core implementation. Uses Win32 Winspool API to enumerate installed printers and send raw ESC/POS bytes.

> **API Verification Note:** The code below uses `.as_bool()` checks on Win32 return values. The exact function signatures in the `windows` crate may vary by version — some functions return `BOOL` (use `.as_bool()`), others return `windows::core::Result<()>` (use `?`). When compiling on Windows, if the compiler reports type mismatches, adapt the error checking pattern to match the actual return types. This code is written for v0.58 but should be verified at compile time. Also, `tokio::task::spawn_blocking` requires the multi-thread runtime — Tauri 2 uses this by default.

- [ ] **Step 1: Add `windows` crate dependency to Cargo.toml**

Add the target-gated dependency at the end of `Cargo.toml`:

```toml
[target.'cfg(windows)'.dependencies]
windows = { version = "0.58", features = ["Win32_Graphics_Printing", "Win32_Foundation"] }
```

- [ ] **Step 2: Create `windows.rs` with RAII handle wrapper**

Create `apps/pos/src-tauri/src/printing/windows.rs`:

```rust
//! Windows Winspool raw printing support.
//!
//! Uses the Win32 Winspool API to discover installed printers and send
//! raw ESC/POS bytes. This is the primary printing path on Windows since
//! most USB thermal printers register as USB Printer Class devices (not
//! serial COM ports).

use std::ffi::OsStr;
use std::os::windows::ffi::OsStrExt;

use windows::core::PCWSTR;
use windows::Win32::Foundation::HANDLE;
use windows::Win32::Graphics::Printing::{
    ClosePrinter, EndDocPrinter, EndPagePrinter, EnumPrintersW, OpenPrinterW,
    StartDocPrinterW, StartPagePrinter, WritePrinter, DOC_INFO_1W,
    PRINTER_ENUM_CONNECTIONS, PRINTER_ENUM_LOCAL, PRINTER_INFO_2W,
};

use super::PrintError;

/// RAII wrapper for a Win32 printer handle.
/// Calls `ClosePrinter` on drop to prevent handle leaks.
struct PrinterHandle(HANDLE);

impl Drop for PrinterHandle {
    fn drop(&mut self) {
        if !self.0.is_invalid() {
            unsafe {
                let _ = ClosePrinter(self.0);
            }
        }
    }
}

/// Convert a Rust string to a null-terminated wide string (UTF-16).
fn to_wide(s: &str) -> Vec<u16> {
    OsStr::new(s).encode_wide().chain(std::iter::once(0)).collect()
}

/// Virtual/non-physical printer driver names to filter out during discovery.
const VIRTUAL_PRINTER_KEYWORDS: &[&str] = &[
    "pdf", "xps", "fax", "onenote", "microsoft print",
    "send to", "snagit", "cutepdf", "foxit", "bullzip",
];

/// Check if a printer name or driver looks like a virtual/non-physical printer.
fn is_virtual_printer(name: &str, driver: &str) -> bool {
    let name_lower = name.to_lowercase();
    let driver_lower = driver.to_lowercase();
    VIRTUAL_PRINTER_KEYWORDS
        .iter()
        .any(|kw| name_lower.contains(kw) || driver_lower.contains(kw))
}
```

- [ ] **Step 3: Implement `discover()` function**

Add to `windows.rs`:

```rust
use super::{PrinterConnectionType, PrinterInfo};

/// Discover printers installed in Windows via EnumPrintersW.
///
/// Returns physical printers only (filters out PDF, XPS, Fax, etc.).
pub fn discover() -> Result<Vec<PrinterInfo>, PrintError> {
    let mut printers = Vec::new();
    let flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;
    let mut bytes_needed: u32 = 0;
    let mut count: u32 = 0;

    // First call: get required buffer size
    unsafe {
        let _ = EnumPrintersW(
            flags,
            None,
            2, // PRINTER_INFO_2 level
            None,
            0,
            &mut bytes_needed,
            &mut count,
        );
    }

    if bytes_needed == 0 {
        return Ok(printers);
    }

    // Allocate buffer and enumerate
    let mut buffer = vec![0u8; bytes_needed as usize];
    let success = unsafe {
        EnumPrintersW(
            flags,
            None,
            2,
            Some(&mut buffer),
            bytes_needed,
            &mut bytes_needed,
            &mut count,
        )
    };

    if !success.as_bool() {
        return Err(PrintError::Windows(
            "Failed to enumerate Windows printers".to_string(),
        ));
    }

    let printer_infos = unsafe {
        std::slice::from_raw_parts(
            buffer.as_ptr() as *const PRINTER_INFO_2W,
            count as usize,
        )
    };

    for info in printer_infos {
        let name = unsafe { info.pPrinterName.to_string() }.unwrap_or_default();
        let driver = unsafe { info.pDriverName.to_string() }.unwrap_or_default();

        if name.is_empty() || is_virtual_printer(&name, &driver) {
            continue;
        }

        printers.push(PrinterInfo {
            id: format!("win:{}", name),
            name: name.clone(),
            connection_type: PrinterConnectionType::Windows,
            address: name,
        });
    }

    log::info!("Windows printer discovery found {} printers", printers.len());
    Ok(printers)
}
```

- [ ] **Step 4: Implement `send()` function**

Add to `windows.rs`:

```rust
/// Send raw ESC/POS bytes to a Windows printer via Winspool raw printing.
///
/// Opens the printer, starts a RAW document (bypassing GDI rendering),
/// writes the bytes, and closes. Uses RAII handle wrapper and is wrapped
/// in `spawn_blocking` for async compatibility with a 10s timeout.
pub async fn send(printer_name: &str, data: &[u8]) -> Result<(), PrintError> {
    let name = printer_name.to_string();
    let data = data.to_vec();

    let result = tokio::time::timeout(
        std::time::Duration::from_secs(10),
        tokio::task::spawn_blocking(move || send_blocking(&name, &data)),
    )
    .await;

    match result {
        Ok(Ok(ok)) => ok,
        Ok(Err(e)) => Err(PrintError::Windows(format!("Print task failed: {}", e))),
        Err(_) => Err(PrintError::Windows(
            "Print job timed out. The printer may be offline or the spooler may be stuck."
                .to_string(),
        )),
    }
}

/// Blocking implementation of Winspool raw printing.
fn send_blocking(printer_name: &str, data: &[u8]) -> Result<(), PrintError> {
    let wide_name = to_wide(printer_name);
    let mut handle = HANDLE::default();

    // Open the printer
    let success = unsafe {
        OpenPrinterW(
            PCWSTR(wide_name.as_ptr()),
            &mut handle,
            None,
        )
    };

    if !success.as_bool() {
        return Err(PrintError::Windows(format!(
            "Cannot open printer '{}'. Check Windows printer settings.",
            printer_name
        )));
    }

    let _handle = PrinterHandle(handle); // RAII — ClosePrinter on drop

    // Start a RAW document (bypass GDI, send bytes as-is)
    let doc_name = to_wide("POS Receipt");
    let datatype = to_wide("RAW");

    let doc_info = DOC_INFO_1W {
        pDocName: PCWSTR(doc_name.as_ptr()),
        pOutputFile: PCWSTR::null(),
        pDatatype: PCWSTR(datatype.as_ptr()),
    };

    let job_id = unsafe { StartDocPrinterW(handle, 1, &doc_info as *const _ as *const _) };
    if job_id == 0 {
        return Err(PrintError::Windows(
            "Could not start print job. The printer may be busy or offline.".to_string(),
        ));
    }

    // Start page
    let success = unsafe { StartPagePrinter(handle) };
    if !success.as_bool() {
        unsafe { EndDocPrinter(handle) };
        return Err(PrintError::Windows(
            "Could not start page. The printer may be busy.".to_string(),
        ));
    }

    // Write data
    let mut bytes_written: u32 = 0;
    let success = unsafe {
        WritePrinter(
            handle,
            data.as_ptr() as *const _,
            data.len() as u32,
            &mut bytes_written,
        )
    };

    if !success.as_bool() {
        unsafe {
            EndPagePrinter(handle);
            EndDocPrinter(handle);
        }
        return Err(PrintError::Windows(
            "Failed to send data to printer. Check connection.".to_string(),
        ));
    }

    // End page and document
    unsafe {
        EndPagePrinter(handle);
        EndDocPrinter(handle);
    }

    log::info!(
        "Sent {} bytes to Windows printer '{}'",
        bytes_written,
        printer_name
    );

    Ok(())
}
```

- [ ] **Step 5: Verify it compiles**

Run: `cd apps/pos/src-tauri && cargo check`

On macOS/Linux this will compile (module is `#[cfg]`-gated). On Windows, verify the `windows` crate resolves and the Win32 types are correct.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src-tauri/Cargo.toml apps/pos/src-tauri/src/printing/windows.rs
git commit -m "feat(printing): add Windows Winspool raw printing module"
```

---

### Task 3: Update `discover_printers` command to include Windows printers

**Files:**
- Modify: `apps/pos/src-tauri/src/commands/printing.rs`

- [ ] **Step 1: Add Windows discovery to `discover_printers`**

In `apps/pos/src-tauri/src/commands/printing.rs`, add a `#[cfg]` block inside the `discover_printers` function to call `windows::discover()`:

```rust
#[tauri::command]
pub async fn discover_printers(subnet_prefix: Option<String>) -> Result<Vec<PrinterInfo>, PrintError> {
    let mut printers = Vec::new();

    // Windows printer discovery (Winspool API)
    #[cfg(target_os = "windows")]
    {
        match printing::windows::discover() {
            Ok(win_printers) => printers.extend(win_printers),
            Err(e) => log::warn!("Windows printer discovery failed: {}", e),
        }
    }

    // USB discovery (synchronous, quick)
    let usb_printers = printing::usb::discover();
    printers.extend(usb_printers);

    // Network discovery (async, scans subnet)
    let prefix = subnet_prefix.unwrap_or_else(|| "192.168.1.".to_string());
    let net_printers = printing::network::discover(&prefix).await;
    printers.extend(net_printers);

    log::info!("Discovered {} printers", printers.len());
    Ok(printers)
}
```

- [ ] **Step 2: Verify it compiles**

Run: `cd apps/pos/src-tauri && cargo check`

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src-tauri/src/commands/printing.rs
git commit -m "feat(printing): include Windows printers in discovery"
```

---

### Task 4: Update frontend types and Settings UI

**Files:**
- Modify: `apps/pos/src/lib/printing.ts`
- Modify: `apps/pos/src/pages/SettingsPage.tsx`

- [ ] **Step 1: Add i18n translation keys**

In `apps/pos/src/locales/en/pos.json`, add after the `"networkPrinter"` line:

```json
"windowsPrinter": "Windows",
```

In `apps/pos/src/locales/fr/pos.json`, add after the `"networkPrinter"` line:

```json
"windowsPrinter": "Windows",
```

- [ ] **Step 2: Add `'windows'` to `PrinterConnectionType`**

In `apps/pos/src/lib/printing.ts`, line 9, update the type:

```typescript
export type PrinterConnectionType = 'usb' | 'network' | 'windows';
```

- [ ] **Step 3: Update connection type display in SettingsPage — current printer section**

In `apps/pos/src/pages/SettingsPage.tsx`, line 298, replace the ternary:

```tsx
{printerConfig.connection_type === 'usb' ? 'USB' : t('settings.networkPrinter')} — {printerConfig.address}
```

With a helper that handles all three types:

```tsx
{printerConfig.connection_type === 'usb'
  ? 'USB'
  : printerConfig.connection_type === 'windows'
    ? t('settings.windowsPrinter')
    : t('settings.networkPrinter')} — {printerConfig.address}
```

- [ ] **Step 4: Update connection type display in SettingsPage — discovered printers list**

In `apps/pos/src/pages/SettingsPage.tsx`, line 395, apply the same pattern:

```tsx
{printer.connection_type === 'usb'
  ? 'USB'
  : printer.connection_type === 'windows'
    ? t('settings.windowsPrinter')
    : t('settings.networkPrinter')} — {printer.address}
```

- [ ] **Step 5: Verify frontend compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/lib/printing.ts apps/pos/src/pages/SettingsPage.tsx apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(printing): add Windows type to frontend and update Settings UI labels"
```

---

### Task 5: Manual testing on Windows

**Files:** None (testing only)

This task must be done on the Windows machine with the Rongta printer connected.

- [ ] **Step 1: Build the Tauri app**

Run: `cd apps/pos && pnpm tauri build` (or `pnpm tauri dev` for development)

- [ ] **Step 2: Test printer discovery**

1. Open the app → Settings
2. Click "Scan for Printers"
3. Verify the Rongta printer appears in the list with "Windows" label
4. Verify virtual printers (Microsoft Print to PDF, etc.) are NOT listed

- [ ] **Step 3: Test printer selection and test print**

1. Select the Rongta printer from the list
2. Verify it shows as configured with "Windows — Rongta 80mm Series" (or similar)
3. Click "Test Print"
4. Verify a test page prints on the physical printer

- [ ] **Step 4: Test receipt printing**

1. Process a sale through the POS
2. Verify receipt prints automatically (if auto-print enabled) or via manual print button
3. Verify receipt formatting (header, items, totals, fiscal hash, QR code, footer, cut)

- [ ] **Step 5: Test cash drawer**

1. Go to Cash Drawer Settings
2. Click "Test Drawer" button
3. Verify the cash drawer opens
4. Verify auto-open on cash sale works

- [ ] **Step 6: Final commit (if any fixes needed)**

```bash
git add -A
git commit -m "fix(printing): adjustments from Windows testing"
```
