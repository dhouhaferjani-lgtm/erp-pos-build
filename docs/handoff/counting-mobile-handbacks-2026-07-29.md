# Counting lane — hand-backs from the mobile hardening lane (B1–B6)

**Date:** 2026-07-29
**Mobile lane:** `erp-mobile` @ `codex/mobile-counting-hardening` (branch left for the owner to push)
**Mobile brief:** [`CODEX-mobile-counting-hardening-2026-07-28.md`](CODEX-mobile-counting-hardening-2026-07-28.md)
**Mobile report:** `erp-mobile:docs/handoff/mobile-counting-hardening-report.md`
**Server lane:** [`counting-completion-report.md`](counting-completion-report.md) (A1–A6, Phase 10.1.x)

The mobile lane finished B1–B4 with committed Opus APPROVE records. It deliberately did
not touch this repo. This document records what it handed back, verified against the code
on `dev` at `92b28dddd` — not against the mobile report's claims.

---

## 1. OPEN — `idempotency_key` is not honored on count submission

**Status: open server obligation. Mobile already ships the field.**

Mobile B4 now sends `idempotency_key` on **every** count submission: one UUID per logical
submission, reused across the ambiguous-network fallback, the persisted queue row, and every
background retry. Legacy queue rows derive a deterministic UUID from their row ID.

The server does not read it:

- `apps/api/app/Modules/Inventory/Presentation/Requests/SubmitCountRequest.php:27-39` —
  rules cover `quantity`, `notes`, `counted_at_device`, `device_now`. No `idempotency_key`.
- `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:266-272` —
  `submitCount()` takes no request-identity parameter.
- Route: `apps/api/app/Modules/Inventory/Presentation/routes.php:262`.

**Impact is bounded, not zero.** Quantity is safe — `submitCount` is last-write-wins. The
exposure is the replay boundary and the skew flags: if the server processes a submission
but the response is lost, the retry sends the **same** `counted_at_device` with a **fresh**
`device_now`, so skew is recomputed from a different vantage point and can raise a
`clock_skew` flag on a count that was fine.

**No breakage risk from shipping mobile first.** `SubmitCountRequest` does not prohibit
unknown fields, so the additive key is silently ignored by current servers. Mobile can ship
ahead of this work; it simply gets no dedup until it lands.

**Contract the mobile lane requires** (from its B3/B4 review record):

1. Accept `idempotency_key` as an additive, optional field.
2. Deduplicate **by the key alone** — replay-time metadata (`device_now`, and therefore
   derived skew) will legitimately differ between the original and the retry.
3. Return the **original success** for a replayed key, not a terminal 409/422.

**Precedent already in this module** — `StockTransfer` implements exactly this shape and is
the pattern to copy: `StoreStockTransferRequest.php:83` (`nullable|string|max:128`),
`StockTransferController.php:185`, `StockTransferService.php:106-152` (lookup by key inside
the transaction, persist the key on the row).

**Not implemented here — needs an owner call**, because it requires a migration to persist
the key, and pushing to `origin/dev` auto-deploys staging including `tenants:migrate`.
Sizing: one column + index, request rule, dedup lookup in the submit path, tests. Small,
but it is a schema change and belongs to a deliberate deploy, not a doc commit.

---

## 2. CLOSED — unvalidated `productId` in `scope_filters.product_ids`

Mobile B1 reported that `batchAddProducts` wrote an unvalidated `productId` straight into
`scope_filters.product_ids`. **This was already fixed in this lane** by A6 (`108d9455b`) and
the typed error codes (`980f19b9e`), both now merged to local `dev`:

- `InventoryCountingController.php:1163-1186` — rejects non-UUID IDs per item and IDs not
  resolving to a company product, keeping valid siblings in the same batch. Both branches
  emit `PRODUCT_NOT_FOUND` (`:1167`, `:1179`).
- `InventoryCountingController.php:1189-1217` — barcode fallback preserved; unknown barcode
  returns `PRODUCT_NOT_FOUND` (`:1210`) rather than silent success.

