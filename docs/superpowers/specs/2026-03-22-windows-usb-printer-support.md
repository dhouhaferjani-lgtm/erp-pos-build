# Windows USB Printer Support via Winspool Raw Printing

## Problem

The current printer discovery uses `serialport::available_ports()` which only finds USB devices that present as serial COM ports. Most modern 80mm thermal receipt printers (Rongta, Epson TM-T20, Star TSP100, etc.) register as **USB Printer Class** devices on Windows. They appear in Windows Settings > Printers & Scanners but are invisible to our app.

The manual entry form only accepts IP + port (network printer), offering no workaround for USB printers that aren't COM ports.

## Solution

Add a third printer connection type — `windows` — that uses the Win32 Winspool API (`OpenPrinterW`, `WritePrinter`, `ClosePrinter`) to send raw ESC/POS bytes to any printer Windows can see. This is how commercial POS software handles printing.

## Architecture

### Connection Types

```
PrinterConnectionType: 'usb' | 'network' | 'windows'
                        │         │           │
                        │         │           └─ Win32 Winspool (RAW datatype)
                        │         └─ TCP port 9100
                        └─ Serial port (9600 baud)
```

The `windows` type is the primary path for Windows desktop deployments. The existing `usb` (serial) and `network` (TCP) paths remain unchanged for Linux/macOS and network printers.

### Discovery Flow

1. User clicks "Discover Printers"
2. On Windows: call `EnumPrintersW(PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS)` → returns all installed printers
3. On all platforms: existing network scan runs (TCP 9100 subnet scan)
4. On all platforms: existing serial port scan runs (likely returns nothing on Windows for thermal printers)
5. Results merged and deduplicated, returned to frontend

### Printing Flow (windows type)

1. Frontend sends `print_receipt` with `connection_type: "windows"`, `address: "Rongta 80mm Series"` (Windows printer name)
2. Rust: `OpenPrinterW(printer_name)` — opens handle to the printer
3. Rust: `StartDocPrinterW` with `DOC_INFO_1 { datatype: "RAW" }` — tells Windows to pass bytes through unmodified (no GDI rendering)
4. Rust: `StartPagePrinter` — begins a page
5. Rust: `WritePrinter(escpos_bytes)` — sends the ESC/POS binary data
6. Rust: `EndPagePrinter` → `EndDocPrinter` → `ClosePrinter` — cleanup
7. All existing ESC/POS formatting (receipt template, barcodes, QR codes, fiscal data) is identical — only the transport layer changes

### Cash Drawer

The cash drawer is physically connected to the printer via RJ11/RJ12. The kick command is an ESC/POS sequence (`ESC p` / `0x1B 0x70`) sent through the same data pipe as receipt data. No special handling needed — if the printer is configured as `windows` type, the `open_cash_drawer` command routes through `windows::send()` with the same ESC/POS bytes. All existing drawer settings (pin 2/5, pulse timing, beep) work unchanged.

## Implementation

### New File: `apps/pos/src-tauri/src/printing/windows.rs`

Behind `#[cfg(target_os = "windows")]`.

**Functions:**

- `discover() -> Result<Vec<PrinterInfo>>` — Calls `EnumPrintersW` to list all installed Windows printers. Filters out virtual/non-physical printers (PDF, XPS, Fax, OneNote) by checking driver name and printer attributes. Maps each to `PrinterInfo { id: "win:{name}", name, connection_type: Windows, address: "{printer_name}" }`.
- `send(printer_name: &str, data: &[u8]) -> Result<()>` — Opens printer, starts RAW document, writes ESC/POS bytes, closes. Uses an RAII `PrinterHandle` wrapper (implements `Drop` calling `ClosePrinter`) to prevent handle leaks on panics or early returns. Wrapped in `tokio::task::spawn_blocking` with a 10s timeout to prevent blocking on stuck spooler queues.

