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
