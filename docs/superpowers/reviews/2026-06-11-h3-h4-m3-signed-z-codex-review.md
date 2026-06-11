# REQUEST-CHANGES: H3/H4/M3 Signed-Z Adversarial Review

**Verdict:** REQUEST-CHANGES
**Confidence:** 88%
**Branch:** `feat/zreport-launch-blockers`
**Commit range reviewed:** `09bdbf88c..HEAD`
**Previously reviewed (excluded):** H1+H2 cash work up to `09bdbf88c`
**Date:** 2026-06-11

---

## Findings Summary

| ID | Severity | File:Line | Description |
|---|---|---|---|
| M3-1 | P1 | `apps/pos/src/stores/terminalStore.ts:214` | Anchor captures legacy `terminal_state.hash_sequence` instead of the fiscal-event operational sequence; after shift 1 the anchor is stale and the next Z can re-include previous-shift receipts. |
| M3-2 | P1 | `apps/pos/src/stores/terminalStore.ts:208` | Anchor write is fail-open for v3 shifts; on any DB/write failure the shift opens and Z silently falls back to the clock-based window — the exact unsafe path M3 is meant to eliminate. |

---

## H3 — Empty-Shift Guard Removal

**Summary verdict: APPROVE (no new issues introduced)**

OBSERVED: The empty-shift guard was removed so any shift close now proceeds through normal aggregation and persistence. With zero receipts, refunds, drawer ops, and account payments, `aggregateReportData` returns zero sales/refunds/VAT/payment totals; `expected_cash` becomes the opening cash amount; `buildReceiptSnapshots` returns `[]`; hash computation and Z-chain advancement still execute through the normal path.

INFERRED: H3 is directionally correct for server parity and nil-Z closure. The existing `getZReportByShift` guard still prevents re-generating a second local Z for the same `shiftId`; the Z is idempotent in the normal sense. No new double-close hole was found introduced by H3 itself.

---

## H4 — Legacy 409 Cutover Recognition

**Summary verdict: APPROVE (no new issues introduced)**

OBSERVED: `isLegacyZSyncRetiredError` requires `error instanceof ApiRequestError` AND exact server code `Z_SESSION_DEVICE_AUTHORITY_REQUIRED` (`apps/pos/src/lib/sync/syncService.ts:207–208`). The catch path marks the local legacy Z mirror synced only for that classifier (`apps/pos/src/lib/sync/syncService.ts:357–367`). Other errors still log, increment failure count, and chain-break errors still halt sync (`apps/pos/src/lib/sync/syncService.ts:370–378`).

INFERRED: H4 correctly distinguishes cutover from other 409s because the client classifier keys on the server-sent error code, not HTTP status alone. Marking the legacy mirror synced is safe for v3 cutover because the server rejects the legacy endpoint before payload validation, and the canonical Z authority is the device-authored `Z_REPORT` fiscal event sync path. No path was found where a legacy Z that should still sync is silently dropped: v2/non-cutover terminals should not receive this code, and non-code 409s do not match the classifier.

---

## M3 — Sequence-Anchor Receipt Window

**Summary verdict: REQUEST-CHANGES — two P1 findings**

### M3-1 (P1): Anchor Uses The Wrong Chain Head

**File:Line:** `apps/pos/src/stores/terminalStore.ts:214`

**Observed evidence:**

- OBSERVED in changed code: `authorShiftOpenFiscalEvents` reads terminal state, then writes `opening_hash_sequence: state.hash_sequence` to the new anchor table (`terminalStore.ts:214–218`).
- OBSERVED in changed code: Z aggregation selects all receipts for the terminal with `hash_sequence > opening_hash_sequence` (`zReportService.ts:156–163`).
- OBSERVED in migration comment: the anchor is documented as "the last receipt of the previous shift" (`migrations.ts:1572–1574`).
- OBSERVED in supporting code outside the diff: `offline_receipts.hash_sequence` is populated from `fiscalEventResult.sequence_number`, and `FiscalEventEngine` advances `terminal_state.fiscal_event_sequence` for operational receipt events — not the legacy `terminal_state.hash_sequence`.

**Reasoning:**

The M3 anchor must be strictly less than every receipt in the new shift and at least the last receipt sequence from the previous shift. The changed code does not capture that value. It captures `TerminalHashState.hash_sequence`, which is the legacy mirror field. Current v3 receipt rows store `hash_sequence` from the fiscal-event engine's operational sequence. Those are different terminal-state columns.