**Dependency:** `windows` crate (Microsoft's official Rust bindings), target-gated in `Cargo.toml`:

```toml
[target.'cfg(windows)'.dependencies]
windows = { version = "0.58", features = ["Win32_Graphics_Printing", "Win32_Foundation"] }
```

Features used:
- `Win32_Graphics_Printing` — `OpenPrinterW`, `StartDocPrinterW`, `WritePrinter`, `EndDocPrinter`, `ClosePrinter`, `EnumPrintersW`, `PRINTER_INFO_2W`, `DOC_INFO_1W`
- `Win32_Foundation` — `HANDLE`, `PCWSTR`, error codes

### Modified Files

**`src/printing/mod.rs`:**
- Add `Windows` variant to `PrinterConnectionType` enum — **unconditionally on all platforms** (required for serde deserialization of persisted configs from Windows machines)
- Add `#[cfg(target_os = "windows")] pub mod windows;`
- Update `send_to_printer()` match: add `Windows` arm that calls `windows::send()` on Windows, or returns `PrintError::Windows("Windows printing is only available on Windows".into())` on other platforms

**`src/commands/printing.rs`:**
- `discover_printers`: On Windows (`#[cfg]` block inside function), call `windows::discover()` and merge results with network/serial scans
- No changes to `print_receipt`, `open_cash_drawer`, `print_test_page` — they already delegate to `send_to_printer()` which handles the routing

### Frontend Changes

**`apps/pos/src/lib/printing.ts`:**
- Add `'windows'` to `PrinterConnectionType` type union

**`apps/pos/src/pages/SettingsPage.tsx`:**
- Update the connection type display ternary (currently `connection_type === 'usb' ? 'USB' : t('settings.networkPrinter')`) to handle `'windows'` as a third branch — both in the "current printer" display and the discovered printers list
- Display Windows printers with a "Windows" label/tag
- No new manual entry form needed — if Windows can see the printer, discovery finds it

No changes to: `printerStore.ts`, `cashDrawerStore.ts`, `CheckoutSuccessModal.tsx`, `PrinterAdvancedSettings.tsx`, `CashDrawerSettings.tsx`.

### Error Handling

Add a new `PrintError::Windows(String)` variant (consistent with existing `Usb(String)` and `Network(String)` variants). All Win32 errors map to this variant with descriptive messages:

| Win32 Error | User Message |
|---|---|
| `OpenPrinterW` returns false | "Printer not found or not accessible. Check Windows printer settings." |
| `StartDocPrinterW` returns 0 | "Could not start print job. The printer may be busy or offline." |
| `WritePrinter` returns false | "Failed to send data to printer. Check connection." |
| Printer not in `EnumPrinters` list | "Printer not found. Make sure it's installed in Windows." |
| `spawn_blocking` timeout (10s) | "Print job timed out. The printer may be offline or the spooler may be stuck." |

### Platform Conditional Compilation

```rust
// src/printing/mod.rs
pub mod escpos;
pub mod network;
pub mod usb;
pub mod receipt_template;

#[cfg(target_os = "windows")]
pub mod windows;
```

All `windows::` calls in `commands/printing.rs` are gated with `#[cfg(target_os = "windows")]`. On non-Windows platforms, the `Windows` connection type variant returns an error: "Windows printing is only available on Windows."

## Testing

- **Unit tests:** `windows.rs` functions tested with mock printer name (verify correct Win32 API call sequence)
- **Integration test:** On a Windows machine with a printer installed, run `discover()` and verify it returns the printer
- **Manual test:** Print a test page and a real receipt on the Rongta 80mm via Windows path
- **Regression:** Verify network and serial paths still work unchanged on all platforms
- **Cash drawer:** Verify drawer kick works through Windows path

## Out of Scope

- Bluetooth printer support
- Kitchen printer / multi-printer support
- Linux CUPS printing (existing serial/network paths cover Linux)
- macOS printing (existing serial/network paths cover macOS)
- Printer status monitoring (paper out, cover open, etc.)