**Minor inconsistency found while verifying (not fixed here).** The typed-code work
(`980f19b9e`) standardized on UPPERCASE constants —
`BATCH_ERROR_PRODUCT_ALREADY_IN_COUNT = 'PRODUCT_ALREADY_IN_COUNT'` and
`BATCH_ERROR_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND'` (`:38`, `:40`) — but the A6
malformed-barcode branch at `:1195` still emits a bare lowercase literal `'invalid_barcode'`,
not a constant, in the same `data.errors[].code` field. `BatchAddProductsValidationTest.php`
asserts the two uppercase codes but has no case for `invalid_barcode`, which matches the
A1–A6 report's own note that this branch is statically reviewed and untested.

A client switching on `code` therefore sees mixed casing on one field. Mobile is unaffected
today: it only ever sends a string `barcode`, so it cannot reach this branch, and its
duplicate detection keys on `PRODUCT_ALREADY_IN_COUNT`. Low severity, but it is a
one-line constant plus one test in a lane that just shipped typed codes.

Mobile independently fixed its half (sends `{ barcode }` for scanned values, `{ productId }`
only for UUID-shaped legacy drafts) and switched duplicate detection to the typed
`PRODUCT_ALREADY_IN_COUNT` code with the English message kept as fallback. Both halves agree.

**Rollout note (already in the A1–A6 report, repeated because it is the sequencing risk):**
an old mobile build still putting a barcode in `productId` now gets an explicit per-item
error instead of false success. That is correct, but it means **the mobile B1 fix should
deploy before or with this lane**. Already-installed old builds will surface scan errors
until updated.

Drafts poisoned *before* A6 are not repaired by the prevention change. Check or recreate
suspicious staging drafts before testing.

---

## 3. CLOSED — stale mobile handover doc (B6)

[`HANDOVER-live-counting-mobile.md`](HANDOVER-live-counting-mobile.md) already carries the
`✅ DELIVERED — this handover is CLOSED` header pointing at the hardening brief. No action.

---

## 4. DECISION NEEDED — flagged-for-review state on mobile (B5)

Not built, by design; the brief required an explicit decision first.

Today the mobile session item list distinguishes only `done` / `pending`. A count flagged
server-side (`clock_skew`, basket-window ambiguity) shows the counter nothing, so they leave
the location and the flag surfaces later on the web review page.

- **Option A — keep review web-owned.** Preserves blind counting (no expected quantity or
  variance leaks to the counter), keeps triage where supervisors have context and
  permissions, adds no new mobile state machine. Cost: revisit travel when a recount is needed.
- **Option B — show a flagged/recount state on mobile.** Enables on-the-spot correction.
  Cost: any variance detail compromises blind counting; needs an authoritative server-driven
  state, permission rules, refresh/offline semantics, and carefully neutral copy.

**Mobile lane's recommendation: Option A for now.** If field operations show material
revisit cost, add only a neutral server-driven state (e.g. **Recomptage demandé**) carrying
no quantity, variance, reason, or other counters' values — as a separate product decision.

**Owner call required before any work.**

---

## 5. Mobile lane state (for the owner's push)

Branch `codex/mobile-counting-hardening`, working tree clean, 6 commits off `b610560`:

| Commit | Content |
|---|---|
| `b077279` | B1 design note |
| `65c640c` | B1 — draft scans sync as `{ barcode }` |
| `f2f1755` | B1 — prefer typed duplicate product errors |
| `37b02bf` | B2 — park failed offline counts (bounded retry + recovery UI) |
| `80f6eb8` | B3+B4 — offline storage caps + client request IDs |
| `5a90341` | carried-over scan-capture doc note (see below) |

Verified independently in this session, not taken from the report:
`npm test -- --runInBand src/features/counting src/ui/atoms/__tests__/OfflineIndicator.test.tsx`
→ **17 suites, 149 tests passed**; `npm run typecheck` → clean.

APPROVE records committed in that repo: `mobile-counting-hardening-B1-review.md`,
`-B2-review.md`, `-B3-B4-review.md`.

**The uncommitted file the brief flagged** (`docs/handoff/HANDOVER-mobile-scan-capture.md`,
left dirty by the scan-capture session) was verified accurate against server commit
`638cdaa99` — the duplicate-upload 422 does now return
`{error:{code:'DUPLICATE_DOCUMENT',...}}` with `errors.file` retained — and committed
unchanged as `5a90341` so it was not lost. Its content was not authored or altered here.
