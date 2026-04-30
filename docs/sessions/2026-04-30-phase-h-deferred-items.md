# Phase H — Deferred Items

Punch list of UX-drift and audit-trail follow-ups deferred from the Phase H critical fixup (commit `61e6f155` on `feat/refund-flow`). Session 4 picks these up alongside Tasks 52–53.

**None of these block the PR-to-dev.** They are either (a) UX drift the user would notice on first dev-tenant smoke test but that doesn't silently corrupt data, or (b) audit-trail gaps that the existing `voucher.notes` append captures coarsely. The three Critical bugs (C1/C2/I1) that would have produced silent failures in production are already fixed in `61e6f155`.

---

## C3 — UserPicker for second-admin field + cashier filter

**File 1:** `apps/web/src/features/vouchers/components/IssueGoodwillVoucherModal.tsx`
**Lines:** 10 (import), 53–54 (state types), 117 (payload), 185 (partner picker — keep this one, it's a real partner), 247–250 (second-admin picker — this one is wrong)

**File 2:** `apps/web/src/features/customer-history-audit/pages/CustomerHistoryAuditPage.tsx`
**Lines:** 7 (import), 106 (cashier filter — wrong), 144 (partner filter — keep, real partner)

**Files to create:** `apps/web/src/components/ui/UserPicker.tsx` plus a small Vitest at `apps/web/src/components/ui/__tests__/UserPicker.test.tsx`.

**Rationale:** `PartnerPicker` and `PartnerSearchSelect` query the partners table (customers + suppliers). The two flagged call sites need to select a User (admin for four-eyes approval, cashier for audit filtering). UUIDs returned by the partner picker pass UUID-format validation but don't exist in `users`, so the backend resolution fails downstream. Need a new `UserPicker` that hits a user-search endpoint (grep the codebase for an existing one — likely under user-management) with an optional `roleFilter` prop for the second-admin case.

**Blocks PR-to-dev:** No. (Bug is real but only fires when a user actually clicks the picker — won't break route loads or data display.)

---

## I2 — Zod `reason` schemas misaligned with backend FormRequests

**File 1:** `apps/web/src/features/vouchers/components/VoidVoucherModal.tsx` line 10
**File 2:** `apps/web/src/features/vouchers/components/TransferVoucherModal.tsx` line 12
**File 3:** `apps/web/src/features/vouchers/components/ExtendExpiryModal.tsx` lines 10–11

**Changes:**
- All three: `z.string().min(1)` → `z.string().min(5)` on the `reason` field. Backend FormRequests require min:5; current 1-character input passes client-side, hits the server, returns 422 with a generic toast instead of in-form field error.
- `ExtendExpiryModal` line 10 (`new_expires_at: z.string().min(1)`): replace with `z.coerce.date().refine((d) => d.getTime() > Date.now(), 'must be in the future')` (or string equivalent that parses + refines). Currently a past date passes client-side and gets a generic backend rejection.
- Add translated error messages for the new minimum + future-date constraints.
- Update each modal's tests to assert the new minimums client-side.

**Rationale:** Backend already enforces; this is purely about giving the cashier in-form validation feedback rather than a generic toast after a roundtrip.

**Blocks PR-to-dev:** No.

---

## I3 — Sidebar misplacement of `/settings/...` entries under POS group

**File:** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
**Lines:** 224–225 (the two misplaced entries inside the `pointOfSale` group children array)

**Change:** Move both entries OUT of the POS group's `children` array:
- `posRefundPolicies` (`/settings/pos-refund-policies`) — relocate under the existing Settings group, or alongside other settings entries. Drop `module: 'settings'` if Settings group already gates correctly.
- `customerHistoryAudit` (`/settings/audit/customer-history`) — same. Consider creating a small Audit subgroup if other audit pages exist; otherwise place under Settings.

Keep the `vouchers` entry at line 223 in the POS group — its href is `/pos/vouchers` so it belongs there.

**Rationale:** Clicking a `/settings/...` link from inside the POS sidebar group is jarring — the user expects the URL to stay within the POS area. Active-route highlighting will also break (item shows active in POS sidebar but actual route is under settings).

**Blocks PR-to-dev:** No. (Cosmetic / IA bug.)

---

## I4 — `parseFloat` violates codebase numeric-conversion convention

**File:** `apps/web/src/features/settings/types/posRefundPolicies.ts`
**Line:** 121 (`(v) => parseFloat(v) >= 0 && parseFloat(v) <= 100`)

**Change:** Replace both `parseFloat` calls with `Number(...)`. The codebase prefers `Number()` for numeric coercion because `parseFloat('100abc')` silently returns `100` while `Number('100abc')` returns `NaN` — the latter propagates more safely through Zod refinements.

**Rationale:** Codebase consistency; in this specific Zod refine the difference is benign (the field is a numeric-string already validated upstream) but the linter / future reviewer will flag it.

**Blocks PR-to-dev:** No. (Pure convention.)

---

## Task #8 (pre-existing) — Backend transfer reason persistence + extendExpiry ledger row

**File:** `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php`

**Issue 1: `transfer()` reason not on `voucher.override_reason`.**
**Lines:** 363–425 (the `transfer()` method body). Currently the reason is appended to `voucher.notes` (lines 416–419) and `policy_trigger='manual_transfer'` is written on the ledger row (line 409), but `voucher.override_reason` is left unchanged. The `void()` method (search for `'manual_void'`) sets `override_reason` AND appends to notes — `transfer()` should mirror that pattern for parity.

**Change:** Inside the `DB::transaction` closure, add `$voucher->override_reason = $reason;` before the `$voucher->save()` call (around line 421).

**Issue 2: `extendExpiry()` writes no `VoucherLedger` row.**
**Lines:** 432–end (the `extendExpiry()` method body). Currently mutates `voucher.expires_at` and appends a note, but writes no ledger event. The spec's "ledger is the source of truth for back-office override actions" principle wants every override traced through the ledger.

**Change:** Inside the `DB::transaction` closure, write a `VoucherLedger` row with `event = VoucherEvent::Extended`, `amount = '0.00000'`, `policy_trigger = 'manual_extend_expiry'`, `user_id = $user->id`, and `occurred_at = now()`. Mirror the structure from `transfer()` lines 397–414. Then update `VoucherControllerTest::test_extend_expiry_*` to assert the ledger row was written. Also extend the frontend `LedgerHistoryTable.tsx` if the `Extended` event type isn't already styled.

**Rationale:** Coarse audit (notes string append) survives but doesn't expose to ledger queries / fraud analytics. Either flag is independently auditable but together they leave the operations team grepping `notes` text for an extension/transfer history that should be a structured ledger event.

**Blocks PR-to-dev:** No. (Audit-trail gap, not a correctness bug. Existing notes append captures the action coarsely.)

---

## Verification commands for Session 4

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.refund-flow

# After C3+I2+I3+I4 fixes:
cd apps/web
pnpm test src/features/vouchers src/features/settings src/features/customer-history-audit src/components/ui
pnpm typecheck
pnpm lint --max-warnings=0 src/features/vouchers src/features/settings src/features/customer-history-audit src/components/ui

# After Task #8 fixes:
cd ../api
./vendor/bin/phpunit --filter='VoucherControllerTest'
./vendor/bin/phpstan analyse app/Modules/Voucher/Presentation --level=8
./vendor/bin/pint --test app/Modules/Voucher/Presentation
```

---

## Phase H follow-up: full receipts mirror for cross-session refunds

**Filed during Task 52 implementation (2026-04-30).**

### Current behaviour

`hydrateFromReceipt` looks up the original receipt lines from `offline_receipts` by UUID. This works when the receipt was originated on the **same terminal** in the same DB and has not yet been pruned (pruning happens 30 days after sync per `cleanupSyncedReceipts`). When the receipt is absent — either because it was synced + pruned, or because it was issued on a different terminal — `hydrateFromReceipt` returns null and the UI surfaces `refundFlow.detailsNotLocal` (no cart mutation).

### The gap (Option B)

For a complete refund solution where any terminal can refund any receipt from any terminal at any time, we need a **full receipts mirror table** that is populated from the server sync payload. This would be a separate `receipt_lines_mirror` (or extending `receipt_qr_index` to carry a `lines_json` column) that is populated by the sync service alongside the QR index entries.

**Why deferred:**
1. The server sync payload currently does not include line items — only the metadata exposed to `receipt_qr_index` (uuid, number, terminal, total, currency, posted_at, qr_token). Adding `lines_json` to the sync payload requires a backend change to the receipt-sync endpoint and a new migration on the POS side.
2. The 30-day pruning window means most real-world refunds (within the return window) will still find the receipt in `offline_receipts`, making this a day-2 feature gap rather than a launch blocker.
3. Cross-terminal refunds require manager-level authorisation (Task 53) anyway, so the user experience for the cross-terminal case is already gated.

**Recommended follow-up (Option B):**
- Backend: extend `GET /sync/receipts` to include `lines_json` (serialised as a compact decimal-string array) per receipt entry.
- Sync service: pass `lines_json` through `upsertReceiptQrIndexEntries` and store it in a new column `receipt_qr_index.lines_json TEXT NULL` (migration 28).
- `hydrateFromReceipt`: fall through to `receipt_qr_index` lookup when `offline_receipts` miss, parsing `lines_json` the same way.
- Remove the `detailsNotLocal` warning once Option B is live.

**Estimated effort:** 1–2 days (backend sync endpoint + migration + hydration fallback).

---

## Phase H follow-up: API exception handler typed-code mapping

**Filed during Task 53 implementation (2026-04-30).**

**Owner:** Backend session (Laravel / `apps/api/`).

### Current behaviour

`apps/api/bootstrap/app.php` has a single generic handler for all `DomainException` subclasses that returns HTTP 422 with `error.code = 'BUSINESS_ERROR'`. This means the POS frontend cannot distinguish between refund-specific 422s — `ManagerOverrideRequiredException`, `DailyRefundCapExceededException`, `RefundWindowClosedException`, `RefundDestinationNotAllowedException` — and any other domain error.

As a result, `RefundConfirmModal` falls back to a generic toast for all of them instead of showing the inline ManagerPinPanel for the cases that require it.

### Exception classes involved

All four live at: `apps/api/app/Modules/POS/Domain/Exceptions/`

- `ManagerOverrideRequiredException` — should map to 422 with `error.code = 'MANAGER_OVERRIDE_REQUIRED'`
- `DailyRefundCapExceededException` — should map to 422 with `error.code = 'DAILY_REFUND_CAP_EXCEEDED'`
- `RefundWindowClosedException` — should map to 422 with `error.code = 'REFUND_WINDOW_CLOSED'`
- `RefundDestinationNotAllowedException` — should map to 422 with `error.code = 'REFUND_DESTINATION_NOT_ALLOWED'`

### Required fix in `apps/api/bootstrap/app.php`

Add four specific `render` closures **BEFORE** the generic `DomainException` handler, each returning a 422 with the typed code:

```php
->withExceptions(function (Exceptions $exceptions) {
    // Specific POS refund exception handlers — MUST come before the generic DomainException handler
    $exceptions->render(function (ManagerOverrideRequiredException $e, Request $request) {
        return response()->json([
            'error' => ['code' => 'MANAGER_OVERRIDE_REQUIRED', 'message' => $e->getMessage()],
            'meta' => ['timestamp' => now()->toISOString(), 'request_id' => $request->header('X-Request-Id', '')],
        ], 422);
    });
    $exceptions->render(function (DailyRefundCapExceededException $e, Request $request) {
        return response()->json([
            'error' => ['code' => 'DAILY_REFUND_CAP_EXCEEDED', 'message' => $e->getMessage()],
            'meta' => ['timestamp' => now()->toISOString(), 'request_id' => $request->header('X-Request-Id', '')],
        ], 422);
    });
    $exceptions->render(function (RefundWindowClosedException $e, Request $request) {
        return response()->json([
            'error' => ['code' => 'REFUND_WINDOW_CLOSED', 'message' => $e->getMessage()],
            'meta' => ['timestamp' => now()->toISOString(), 'request_id' => $request->header('X-Request-Id', '')],
        ], 422);
    });
    $exceptions->render(function (RefundDestinationNotAllowedException $e, Request $request) {
        return response()->json([
            'error' => ['code' => 'REFUND_DESTINATION_NOT_ALLOWED', 'message' => $e->getMessage()],
            'meta' => ['timestamp' => now()->toISOString(), 'request_id' => $request->header('X-Request-Id', '')],
        ], 422);
    });
    // ... existing generic DomainException handler below ...
})
```

### Frontend wiring (already done — waiting on backend)

`apps/pos/src/lib/refundFlow/refundConfirmation.ts` already has `mapRefundErrorToUiAction()` that routes the four typed codes to their respective UI actions (`manager-pin`, `daily-cap`, `window-closed`, `generic`). `RefundConfirmModal` already branches on this. Once the backend emits the typed codes, the PIN prompt will fire automatically.

The TODO comment in `refundConfirmation.ts` and `RefundConfirmModal.tsx` points at this follow-up.

---

## Phase H follow-up: receipt printer wiring (sale QR, AVOIR header, exchange both-halves, voucher tickets)

**Filed during Task 53 implementation (2026-04-30).**

**Owner:** POS session (`apps/pos/`). Requires a Tauri build to smoke-test; cannot be verified in vitest alone.

**Status update (2026-04-30, Block 2 Session 4):**
- [x] **#1 Sale-receipt QR token at footer** — shipped (TS `ReceiptData.qr_token` + Rust render at footer; HomePage looks up via `findReceiptByNumber`).
- [x] **#2 Refund-receipt REMBOURSEMENT/REFUND header + original ticket cross-refs** — shipped (i18n keys, `receipt_kind`, `original_receipt_number`, `original_receipt_qr_token`; Rust block before items).
- [ ] **#3 Exchange both-halves single-ticket layout** — STILL DEFERRED. Exchange already works as two cross-referenced fiscal receipts joined by `exchange_group_id`; printing them as two tickets with cross-references is good enough for go-live. Single-ticket layout remains a polish item.
- [x] **#4 Voucher ticket** — shipped (separate `print_voucher_ticket` Tauri command + dedicated Rust template + `printVoucherTicket` TS wrapper + backend `processReturn` response now surfaces `issued_voucher`).

### Scope of work

`apps/pos/src/lib/buildReceiptData.ts` needs the following additions:

#### 1. Sale receipt — QR token at footer

The `receipt_qr_index` table has a `qr_token` field (format `v:kid:receipt_uuid:mac`). Currently the sale receipt doesn't include a QR code. The QR token should be appended to the footer of every sale receipt so that any terminal can scan it to start a return.

**Data source:** `receipt_qr_index.qr_token` (from `findReceiptByQrToken` / `findReceiptByNumber` in `voucherRepository.ts`). The POS can look up its own offline receipt's QR token by receipt UUID via `findReceiptByQrToken`.

#### 2. Refund receipt — REMBOURSEMENT / AVOIR header

A refund receipt should carry:
- Header text: `REMBOURSEMENT` (FR) / `REFUND` (EN) or `AVOIR` at the top (before items).
- Original receipt number: `Ticket original: R-XXXX` below the header.
- Original receipt QR reprint: a second QR section (or text equivalent) with the original ticket's QR token, so the original can still be scanned for further partial refunds.
- V3 hash footer: same chain-hash footer as sale receipts (ensure the refund receipt is included in the fiscal chain).

#### 3. Exchange receipt — both halves on one page

When the cart has both `kind: 'return'` and `kind: 'sale'` items (mixed exchange), print both halves on one receipt:
- Top half: RETOUR / RETURNING section (negative lines from original).
- Middle: separator.
- Bottom half: VENTE / BUYING section (new items).

Currently the receipt builder may only handle one line type. Verify and extend.

#### 4. Voucher ticket

When a refund destination is `store_voucher` and the server issues a voucher code, print a dedicated voucher ticket (or a second page):
- Voucher code (large, scannable QR + human-readable).
- Balance at issuance.
- Expiry date (if set).
- Redemption mode (Bearer / Customer-bound).
- Issuing terminal + date.

**Data source:** The server returns the issued voucher details in the refund-confirm API response. Store/pass this to the receipt printer after the successful refund.

### Build requirement

All four require a Tauri build (`pnpm tauri build` or `pnpm tauri dev`) to print to an actual ESC/POS printer and verify layout. Vitest cannot mock the Tauri printer plugin.

---

## Phase H follow-up: end-to-end smoke (cash sale → finalize → print → scan QR → confirmation sheet → refund cart → confirm → print AVOIR → voucher issued → next sale redeems voucher)

**Filed during Task 53 implementation (2026-04-30).**

**Owner:** QA / human tester. Requires all of:
1. The API exception handlers (above) — so typed PIN prompts fire correctly.
2. The receipt printer wiring (above) — so AVOIR header + voucher ticket print.
3. A Tauri build pointing at a dev-tenant server with the refund endpoints deployed.

### Smoke steps

1. **Cash sale:** Ring up any product, tender cash, finalize → receipt prints → QR code at footer.
2. **Scan QR:** From POS home, scan the receipt QR → confirmation sheet renders (Task 50).
3. **Start refund:** Tap "Start Return" → unified cart loads with all original lines as negatives (Task 52).
4. **Confirm refund (no override):** Select "Cash" as refund destination (Task 53 Piece 1). Submit → server accepts without override → cash refund completes → AVOIR receipt prints with original ticket ref + original QR.
5. **Confirm refund (with override):** Repeat for a refund that exceeds manager threshold → `MANAGER_OVERRIDE_REQUIRED` 422 → ManagerPinPanel shows inline → manager enters PIN → re-submit → refund completes.
6. **Voucher destination:** Repeat selecting "Store Voucher" destination → server issues voucher → voucher ticket prints.
7. **Next sale redeems voucher:** Open new sale, tap "Voucher / Bon" tender (Task 53 Piece 3) → scan/type the voucher code → details show (balance, expiry) → apply → receipt posts with `payment_method.code = 'store_voucher'` and `instrument_serial = <code>`.

### Deferred until

Steps 4–7 require both the API exception handler fix AND the receipt printer wiring. Steps 1–3 (scan + confirmation sheet + refund cart) can be tested immediately once a Tauri build is available.

---

## Phase H Block 2.5 — Full-fiscal-year local receipt retention (SHIPPED 2026-04-30)

Shipped:
- POS-side `cleanupSyncedReceipts` no longer prunes after 30 days; receipts within
  the current fiscal year (calendar year for Phase 1) are retained. The retention
  anchor is `created_at` (fiscal posting timestamp on this terminal), not
  `synced_at` — the boundary cares when the receipt was issued, not when it
  was uploaded.
- `findRecentReceiptsByPartner` and `CustomerHistorySearchService::search` /
  `::searchByPartner` apply a permission-bound window at READ time:
  - `pos.search_customer_full_history` → full fiscal year (calendar year for
    Phase 1; `Carbon::now()->startOfYear()` on the backend).
  - `pos.search_customer_recent_purchases` (only) → 30-day cashier window on
    the POS, `customerHistoryWindowDays` (default 14) on the backend.
- `ReceiptLocatorScreen.CustomerTab` now derives `searchWindowDays` from the
  operator's permissions and passes it to `findRecentReceiptsByPartner`.

Deferred to a later phase:
- Custom fiscal-year start dates per tenant. Phase 1 hardcodes calendar-year
  boundaries (Jan 1). Most retail tenants in France use the calendar year as
  their fiscal year, so this is acceptable for the launch wave. Phase 2 will
  read `fiscal_year.start_date` from `companyConfig` and gate the boundary.
- Fiscal-year-close archival: at year-close, an NF525-compliant long-term
  storage flow will migrate the prior year's receipts off local SQLite and
  reintroduce a pruning boundary aligned with that flow. Today, the POS
  accumulates receipts indefinitely within a year; at ~100 receipts/day that's
  ~36k rows in SQLite — well within comfortable limits.
- Cross-terminal refundability (Option B above — "Phase H follow-up: full
  receipts mirror for cross-session refunds"). A receipt issued at terminal A
  is still only refundable at terminal A. Phase 2 will introduce a
  server-pushed `receipt_lines_mirror` synced to all terminals to enable
  cross-terminal refunds.
- HTTP-route wiring of `CustomerHistorySearchService`. Block 2.5c added the
  permission gate as defence-in-depth; the service is not yet exposed via an
  endpoint. When a controller is added, the existing permission check will
  apply without further service refactor.

