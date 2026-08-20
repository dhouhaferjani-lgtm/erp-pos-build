I reviewed the full range and the fix round in code. Below is the round‑2 register.

---

## Adversarial merge-gate register — M1 (round 2)

Range: `60df88a01..HEAD` (`37c4b3a00` tip, 26 commits). Fix round under review: `e0eb2a2b8`, `34101009f`, `97484c442`, `055b6bd45`, `37c4b3a00`. Amending authority applied: the 2026‑08‑19 re‑pin + `preflight_policy` in `docs/handoff/progress/dn-consolidation-build.progress.yaml:10-42`. Brief `§3` (ratified OI‑8 conditions 1–4), `§6` and `§7` are unamended and still bind.

**Lens applicability.** **tenancy-authz** — applies; re‑verified over the fix round (`durableDeliveryNoteWinner` is tenant+company+type scoped on *both* sides of the join, `DeliveryNoteToInvoiceConverter.php:187-200`; marker reads in `DocumentConversionController.php:473-477` are company-scoped; routes unchanged from round 1). No finding. **treasury** — applies weakly; the fix round adds no GL/payment write, no reordering, no partial-write path. No finding. **inventory-costing** — applies; the currency revert (`34101009f`) touches only the DN list read path, no stock/WAC/cost surface. No finding. **general** — findings below.

### Round‑1 findings — disposition (verified in code, not from the report)

| R1 | Verdict |
|---|---|
| **1 (P1)** OI‑8 conditions 2–4 absent on the consolidation lane | **CLOSED.** `DeliveryNoteConsolidation.tsx:48-77` parses `error.details.documents[]`; `:303-384` renders a persistent `role="alert"` region with per‑DN number, taking invoice date, human lane label and `Open INV-XXXX` link; `:226-232` deselects exactly the named ids and keeps the rest. en+fr at `locales/{en,fr}/sales.json:619-632`. Non‑vacuous coverage incl. French at `DeliveryNoteConsolidation.billingRefusal.test.tsx:121-183`. |
| **2 (P2)** `GET /delivery-notes` narrowed to company currency | **CLOSED.** Row predicate removed (`DeliveryNoteController.php:103-108`); the predicate moved into `deliveryNoteAggregates()` (`:195`) which receives `clone $query` (`:115`). Deep clone confirmed at `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:2327-2331`. Regression `DeliveryNoteBillingProjectionTest.php:323-341` asserts `meta.total=2` / `aggregates.count=1`. |
| **3 (P2)** DN lane phantom 422 on retry exhaustion | **CLOSED.** `DeliveryNoteToInvoiceConverter.php:141-160` mirrors the SO guard; `durableDeliveryNoteWinner` (`:186-222`) requires marker + invoice row + payload triple agreement before translating, else rethrows `$previous`. Real PG regression `DeliveryNoteConsolidationConcurrencyTest.php:323-368` (deferred constraint trigger raising `40001`) asserts 500/`INTERNAL_ERROR`, zero invoices, zero markers, zero sequence consumption. Marker‑collision `23505` correctly fails `isRetryExhaustion` (`:163-175`) and propagates as the real refusal. |
| **4 (P2)** §7 evidence contract missing | **SUBSTANTIALLY CLOSED.** `HANDBACK-…:110-236` now carries the per‑spec‑item register with red‑first evidence, the lock inventory as built with the acyclicity argument and the explicit no‑writer‑reaches‑invoice‑before‑claims statement, OI‑8 1–4 itemised, the 5–7 unratified sub‑section, the OI‑14 proof, decisions/deviations/out‑of‑scope findings and deploy notes. One evidence statement in it is wrong — finding 1 below. |
| **5 (P2)** unchunked backfill in an unattended `tenants:migrate` | **CLOSED.** `2026_08_18_000002_…php:66-100` uses `chunkById(100)`; per‑row `exists()` dropped safely (table is created in the same transactional `up()`, and a completed table short‑circuits at `:31-39`). Regression `DeliveryNoteBillingMarkerMigrationTest.php:203-240` seeds 101 rows and asserts ≥2 `documents` reads — RED at one read before the fix. |
| **6–14 (P3)** | Carried, now disclosed in `HANDBACK-…:299-317`. Acceptable per §6's P3 rule. |

