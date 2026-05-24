# POS Coordination Log — 2026-05-24 Sprint (v3 — broadened scope per round-2 R2-P2-2)

**Purpose:** Single coordination point for **ANY POS-related change** (Tauri client OR backend POS module OR migrations against POS-fiscal-touching tables) required by sprint tracks. The in-flight POS fiscal Codex session (running [2026-05-14-pos-phase1-fiscal-event-engine.md](../plans/2026-05-14-pos-phase1-fiscal-event-engine.md)) picks coordination items from here. **Sprint tracks do NOT modify POS code (Tauri OR backend) directly.** (Relative path corrected per round-4 S-2.)

**File rename:** previously `2026-05-24-tauri-pos-deltas.md`. Renamed per round-2 review (R2-P2-2) — original scope was too narrow; Wave 1 backend-POS work (T1 `ReceiptCreationService.php` mods, T2 migrations on `pos_receipt_lines` / `pos_receipt_line_batch_allocations`) also requires fiscal coordination, not just Tauri-side changes. **NOT `payments`** — that column ownership belongs entirely to fiscal Phase 1 Task 12 (corrected per round-3 P1-1 + round-4 sweep).

**Wave 1 backend-POS items requiring fiscal handshake (per round-2 R2-B1):**
- **T1-S1 (server-side coordination item):** modifying `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` lines 831-883 to consume `AvailableQuantityService` for in-transit availability enforcement. Fiscal Phase 1 Tasks 21/22 simultaneously refactor sibling services (`ReceiptSyncService.php`, `ReceiptPaymentService.php`); sequencing required.
- **T2-S1 (server-side coordination item):** adding `variant_id` columns to `pos_receipt_lines`, `pos_receipt_line_batch_allocations` via migrations (NOT `payments` — that's owned by fiscal Phase 1 Task 12). Fiscal Phase 1 Tasks 11/12 add columns to overlapping POS tables; sequencing required to avoid migration-order conflicts.

---

## Fiscal Session Handshake Protocol (NEW per Codex round-1 review)

The round-1 Codex adversarial review correctly identified that v1's "log to deltas" was too weak — fiscal Phase 1 actively rewrites the exact Tauri files (`receiptService.ts`, `offlineCheckoutService.ts`, `receiptApi.ts`, `syncService.ts`, device SQLite migrations) that T1/T2 deltas need to touch. **A fire-and-forget log creates collision risk.**

**The protocol:**

1. **Sprint track lead** writes the coordination entry in the table below (track origin, what's needed, why, suggested approach, urgency, dependencies, file targets)
2. **When a Codex POS session opens next** (whether for fiscal Phase 1 continuation or for a dedicated POS work pass), it consumes pending entries from this log
3. **Codex POS session** triages each entry:
   - **Schedule:** add into the POS session roadmap with explicit sequence point
   - **Defer:** mark "post-fiscal-Phase-1", do not ship now
   - **Negotiate:** propose alternative server-side approach that avoids POS touch
4. **Codex POS session** moves accepted entries to "In flight" section
5. **PR ships** the coordination item; entry moves to "Done" with PR link

No human-routing handoff required between sessions — the log IS the protocol (per user direction post-round-3).

**Coordination items ship via the fiscal session.** Wave 2 items + backend-POS Wave 1 items (T1-S1 + T2-S1 above) are handled the same way: log here, picked up when next Codex POS session opens.

**Wave 1 is zero-Tauri-CLIENT-touch** — the Tauri client code is untouched. Wave 1 backend-POS work (T1-S1 + T2-S1 above) IS in scope here. Tauri-CLIENT items appear in Tier C / Wave 2 sections below.

---

## Open deltas

### From T1 (Stock Transfer)

| # | Need | Why | Files touched | Suggested approach | Urgency | Dependencies | Status |
|---|---|---|---|---|---|---|---|
| T1-D1 | Surface `location.tax_id` + `location.branch_code` on printed receipts when set (fallback to company-level when null) | Tunisian branch numbering requires per-branch tax ID on receipts (also generic across other tax-ID-by-branch jurisdictions) | Receipt template renderer (Tauri side or server-rendered PDF) | Extend receipt template lookup to read `location.tax_id` + `branch_code`; fallback to `company.tax_id`. Verify both online + offline rendering paths. | Medium (needed for client meeting demo if possible) | T1 Phase 1 migration (`tax_id` + `branch_code` columns) must merge first | Pending fiscal triage |
| T1-D2 | InTransitAvailability POS rendering — show "Available with notice" / "Pending" / "Not available" on product cards based on per-COMPANY setting | Per Codex P1-5: T1's in-transit setting requires POS behavior change; can't pass through server work alone for the UX | `apps/pos/src/components/Product*`, possibly `cartStore.ts` for availability calculation | Add availability descriptor to product DTO (server-side); POS renders accordingly. Offline mode: cached `available_quantity` includes in-transit adjustment per company setting (snapshot at sync time). | Medium | T1 server-side `AvailableQuantityService` + per-company setting storage must merge first | Pending fiscal triage |
| T1-D3 | Server-side enforcement: `ReceiptCreationService` consumes `AvailableQuantityService` (which honors per-COMPANY `InTransitAvailability` setting) | If setting=NotAvailable and product is in transit, sale must be blocked server-side too | `apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (lines 831-883) | Replace existing raw `StockLevel::getAvailableQuantity()` call with `AvailableQuantityService::availableForSale($productId, $variantId, $locationId)` | High (server-side coordination item — part of T1-S1) | T1 server-side merged | Backend-POS coordination item (per round-4 P2-2: not a Tauri delta but coordinates with same fiscal session for sequencing) |

### From T2 (Variants)

| # | Need | Why | Files touched | Suggested approach | Urgency | Dependencies | Status |
|---|---|---|---|---|---|---|---|
| T2-D1 | Variant picker modal — cashier taps variant-tracked product → modal shows matrix (color rows × size columns) with available stock per cell | Customer asks for "chaussure orthopédique 39 noir"; cashier needs UX to pick variant | `apps/pos/src/pages/HomePage.tsx`, new `VariantPickerModal.tsx`, `cartStore.ts` (cart line accepts variant_id) | New modal component triggered from `ProductCard` tap; mirrors UX style of existing modifier pickers in the catalog area | High (needed for demo) | T2 Phase 1–2 (variant_id columns + service) must merge first | Pending fiscal triage |
| T2-D2 | Direct variant barcode scan — barcode scanner resolves to variant if matched, falls back to product | Faster checkout for variant-tracked SKUs | `cartStore.ts` barcode resolution path | Call new `GET /api/v1/products/lookup?barcode=...` (extended in T2) which returns variant if matched | Medium | T2 Phase 1–2 | Pending fiscal triage |
| T2-D3 | Cart line display — show "Chaussure X — 39 / Noir" instead of just product name | UX clarity for variant SKUs | Cart line component | Extend cart line renderer to display `variant.name_suffix` when present | Medium | T2 Phase 1–2 | Pending fiscal triage |
| T2-D4 | Local SQLite schema update — `pos_receipt_lines` + variant cache need `variant_id` | Offline-first variants | `apps/pos/src/lib/offline/migrations/`, `receiptService.ts`, `offlineCheckoutService.ts` | Mirror existing local-DB migration pattern; add SQLite migration for `variant_id` columns. **HIGH RISK OF FISCAL COLLISION** — these are the exact files fiscal Phase 1 rewrites | Medium (only needed for production deployment) | T2 Phase 1–2; **CRITICAL fiscal coordination** | Pending fiscal triage |

### From T11 (B2B/B2C — design-only this sprint, deltas only when impl-C ships in next cycle)

| # | Need | Why | Files touched | Suggested approach | Urgency | Dependencies | Status |
|---|---|---|---|---|---|---|---|
| T11-D1 | "B2B" badge in customer search results | When cashier searches a customer, B2B accounts visually distinct | `apps/pos/src/components/CustomerSearch*` | Extend customer search result component with pill badge when `partner.customer_category=Business` | Low (post-sprint, when T11-impl-C ships) | T11-impl-A merged | Pending fiscal triage |
| T11-D2 | "Save as Draft Order → Web" button | Thin-POS hand-off pattern when B2B customer walks in | `holdStore.ts`, new button in checkout area | Extend existing `holdStore` (`holdCurrentCart` / `recallTransaction`) with `saveAsDraftOrder` variant that POSTs to web ERP queue endpoint; mirrors hold pattern exactly | Low (post-sprint, when T11-impl-C ships) | T11-impl-C | Pending fiscal triage |
| T11-D3 | Apply PartnerPriceList automatically when B2B customer selected at POS | Pricing routing — same PricingStrategyResolver used by web | Customer selection handler in `cartStore.ts` | New API call on customer selection: fetch resolved prices for current cart lines using `PricingStrategyResolver` | Low (post-sprint, when T11-impl-B ships) | T11-impl-B | Pending fiscal triage |

### From T3 (Sync Hub — shared infrastructure only this sprint)

| # | Need | Status |
|---|---|---|
| _(none expected)_ | T3 ships shared infrastructure only; no concrete adapters; no POS touch needed | N/A |

### From T4 (Order Routing)

| # | Need | Status |
|---|---|---|
| _(none expected)_ | T4 routes incoming online orders server-side; POS sees results in Documents list | N/A |

### From T5 (Reporting)

| # | Need | Status |
|---|---|---|
| _(none expected)_ | T5 is web-only | N/A |

### From T6 (Tenant Provisioning)

| # | Need | Status |
|---|---|---|
| _(none expected)_ | T6 is infrastructure; transparent to POS | N/A |

---

## Channel adapter implementations (deferred, NOT this sprint)

Per v2 roadmap restructure, concrete channel adapters (WooCommerce, PrestaShop, Shopify, Paradeals) are deferred to a follow-up sprint after Nénupharma confirms their platform. **No adapter-related Tauri deltas this sprint.** When adapter sprint happens, the variant mapping work (T2-derived) will flow through that sprint's separate coordination.

---

## In flight (deltas fiscal session has scheduled and is executing)

_(none yet)_

---

## Done deltas

_(none yet)_

---

## Conventions

- Append new deltas with track origin, urgency, dependencies, AND `Files touched` column (per Codex review feedback — concrete file targets help fiscal session triage)
- Fiscal session owner marks `Status` column: `Pending fiscal triage` → `Scheduled <date>` → `In flight` → `Done — PR #<N>`
- If a delta becomes obsolete, mark `Obsolete — <reason>` in Status column
- Cross-link to originating spec for context
- **No track lead modifies Tauri code directly** — all goes through fiscal session
