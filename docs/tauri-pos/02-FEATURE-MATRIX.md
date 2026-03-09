# Feature Matrix - Web POS vs Tauri POS

## Legend
- **W** = Web POS only
- **T** = Tauri POS only
- **B** = Both (shared feature, different implementation)
- **N** = Not yet implemented anywhere

---

## Core POS Operations

| Feature | Web | Tauri | Notes |
|---------|-----|-------|-------|
| Product grid with categories | B | B | Tauri uses menu-based layout |
| Barcode scanning | N | T | USB HID scanner via Tauri |
| Cart management | B | B | |
| Quantity adjustment | B | B | |
| Modifier selection (F&B) | B | B | Modal for composite items |
| Consumption mode toggle | B | B | Dine-in / Takeout |
| Line-level discounts | B | B | |
| Transaction-level discounts | B | B | |
| Split payments | B | B | |
| Cash tendered + change | B | B | |
| Receipt creation | B | B | Tauri can work offline |
| Receipt voiding | B | B | |
| Receipt PDF | W | T | Web streams from API; Tauri prints locally |
| Receipt printing | N | T | Thermal printer via ESC/POS |

## Menu & Catalog

| Feature | Web | Tauri | Notes |
|---------|-----|-------|-------|
| Menu CRUD | W | - | Admin function |
| Category management | W | - | Admin function |
| Active menu display | B | B | Categories + items grid |
| Menu item search | B | B | Tauri adds barcode |
| Modifier group UI | B | B | |
| Price override per menu | B | B | |
| Item availability toggle | W | - | Admin function |

## Shift & Cash Management

| Feature | Web | Tauri | Notes |
|---------|-----|-------|-------|
| Open shift | B | B | |
| Close shift | B | B | |
| Cash deposits | B | B | |
| Cash payouts | B | B | |
| Cash drawer balance | B | B | |
| Cash drawer kick | N | T | Hardware command |
| Shift receipts list | B | B | |

## Reporting

| Feature | Web | Tauri | Notes |
|---------|-----|-------|-------|
| X-Report generation | B | B | |
| X-Report display | B | B | |
| Z-Report generation | B | T | Web triggers via API; Tauri generates + prints locally |
| Z-Report PDF export | N | T | Save to filesystem |
| Z-Report list/history | W | T | Web browses all; Tauri shows local |
| Z-Report hash verification | W | - | Admin/audit function |
| Shift summary dashboard | B | T | Tauri shows detailed local view |
| Sales by category | N | T | Local analytics |
| Sales by hour | N | T | Local analytics |

## Loyalty & Promotions

| Feature | Web | Tauri | Notes |
|---------|-----|-------|-------|
| Member lookup | B | B | By phone/card number |
| Points display | B | B | |
| Points earning preview | B | B | |
| Reward selection | B | B | |
| Stamp card progress | B | B | |
| Coupon scanning/entry | B | T | Tauri adds barcode scan |
| Automatic promotions | B | B | Backend-driven |

## Hardware Integration (Tauri Only)

| Feature | Notes |
|---------|-------|
| Receipt printer (thermal) | ESC/POS via USB/serial/network |
| Kitchen printer | Separate printer for kitchen tickets |
| Cash drawer | Open via printer or dedicated port |
| Barcode scanner | USB HID (keyboard wedge) |
| Customer display | Secondary screen or pole display |
| Scale integration | For weight-based items (future) |

## Offline & Sync (Tauri Only)

| Feature | Notes |
|---------|-------|
| Offline receipt creation | Local SQLite with fiscal hash chain |
| Sync queue | Background sync when online |
| Conflict resolution | Server-authoritative with local sequence |
| Menu caching | Local copy of active menu |
| Stock level caching | Approximate local stock |

## Settings (Tauri Only)

| Feature | Notes |
|---------|-------|
| Printer configuration | Select receipt/kitchen printers |
| Cash drawer settings | Port, open command |
| Display settings | Fullscreen, kiosk mode, screen layout |
| Network/API settings | Server URL, sync interval |
| Terminal registration | Link to backend terminal record |
| Sound settings | Transaction sounds, alerts |
| Receipt template | Logo, footer text, paper width |
| Language/locale | fr, en, ar |

## Admin Functions (Web Only)

| Feature | Notes |
|---------|-------|
| Terminal CRUD | Create/edit/delete terminals |
| Shift history (all terminals) | Cross-terminal view |
| Receipt search (all terminals) | Global search |
| User/permission management | |
| Menu builder | Drag-and-drop categories and items |
| Loyalty program setup | |
| Promotion rules | |
| Coupon management | |