---

### P2 — fix before merge

**1. The consolidation refusal still fires a transient toast, and the handback asserts the opposite as OI‑8 condition‑1 evidence.**
`apps/web/src/features/documents/hooks/useDeliveryNotes.ts:153-155` · **CONFIRMED**
`useConsolidateDeliveryNotes` has an unconditional `onError: (error) => { toast.error(getErrorMessage(error)) }`. React Query fires `onError` for a `mutateAsync` rejection too, so the attributed 422 raises a toast *before* the component's `catch` at `DeliveryNoteConsolidation.tsx:214-223` sets the inline region. The SO lane deliberately does the opposite — `SalesOrderDetailPage.tsx:212-218` branches inside `onError` and suppresses the toast when `parseBillingRefusal` matches. The two lanes are asymmetric on the exact clause §3 condition 1 titles *"Persistent inline error — never a toast."*
Failure scenario: a clerk loses a race. A red sonner toast appears carrying the server's untranslated batch string (`getErrorMessage` → `error.message`, `lib/api.ts:87-91`, i.e. *"Delivery notes have already been invoiced"* — no `t()`), auto-dismisses, and the persistent region renders underneath. The transient, unattributed message is the one that grabs attention first. The new test cannot catch this: `DeliveryNoteConsolidation.billingRefusal.test.tsx:12-49` `vi.mock`s the whole `./hooks/useDeliveryNotes` module, so the real `onError` never runs.
The blocking part is the report, not the pixels: `HANDBACK-…:206-209` states under condition 1 *"Tests rerender or interact after failure and keep the `role=alert` region visible; **no toast is used**."* That is false for this lane and is exactly the class of unevidenced conditions‑1–4 claim round 1 filed as its P1. §7 item 10 makes a deviation discovered at gate time an invalidation of the handback. Close it either by mirroring the SO branch in the hook's `onError` (one `parseBillingRefusal` check) — preferred, it also removes the untranslated string — or by correcting the handback sentence to state that a redundant toast still fires and why that is acceptable.

---

### P3 — note / ticket

**2.** `apps/web/src/features/documents/components/DeliveryNoteConsolidation.tsx:226-232` — CONFIRMED. The button reads *"Remove these N and retry"* (`locales/en/sales.json:631`) but `removeRefusedDeliveryNotes()` only deselects and clears the region; it never resubmits. Brief §3 condition 4's consolidation parenthetical is *"deselect exactly the named rows, keep the rest selected, **resubmit**"*, and spec `:906` is *"marks exactly those two rows and leaves the rest selected"*. The deselect+keep half is built and tested (`…billingRefusal.test.tsx:148-166`); the resubmit half is not, and the handback justifies it (`:294-296`) by invoking the no‑auto‑retry posture — which is research 17 proposal **7**, unratified, and which concerns *client-initiated* retry, not a retry the operator explicitly clicked. Operator-visible effect: clicking a button labelled "retry" makes the error vanish with no submission and no confirmation. Related: the refused rows are named only in the alert region, never marked in the table itself, so they remain selectable on the next attempt.

**3.** `docs/handoff/progress/dn-consolidation-build.progress.yaml:96` — CONFIRMED. `verdict: null` while `docs/handoff/reviews/dn-consolidation-build/M1-round1.md` exists and is committed at `37c4b3a00`. The brief's banner requires the `--out` path in `verdict:` and the exit result in `last_verdict:` (the latter is correctly `CHANGES-REQUIRED`). Bookkeeping only; the resume point is still recoverable from the register directory.

**4.** `DeliveryNoteConsolidation.tsx:27-77` vs `SalesOrderDetailPage.tsx` — CONFIRMED. `parseBillingRefusal`, `isRecord`, `nullableString` and the `BillingRefusalDocument` shape are duplicated across the two lanes. The SO copy additionally carries `billed_order_line_ids`. Two divergent parsers for one server contract; a shared module under `features/documents/` would be the natural home. Not fixed here — M4 deletes the consolidation page, which is a defensible reason to leave it.

