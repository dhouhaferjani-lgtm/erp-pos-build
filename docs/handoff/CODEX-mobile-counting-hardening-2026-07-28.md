# CODEX BRIEF — erp-mobile: counting offline-queue hardening

**Date:** 2026-07-28
**Target repo:** `/Users/houssamr/Projects/syneriva/erp-mobile` (separate repo — **the owner pushes this repo personally**)
**Server repo (read-only reference):** `/Users/houssamr/Projects/syneriva/apps/erp`
**Owner of merge:** the orchestrating Claude session hands you back a branch; **you do not push to any shared branch.**

---

## 0. Scope correction — read this first

This lane was originally scoped as "build live inventory counting on mobile." **That scope is wrong and is hereby cancelled.** A full audit on 2026-07-28 confirmed the live-counting mobile work is **already complete, merged (2026-07-07), and passing 89 counting tests across 11 suites, none skipped.**

Specifically, already DONE — do **not** rebuild any of it:

- `counted_at_device` + `device_now` on both the online path (`useSubmitCount.ts`) and the offline-drain path (`useBackgroundSync.ts`), with `device_now` correctly read **fresh at drain time** rather than reused from the stored `countedAt`. This was the subtlest requirement in the whole design and it is implemented correctly, with a dedicated regression test (`countSubmissionDeviceTime.test.tsx`).
- The one-field trap is guarded: `countingApi.submitCount()` only puts the two timestamps on the wire when **both** are truthy.
- `'zone'` scope end-to-end: draft store fields, location picker, zone multi-select, and correct `{location_id, zone_ids}` vs `{product_ids}` branching in both `draftSyncService` and `countingApi`.
- Session-header zone label, blocking banner, and live/ambiguity advisory banner.
- Onboarding-worklist screen (was marked optional; built anyway).
- Barcode scanning during count entry.

**Your job is what the audit found instead**: pre-existing offline-queue robustness gaps and one concrete data-integrity bug. Verify each citation below before changing anything; if a citation does not match, STOP and report.

---

## 1. Repo state as found (2026-07-28)

- Branch: `codex/location-hierarchy-mobile`, HEAD `b610560` (2026-07-13).
- **Working tree is dirty**: one unstaged documentation file, `docs/handoff/HANDOVER-mobile-scan-capture.md` (+5 lines, unrelated to counting).

**First action:** report the dirty file to the orchestrator and wait for a decision on it. Then create a dedicated branch off current HEAD for this work. Do not stash, discard, or commit someone else's uncommitted work.

---

## 2. Constraints

- **TDD.** Failing test first. The counting suites are green today — keep them green; run counting tests by path.
- **Strict TypeScript.** No `any`; use `unknown` + type guards.
- Quantities stay decimal **strings**; no `parseFloat`/`Number()` on quantity.
- All user-facing text through the existing i18n mechanism — the app's counting UI is French-facing (`"Ventes suspendues"`, `"Fenêtre d'ambiguïté"`). Match it.
- Follow existing patterns in this repo rather than importing server-repo idioms.

---

## 3. Tasks, in priority order

### B1 — Scan-to-draft silently corrupts the draft's product scope

**Size: M. This is a real bug and it hits the most common counting flow: building a product-scope count by scanning items.**

**The defect:** `app/(app)/counting/draft/[id]/scan.tsx` stores the **raw scanned barcode string** directly into `DraftCounting.productIds`. `draftSyncService.ts` (which carries its own `TODO(post-demo)` comment at the site) then sends it as `{ productId: <barcode> }` to `batchAddProducts` — **not** as `{ barcode }`.

Server side, `InventoryCountingController::batchAddProducts` only performs a barcode lookup when `productId` is **absent**. Since `productId` is always present here (holding a barcode string), the lookup path never fires, and the raw barcode is written straight into `scope_filters.product_ids` **with no existence validation**, while the API returns `"status": "success"`.

Consequence: the draft's product list is silently poisoned with non-UUID garbage. Activation / item generation against that list either produces no items for the scanned product or hard-fails — and the counter has no idea, because the scan appeared to succeed.

**Fix (client-side, preferred):** send `{ barcode: <value> }` when the value originated from the scan screen, so the server's existing lookup path resolves it — **or** resolve barcode → productId on the device before queueing. Choose based on what survives offline: the draft is built offline and synced later, so if client-side resolution needs a network call it is the wrong choice. State your reasoning in the report.

**Also report (do not fix here):** the server accepting an unvalidated `productId` into `scope_filters.product_ids` is a server-side robustness gap. Write it up for the orchestrator to route into the server lane — do **not** edit the server repo from this lane.

