# Codex review — FU-1b offline approval-cache TTL

**Date:** 2026-06-15. **Branch:** `feat/offline-approval-operator-prune`.
**Reviewed commit:** `800607287`. **Fix commit:** `c447066cb`.
**Reviewer:** Codex (gpt-5-codex via codex-companion). **Verdict: APPROVE-WITH-MINOR-EDITS (0 BLOCKER).**

Process note: Codex cannot write into this worktree sandbox, so the review was
emitted verbatim and is recorded here with dispositions.

---

## Findings & dispositions

### 1. Placement — PASS
The TTL is enforced ONLY in `verifyScopedManagerPin` Case 5 (the
`isGenuineOfflineFailure` branch), not in the shared `verifyOfflineApprovalPin`
matcher. Confirmed a stale cache cannot block a server-confirmed online approval
(online return happens earlier). Codex audited every named offline-approval
entry point and found NO bypass: cash drawer (`CashDrawerModal.tsx:54`),
transaction discount (`DiscountModal.tsx:152`), line discount
(`LineDiscountModal.tsx:153`), EOD close (`Header.tsx:225`), tender tolerance
(`paymentStore.ts:1145`), refund/void (`refundApproval.ts:68`) all route through
`verifyScopedManagerPin`; direct `operator_pins` reads are ordinary login /
EOD-picker population, not final approval.

**Disposition: NO CHANGE.** Confirmed correct.

### 2. `isApprovalCacheFresh` correctness — LOW (future skew)
Null/unparseable fail closed; inclusive boundary `now - fetchedMs <= maxAgeMs`
correct. LOW: accepted ANY future `fetchedAt` with no cap, so a grossly future
server stamp / DB tampering could keep the cache fresh indefinitely.

**Disposition: FIXED (`c447066cb`).** Added `APPROVAL_CACHE_MAX_FUTURE_SKEW_MS`
(1 day): a `fetchedAt` more than a day ahead of the device clock fails closed.
1 day absorbs legitimate server/device skew while blocking gross future-stamping.
Added a test (far-future → stale; +60s → fresh).

### 3. Clock trust — LOW (accepted)
Device `Date.now()` vs server-stamped fetch time. Forward skew fails safe;
backward clock movement weakens the TTL (documented). Codex confirmed no better
wired trusted-time source: the backend returns `server_time` in
`PosAuthController::pinData` but `OperatorPinData` (`syncService.ts:99`) does not
model it, and fiscal `last_server_time_seen` is not exposed as an approval-time
oracle.

**Disposition: NO CHANGE (accepted limitation).** OS-clock rollback is outside
the current POS threat model; wiring a trusted-time oracle is a separate change.
The forward-skew cap (#2) bounds the worst future-stamp abuse.

### 4. TTL value (7 days) — PASS
Defensible for offline-first retail if multi-day-outage operation is a real
requirement. Codex noted the ordinary discount-permission cache is 24h
(`discountPermissions.ts:3`), so manager-override exposure is intentionally
broader. No value change recommended without business-SLA data.

**Disposition: NO CHANGE.** 7 days kept as the documented default; single named
constant, easy to tune if the owner sets an SLA.

### 5. Test adequacy — PASS
Helper tests cover fresh/stale/boundary/null/unparseable/future/custom-TTL (now
+ implausible-future). Offline fail-closed + stale-denial audit tests present.
The `Date.now() - 60_000` fresh fixtures are NOT flaky against a 7-day TTL
(≈6d 23h margin).

**Disposition: NO CHANGE.** Confirmed adequate.

---

## Verification after fixes
- `src/lib/operatorApproval/` suite: 37 passed (4 files).
- `tsc --noEmit`: 0 errors. `eslint` on changed files: 0 errors.