On a clean first shift this can look correct accidentally if both start at 0. After shift 1 has receipts with operational sequences 1..N, the legacy `hash_sequence` can still be 0/stale. Shift 2 records anchor 0. Because the Z query has only `terminal_id` AND `hash_sequence > 0`, shift 2's signed Z re-includes shift 1 receipts. That corrupts: `sales_count`, sales totals, VAT totals, payment-method totals, receipt snapshots, operational event range, grand totals, and the Z hash itself.

This is not pre-existing before `09bdbf88c`; it is introduced by the new anchor write and the new sequence-window query in this range.

**Recommended fix:**

Capture the same sequence stream used by `offline_receipts.hash_sequence`. For production v3 receipts that means the operational fiscal-event head (`terminal_state.fiscal_event_sequence`) or equivalently the current max persisted receipt `hash_sequence` for the terminal at shift open. Do **not** use legacy `terminal_state.hash_sequence`.

Add a regression test with two shifts:
1. Seed `terminal_state.hash_sequence = 0`, `terminal_state.fiscal_event_sequence = 2`.
2. Insert previous-shift receipts with `hash_sequence` 1 and 2.
3. Open shift 2 and record anchor 2.
4. Insert a new receipt with `hash_sequence` 3.
5. Generate Z and assert only sequence 3 is included.

---

### M3-2 (P1): Anchor Failure Falls Back To The Unsafe Clock Window

**File:Line:** `apps/pos/src/stores/terminalStore.ts:208`

**Observed evidence:**

- OBSERVED in changed code: anchor capture is wrapped in `try/catch`, with the comment that failure "must never block opening the shift" (`terminalStore.ts:204–208`).
- OBSERVED in changed code: the catch only logs and continues (`terminalStore.ts:221–222`).
- OBSERVED in changed code: if no anchor exists, `generateZReport` falls back to `created_at >= shiftOpenedAt` (`zReportService.ts:165–170`).

**Reasoning:**

M3's stated fiscal risk is a device clock rollback during a shift causing receipts to fall before `shiftOpenedAt` and be omitted from the signed Z. For a new schema-v3 shift on a clean-slate deployment, failing to record the anchor should not silently degrade to that known-unsafe selector. A missing anchor is only safe as a legacy compatibility path for shifts opened before migration 49 existed. For newly opened cutover shifts, the anchor is part of the fiscal closure invariant.

The fallback is therefore not "always safe." It is acceptable only when the shift genuinely predates anchor support. It is not acceptable after `authorShiftOpenFiscalEvents` has just attempted and failed to create an anchor for a v3 shift.

**Recommended fix:**

Make anchor capture mandatory for schema-v3 shift opens. If `getTerminalState`, repository import, or `insertShiftReceiptAnchor` fails, fail the shift open so no unanchored cutover session can start. Keep the `created_at` fallback only for explicitly legacy shifts (rows with no fiscal session context, or shifts opened before migration 49). Given the clean-slate context ("no deployed terminals, no prior Z hashes"), the simplest safe policy is: v3 shift open requires a persisted receipt anchor or the open fails.

---

## Checklist — M3 Specific Questions

| Question | Finding |
|---|---|
| (a) Anchor strictly < every new-shift receipt? | NOT GUARANTEED — wrong chain-head column (M3-1) |
| (b) Off-by-one (`>=` vs `>`)? | `>` is correct given a fixed anchor; no `>=` bug found |
| (c) Fallback safe if anchor write fails? | NOT ALWAYS SAFE — reintroduces rollback omission risk (M3-2) |
| (d) `shift_id` keying consistent? | CORRECT — anchor uses `shift.id`; receipt/account/refund mirrors also key by `shift.id` |
| (e) Re-generated Z re-aggregation risk? | NONE found — existing guard rejects already-persisted Z for same shift |
| (f) No upper bound assumption sound? | ACCEPTABLE under normal close-before-next-open workflow; no new upper-bound bug found in this range |

---

## Reviewer Notes

- Clean slate was confirmed: "no deployed terminals, no prior Z hashes" — this means M3-1 will manifest on the very first multi-shift sequence in production.
- H3 and H4 can be shipped independently of M3. M3 needs the two P1 fixes before merge.
- The `>` operator choice in the receipt window query is correct in principle; the bugs are in what value is written to the anchor, not the comparison direction.