**Tests:** a scanned barcode produces a payload the server can resolve; a scan queued offline and drained later still resolves correctly; an unknown barcode surfaces a real error to the counter rather than a silent success.

---

### B2 — Pending counts retry forever with no ceiling and no recovery UI

**Size: M.**

`useBackgroundSync.ts` calls `markSyncError` on failure and leaves the item in the array, retried every 30s **indefinitely, with no cap**. Compare `draftSyncService.ts`, which has `MAX_RETRY_ATTEMPTS = 3` and parks a failed draft in a `sync_error` state with a dedicated recovery screen (`app/(app)/counting/sync-errors.tsx`).

A permanently-invalid pending count — e.g. the session was finalized server-side before the drain, or the item was removed — retries forever. The only signal is the generic `OfflineIndicator` showing "N operations syncing…" that never reaches zero. A counter cannot inspect, retry, or discard it.

**Implement:** a retry ceiling plus a recovery affordance for pending counts, mirroring the draft pattern already proven in this repo. Distinguish retryable (network/5xx) from terminal (4xx — session finalized, item gone) failures; terminal failures should park immediately rather than burn three attempts.

**Tests:** retryable failure retries up to the cap then parks; terminal failure parks immediately; a parked count is visible and discardable; parking one count does not stall the rest of the queue.

---

### B3 — `useStorageLimits()` is fully built, tested, and never called

**Size: S.**

`src/features/counting/services/storageLimits.ts` implements 2,000-pending-op / 50-draft / 500-products-per-draft caps and passes its unit tests, but a grep across `app/` and `src/` finds **zero call sites** outside its own test. Nothing currently stops a device from queuing unbounded pending counts and drafts offline.

**Either** wire it into the real call sites (`create-draft`, add-product/scan paths, `addPendingCount`) and surface the warnings, **or** delete the module if the limits are not wanted. Do not leave it dead. Recommend one and say why; if you wire it, the caps must degrade gracefully — warn and block *new* work, never discard already-captured counts.

---

### B4 — No idempotency key on count submission

**Size: S.**

Expenses use an idempotency key (`useLogExpense.ts`); counting does not. Combined with unbounded retry (B2), this creates a narrow but real failure: if the server processes a submission but the response is lost, the retry sends the **same** `counted_at_device` with a **fresh** `device_now`, recomputing skew from a different vantage point and potentially raising a `clock_skew` flag on a count that was fine.

Quantity is not at risk — `InventoryCountingItem::submitCount` is last-write-wins on quantity — but the replay boundary and flags are.

**Implement** a client-generated request ID, or check the item's already-counted state before resubmitting on retry. Cheap fix, removes a confusing class of false flags.

---

### B5 — No "flagged for review" state on the mobile session/item screens — DECISION NEEDED

**Size: S if in scope.**

The session item list distinguishes only `done` / `pending`. A count flagged server-side (`clock_skew`, basket-window ambiguity) shows the counter nothing, so they have no signal to recount on the spot — they walk away and the flag surfaces later on the web review page.

This may well be intentional (review belongs on web). **Do not build this without an explicit decision.** Write up the trade-off for the orchestrator to put to the owner, and stop there.

---

### B6 — Retire the stale handover doc

**Size: S. Server repo, not this one — hand back to the orchestrator.**

`apps/erp/docs/handoff/HANDOVER-live-counting-mobile.md` describes all of §0 above as outstanding mobile work. It has been done for three weeks. Anyone reading it fresh will duplicate work or distrust the app's state. It needs a status header marking it delivered, with a pointer to this brief. **Report this; do not edit the server repo from this lane.**

---

## 4. Review protocol

- After B1 and after B2, dispatch an adversarial review pinned to **Opus**, citing `file:line`, and **save the review to a file** under `docs/handoff/` in this repo — never inline-only.
- Fix, re-review, iterate to APPROVE before moving on.
- B3/B4 may share a single review round.

## 5. Definition of done

- B1, B2 complete with APPROVE review records; B3 and B4 complete or explicitly recommended-against with reasoning; B5 and B6 written up as decisions/handbacks, not silently built.
- Counting test suites still green (run by path), no new skips.
- A report at `docs/handoff/mobile-counting-hardening-report.md`: what changed, what you verified, what you could not verify, the server-side `batchAddProducts` validation gap from B1, and the B5 decision put cleanly to the owner.
- **The branch is left for the owner to push.** You do not push it.