---

### Bypasses attempted that FAILED (the build held)

- **Aborted-connection defeat of the new exhaustion guard.** Hypothesis: on the *commit-time* exhaustion path the retrier throws at `DeliveryNoteBillingConcurrencyRetrier.php:53-55` **without** calling `rollBackFailedCommit()` (`:57` is on the retry path only), so `durableDeliveryNoteWinner`'s SELECT would hit `25P02` and replace `$previous` with a bogus driver error. Refuted by the SO-lane sibling test, which drives the identical deferred-`40001` path through the same code and asserts the surfaced payload contains `SQLSTATE[40001]` (`DeliveryNoteConsolidationConcurrencyTest.php:315-317`) — proving the post-unwind read executes cleanly and returns null rather than throwing.
- **Leak the aggregate currency predicate back into the row query.** `deliveryNoteAggregates()` mutates its argument (`:195`), so a shallow clone would re-narrow the list. Refuted: `Eloquent\Builder::__clone` deep-clones the underlying query builder (`vendor/…/Eloquent/Builder.php:2327-2331`), and `:323-341` asserts both counts in one request.
- **Get a real claim loss swallowed by the new catch.** The marker-collision `DeliveryNoteAlreadyClaimedException` carries a `QueryException`/`PDOException` chain with SQLSTATE `23505` (`DeliveryNoteBillingClaimService.php:90-96`); `isRetryExhaustion` (`:163-175`) matches only `40001`/`40P01`, so the original attributed exception is rethrown unchanged. The `affected !== 1` CAS arm throws with **no** previous and is rethrown at `:143-144`.
- **Get a DN falsely attributed on exhaustion.** `run()` is keyed on `$ids[0]` only, so I tried to make a batch report an unclaimed DN. Refuted: `durableDeliveryNoteWinner` requires a marker row, a joined invoice row, and payload `invoice_id`/`invoiced_via`/`invoiced_at` all agreeing with the marker (`:216-227`), all tenant+company+type scoped. It under-reports (names one DN) but cannot over-claim.
- **Double-insert markers now that the per-row `exists()` is gone.** Refuted: `up()` returns before the backfill when a complete marker table already exists (`:31-39`), and create+backfill share one transaction. `chunkById`'s cursor is stable because the backfill writes only to `delivery_note_billing_marks`, never to `documents`.
- **Escape the PHPStan rule with the new raw marker SQL.** The converters' new `DB::table('documents as delivery_note')->join('delivery_note_billing_marks as mark', …)->first()` is a read; `isForbiddenMethodWrite`/`isForbiddenStaticWrite` cover only `insert/insertOrIgnore/update/upsert/delete/save/statement` (`DeliveryNoteBillingWritesOnlyViaClaimService.php:135-184`), and the rule's docblock already disclaims coverage beyond enumerated literal forms — no overstated claim to file.
- **Break `finalise()`'s exact-N guard.** Both halves remain count-guarded independently, marker (`DeliveryNoteBillingClaimService.php:112-119`) and payload projection (`:121-137`); `claim()` is the only public entry, `reserve()`/`finalise()` protected (`:55`, `:107`).
- **Find a new gating hole.** Routes unchanged from round 1: `module:Sales` on both the new GET and the existing POST, `uninvoiced` ordered before `{deliveryNote}` which now carries `whereUuid` (`Document/Presentation/routes.php:271-293`). Four independent per-layer tests still present; the accountant seeder block and `permissionsMap.generated.ts` are still absent from the diff.

---

Round‑1's P1 and all four P2s are genuinely closed in code, with non‑vacuous red‑first regressions for each. What remains is one narrow P2 — a redundant toast on the consolidation refusal path plus the handback sentence that asserts its absence — and three P3 notes.

VERDICT: CHANGES-REQUIRED
