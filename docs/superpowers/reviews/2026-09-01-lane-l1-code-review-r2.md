# Lane L-1 — adversarial code review, round 2 (fix-round verification)

**Reviewer:** inventory-costing-reviewer (adversarial merge gate)
**Date:** 2026-09-01
**Under review:** `git diff d1a056465..HEAD` in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/l1-receipt`, branch `fix/l1-receipt-hardening`, single commit `5aa42aac9` on top of dev `d1a056465`. Working tree clean (`git status --porcelain` empty).
**r1 review:** `docs/superpowers/reviews/2026-09-01-lane-l1-code-review-r1.md` (MERGE-WITH-CONDITIONS)
**Lane response:** `docs/sessions/session-L-wave2-po-2026-09-01/lane-l1-summary.md` §"Fix round 1" (git-ignored, in `l1-receipt`)
**Nothing in `l1-receipt` was modified by this review.**

---

## VERDICT: spec ✅ + quality **MERGE**

All four r1 merge conditions (`L1-C1-01`, `L1-C1-02`, `L1-C1-03`, `L1-C1-04`) are **genuinely closed in code**, not just in prose, and each is now pinned by a test I ran myself. All four r1 minors that carried an action (`L1-C1-05`…`L1-C1-08`) are closed; the three record-only ones (`L1-C1-09`…`L1-C1-11`) are recorded as named tickets.

Re-verified this round and still true: no BLOCKER, no wrong quantity or cost at rest, no double or missing stock movement, no WAC arithmetic change, no float on money/quantity, no negative-lot path, no new `app()` in production code, no `mixed`, **no edits outside `apps/api/`**, K-1 fence untouched, deptrac flat at the dev baseline (183 → 183), PHPStan level 8 clean on the touched files, Pint pass.

Seven residual findings, all **MINOR**, none blocking. Two of them (`L1-C2-02`, `L1-C2-07`) are contract notes the orchestrator must respect when writing `wave2-po.part1.spec.ts`.

**What to fix before merge: nothing.** Carry `L1-C2-01`…`L1-C2-07` as follow-ups / spec notes.

---

## Disposition audit — every r1 finding

| r1 finding | Lane claim | My verdict | Evidence I read / ran |
|---|---|---|---|
| **`L1-C1-01`** MAJOR — `is_expired` edit leaks into non-receipt DEFAULT-lot writers with an operator-supplied PAST date | "receipt-only expired-at-mint; `findOrCreateBatch` takes an explicit `isExpired`, default `false`" | **CLOSED — and stronger than I asked for.** The lane did not just add pins, it made the non-receipt behaviour **byte-identical to base**. `BatchStockService.php:368` adds `bool $isExpired = false`; `:399` writes `'is_expired' => $isExpired`. Every non-receipt caller omits the argument: `BatchStockService::ensureDefaultBatch:100-108` (→ opening balances, flip-to-tracked, reservations, seeders) and `StockAdjustmentService.php:1105-1119`. Only `GoodsReceiptService.php:636-645` passes `isExpired: $this->expiredLotPolicy->isPastExpiry($lineBatch['expiry_date'])`. Grep of `findOrCreateBatch` across `app/`, `database/`, `tests/` returns exactly those three production call sites | Two new arms exist and are green on PG: `DefaultLotExpiryDriftRegressionTest.php:207-221` (past-dated **opening** lot → `is_expired === false`, positive `StockAdjustmentDocumentService` line posts, batch qty `2.0000`) and `:223-240` (past-dated **explicit non-receipt** lot → same). Both use `Carbon::setTestNow('2026-09-01')` so the predicate is genuinely exercised in its true branch |
| **`L1-C1-02`** MAJOR — expired-lot policy in five places (convention 11) | "`GoodsReceiptExpiredLotPolicy` is the sole owner of the past-date predicate, positive-quantity filter, document-order selection, line details and refusal sentence" | **CLOSED** (option (b) of my r1 fix, executed properly). New class `Inventory/Application/Services/GoodsReceiptExpiredLotPolicy.php`; `isPastExpiry` exists **once** (`:59-64`). Grep for `after_or_equal` across `app/` returns zero hits on any expiry-of-a-lot rule — both FormRequest rules are gone. Both producers now call the same object: `ReceiveGoodsRequest.php:24,101` and `GoodsReceiptService.php:56,949`. Residue: the generic `line %d (…)` label/`lineDetails` helper is still implemented twice — `L1-C2-03`, MINOR, and inert for the expired reason because both surfaces route through the policy | `GoodsReceiptExpiredLotPolicyTest.php:735-765` is a real byte-identity test (`reason`, `message`, **and** `details->toArray()` compared HTTP-vs-service) |
| **`L1-C1-03`** MAJOR — the two `EXPIRED_LOT_REFUSED` producers disagree on scope and on which line they name | "document-order selection + positive-quantity filter, shared" | **CLOSED.** `GoodsReceiptExpiredLotPolicy::firstRefusal:38` iterates `$document->lines->sortBy('line_number')`; `:39-41` skips lines failing `hasPositiveQuantity` (`:120-127`, `bccomp(...,4)`, no float). Both surfaces call it | Two new arms, green: `GoodsReceiptExpiredLotPolicyTest.php:134-152` (payload order deliberately reversed ⇒ document-order line named, `expectedSku: 'P-ORDER-FIRST'`) and `:154-172` (`quantities[zeroLine] = '0.0000'` with an older expired lot on it ⇒ that line is ignored, `expectedSku: 'P-POSITIVE-REFUSE'`). **Red-on-base spot-check by reasoning:** the summary's RED block reports precisely `-'…line 1 (P-ORDER-FIRST)…' / +'…line 2 (P-ORDER-SECOND)…'` and `-'…line 2 (P-POSITIVE-REFUSE)…' / +'…line 1 (P-ZERO-SKIP)…'` — expected = service (document order), actual = HTTP (payload order / zero-qty included). That is exactly what the pre-fix `firstPastExpiry` would emit, and could not be produced by any other bug. Diagnostic, not decorative |
| **`L1-C1-04`** MAJOR — standalone refusal returns a different `details` shape + a payload index | "duplicate rule and hand-built envelope removed; standalone reaches the same service producer" | **CLOSED.** `CreateStandaloneReceiptRequest.php` final diff adds only `use User`, `$canReceiveExpired`, and `'allow_expired' => $canReceiveExpired ? ['sometimes','boolean'] : ['prohibited']` — the `after_or_equal` rule and the whole `failedValidation` hijack are gone. `StandaloneReceiptController.php:50` threads `allow_expired`; `:54-63` catches `GoodsReceiptException` before `\DomainException` and emits `reason` + `details` | `GoodsReceiptExpiredLotPolicyTest.php:476-498` asserts `error.details.lines.0.line_number === 1`, `.sku === 'P-STANDALONE-EXPIRED'`, `.description === 'P-STANDALONE-EXPIRED'`, `.supplied_expiry === '2020-01-01'`, then a permitted override 201s. Summary's RED `"Failed asserting that null is identical to 1"` is the pre-fix shape (no `lines[]`) — consistent |
| **`L1-C1-05`** MINOR — refusal ordering reorder unpinned | "pin added" | **CLOSED.** `GoodsReceiptErrorMessageHygieneTest.php` arm *batch prepass does not shadow over receipt when batch data is supplied* — tracked product, `10.0001` against `10.0000`, **batches supplied** ⇒ `OVER_RECEIPT` | Green (8 passed / 42 assertions, was 7/40) |
| **`L1-C1-06`** MINOR — unguarded `find()` on a route-supplied id, no `whereUuid` | "route now carries `whereUuid`" | **CLOSED and in scope.** `Document/Presentation/routes.php:305` — exactly one line added, on `purchase-orders/{purchaseOrder}/receive` only. Closes both the new FormRequest query and the pre-existing `PurchaseOrderController.php:772-775` one | `GoodsReceiptExpiredLotPolicyTest.php:187-195` posts `/purchase-orders/not-a-uuid/receive` ⇒ 404. Summary's RED shows the PG `22P02` 500 before the fix |
| **`L1-C1-07`** MINOR — two stacked docblocks | "merged" | **CLOSED.** `HandlesDocuments.php:313-317` is one block: description, blank line, `@param … $extra` | Pint `{"result":"pass"}` |
| **`L1-C1-08`** MINOR — second-of-everything one arm short | "both pins added" | **CLOSED.** `GoodsReceiptBatchExpiryConflictTest` arm *same lot received at two locations has one lot and two stock rows* (1 `product_batches`, 2 `inventory_batch_stock`, locations asserted canonically) and arm *expiry conflict is company scoped in a second company* (second `Company` + membership + own MAIN-B + own supplier + own SKU `P-LOT-7-B`; conflict fires and `details.lines.0.sku === 'P-LOT-7-B'`) | Green (7 passed / 41 assertions, was 5/31) |
| **`L1-C1-09`** MINOR — `is_expired` not refreshed on lot reuse | ticket `L-1-FU-is-expired-refresh-on-reuse` | **CLOSED as ticket.** Behaviour confirmed unchanged: `BatchStockService.php:387-389` still returns an existing lot untouched |
| **`L1-C1-10`** MINOR — scale-4 strings in `error.details` | hand-off to lane L-2 | **CLOSED as hand-off.** `GoodsReceiptService::quantity()` still `bcadd($value,'0',self::QUANTITY_SCALE)` — canonical, correct, must be `formatQuantity`-ed at display |
| **`L1-C1-11`** MINOR — `BatchExpiryDailyCheckCommandTest:135` red on base | ticket `L-1-FU-batch-expiry-daily-check-context` | **CLOSED as ticket** (established pre-existing in r1) |

**Summary claim check:** "50 tests / 287 assertions" — I measured 6+8+4+20+7+5 = **50 tests** and 38+42+19+130+41+17 = **287 assertions**. Exact.

---

## Commands I ran (verbatim results)

All PG legs: `cd apps/api && DB_DATABASE=autoerp_test_l DB_CENTRAL_DATABASE=autoerp_test_l_central php artisan test -c phpunit-pgsql.xml --no-ansi [--compact] <path>` — one file at a time, never the full suite.

### The six lane files (the fix round's own gate)

```
   PASS  Tests\Feature\Inventory\GoodsReceiptReceiveAllBatchDataTest      6 passed (38 assertions)   37.84s
   PASS  Tests\Feature\Inventory\GoodsReceiptErrorMessageHygieneTest      8 passed (42 assertions)   32.57s
   PASS  Tests\Feature\Inventory\GoodsReceiptVariantBatchScopingTest      4 passed (19 assertions)   38.85s
   PASS  Tests\Feature\Inventory\GoodsReceiptExpiredLotPolicyTest        20 passed (130 assertions)  57.25s
   PASS  Tests\Feature\Inventory\GoodsReceiptBatchExpiryConflictTest      7 passed (41 assertions)   22.38s
   PASS  Tests\Feature\BatchExpiry\DefaultLotExpiryDriftRegressionTest    5 passed (17 assertions)   13.75s
```

The two `L1-C1-01` arms named in full, because they are the condition:

```
  ✓ past dated opening lot stays adjustable until the expiry job marks…  0.44s
  ✓ past dated explicit lot stays adjustable when minted outside receiv… 0.41s
```

and the two `L1-C1-03` arms:

```
  ✓ http and service refusals choose document order over batch payload…  1.67s
  ✓ http and service refusals ignore expired batches on zero quantity l… 1.39s
```

### `L1-C1-01` blast-radius neighbours (the non-receipt lot writers)

```
   PASS  Tests\Feature\BatchExpiry\EnsureDefaultBatchTest                 7 passed (20 assertions)   15.33s
   PASS  Tests\Feature\Inventory\OpeningBalancePostingServiceTest         4 passed (13 assertions)   12.66s
   PASS  Tests\Feature\Inventory\OpeningLotExpiryW41Test                 15 passed (23 assertions)   18.96s
   PASS  Tests\Feature\BatchExpiry\BatchStockServiceVariantTest           5 passed (12 assertions)   14.79s
```

`EnsureDefaultBatchTest` green is the load-bearing one for `L1-C1-01`: it exercises `ensureDefaultBatch`'s five expiry shapes (configured shelf life, undated, explicit override beating shelf life, idempotent re-run, top-up) with the new parameter defaulted — i.e. the mechanism cannot leak into DEFAULT-lot callers.

### Receipt neighbours most likely to regress from the fix round

```
   PASS  Tests\Feature\Inventory\GoodsReceiptTest                        25 passed (88 assertions)   62.61s
   PASS  Tests\Feature\Inventory\GoodsReceiptLedgerWriteTest             17 passed (105 assertions)  39.65s
   PASS  Tests\Feature\Document\GoodsReceiptConversionUnbalancedGlNarrowingTest  4 passed (12 assertions)  12.70s
   PASS  Tests\Feature\Inventory\GoodsReceiptPriceOverrideTest            6 passed (33 assertions)   20.01s
   PASS  Tests\Feature\Procurement\StandaloneReceiptServiceTest          12 passed (72 assertions)   39.04s
   PASS  Tests\Feature\Procurement\StandaloneReceiptIdempotencyRecoveryTest  2 passed (12 assertions)  16.93s
   PASS  Tests\Feature\Inventory\GoodsReceiptServiceVariantTest           4 passed (23 assertions)   16.76s
   PASS  Tests\Feature\Inventory\GoodsReceiptDestinationTest              3 passed (21 assertions)   16.60s
   PASS  Tests\Feature\Inventory\ReceiptBatchAllocationTest               3 passed (7 assertions)    24.03s
   PASS  Tests\Feature\Inventory\PurchaseBonusGoodsReceiptTest            3 passed (18 assertions)   16.89s
```

`GoodsReceiptConversionUnbalancedGlNarrowingTest` 4/4 again proves the new `catch (GoodsReceiptException …)` in all three controllers did not downgrade the chokepoint-balance **500** arm.

### Static gates

```
$ ./vendor/bin/pint --test <22 changed PHP files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress app/Modules/Inventory/Application/Services/GoodsReceiptExpiredLotPolicy.php
 [OK] No errors

$ ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress app/Modules/BatchExpiry/Application/Services/BatchStockService.php
 [OK] No errors

$ ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress <the 6 new test files>
 [OK] No errors

$ ./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress
  Violations 183   Uncovered 13703   Allowed 14621        (dev base: Violations 183)
```

One PHPStan caveat, chased to ground — see `L1-C2-06`: analysing **all 22** changed files in one invocation additionally reports 3 `method.nonObject` errors in `BatchExpiryDailyCheckCommand.php:142,203` and `CriticalBatchExpiryNotification.php:44`. Those files are **not in the diff**. I reproduced them identically on the untouched base checkout:

```
$ cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api   # base, HEAD 21233d9e2
$ ./vendor/bin/phpstan analyse … app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php app/Modules/BatchExpiry/Notifications/CriticalBatchExpiryNotification.php
  142  Cannot call method toDateString() on Carbon\Carbon|null.
  203  Cannot call method toDateString() on Carbon\Carbon|null.
   44  Cannot call method toDateString() on Carbon\Carbon|null.
 [ERROR] Found 3 errors
```

and the same base subset of *this lane's* modified files is clean (`[OK] No errors`). Partial-scope model-property artifact on untouched files, present on base ⇒ **not this lane**.

### Scope fences re-verified

```
$ git diff --name-only d1a056465..HEAD | grep -v '^apps/api/'
(none outside apps/api)

$ git diff -U0 d1a056465..HEAD -- …/PurchaseOrderController.php | grep '^@@'
@@ -28,0 +29 @@
@@ -797,0 +799 @@ … @@ -823,0 +826 @@ … @@ -839,0 +843 @@ … @@ -843 +847,7 @@ … @@ -856,0 +867,5 @@
```

K-1 fence `PurchaseOrderController.php:91-130` — **untouched**. `apps/web` — **untouched**. The only `routes.php` change is `+ ->whereUuid('purchaseOrder')` on the receive route (`Document/Presentation/routes.php:305`), which is exactly the `L1-C1-06` fix and nothing else.

---

## Findings

### `L1-C2-01` **[MINOR]** — `apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:64-73,92-99` — the expired-lot lookup runs on **every** receive request, not only when a past expiry was supplied

`withValidator`'s `after` callback calls `resolveExpiredLotRefusal()` unconditionally whenever `allow_expired` is falsy — which is every normal receive. That executes `CompanyContext::requireTenantId()` + `requireCompanyId()` and a full `Document::with('lines')->find()` before the controller then loads the *same* document again via `baseQuery()` (`HandlesDocuments::baseQuery`, `PurchaseOrderController.php:772-775`). Two identical document+lines loads per receive.

Not a defect: the context requirement is the same one `baseQuery()` already imposes (`CompanyContext::requireCompanyId()` throws the identical `RuntimeException`), so this introduces **no new 500 class**; and the query is indexed by PK. But it is avoidable work on the hot path.

**Fix (follow-up).** Early-return before the document query when no entry in `batchExpiryMap()` satisfies `GoodsReceiptExpiredLotPolicy::isPastExpiry()` — the predicate is already public and pure.

### `L1-C2-02` **[MINOR]** — `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptExpiredLotPolicy.php:106-110` — the non-disclosing fallback fabricates `line_number: 1`

When the route document is not visible in the active tenant/company/`PurchaseOrder` scope, `firstSanitizedRefusal` returns `['line_number' => 1, 'sku' => null, 'description' => '']`, so the client gets `error.details.lines[0].line_number === 1` and the sentence `Cannot receive expired lot for line 1: expiry date … is before today.` even when the offending payload key was not line 1 — and even when the PO does not exist at all (422 instead of 404).

The non-disclosure intent is right and the two arms that pin it (`GoodsReceiptExpiredLotPolicyTest.php:226-282`) are excellent. But a **fabricated** line number is worse than an absent one: a consumer that trusts `details.lines[0].line_number` gets a wrong answer rather than no answer. There is also no oracle risk in returning nothing here — any past-expiry payload already 422s regardless of document existence.

**Fix (follow-up).** Emit `lines: []` (the DTO already omits an empty `lines` key, `GoodsReceiptFailureDetails.php:30-32`) and a label-free sentence for the sanitized branch.

**Spec note for the orchestrator:** do not write a `wave2-po.part1.spec.ts` row that asserts `details.lines[0]` on a cross-company / non-PO / unknown-id receive — that shape is deliberately blind.

### `L1-C2-03` **[MINOR]** — `GoodsReceiptExpiredLotPolicy.php:129-157` duplicates `GoodsReceiptService.php:1075-1102` (`lineDetails` + label builder)

`lineDetails()` and `label()`/`labelFromDetails()` are implemented twice with identical bodies (same `trim`, same `description === sku` de-duplication, same `' — '` joiner, same `line %d%s` format). The **expired** reason is immune — both surfaces render it through the policy — but the other six reasons render through the service copy, so a wording change applied to one class silently splits the label vocabulary across reasons.

This is the residue of `L1-C1-02`; the load-bearing half (one producer per reason, cross-surface byte-identity) **is** closed. Recording it so the next lane does not add a third copy.

**Fix (follow-up ticket `L-1-FU-receipt-line-label-single-source`).** Move `lineDetails`/`labelFromDetails` onto `GoodsReceiptFailureDetails` (or a small `ReceiptLineLabel` value object in `Inventory\Domain`) and have both classes call it.

### `L1-C2-04` **[MINOR]** — `ReceiveGoodsRequest.php:76-90` — the expired-lot hijack suppresses every co-occurring validation error

`failedValidation` short-circuits to the `GOODS_RECEIPT_FAILED` envelope as soon as `$this->expiredLotRefusal !== null`, discarding the `errors` bag. A request that is *both* malformed (e.g. a bad `received_unit_prices` scale) *and* carries a past expiry reports only the expiry. The operator fixes the date, resubmits, and gets a second, different 422.

Both outcomes are actionable no-op 422s and the FE only ever submits one shape, so this is cosmetic. Recording it because the `error.code` flips between `GOODS_RECEIPT_FAILED` and `VALIDATION_ERROR` across the two attempts.

### `L1-C2-05` **[MINOR / hand-off, not a defect]** — the `allow_expired` override has **no operator surface**

The permission (`RolesAndPermissionsSeeder.php:149`, granted at `:572`) and both API gates ship, but no `apps/web` change was in scope, so `ReceiveGoodsDialog` never sends `allow_expired`. An operator who legitimately receives expired stock (the parapharmacy scrap case that `ProductOpeningStockPhase.php:161-166` documents as ruled legal) sees the refusal with **no way to proceed from the UI**. The refusal sentence does not tell them one exists either — the hint string at `ReceiveGoodsRequest.php:71` is discarded by the hijack.

Correct for this lane (the brief forbids `apps/web` edits). **Hand-off to lane L-2:** a permission-gated "receive anyway" affordance, plus surfacing `error.reason === 'EXPIRED_LOT_REFUSED'` as the trigger.

### `L1-C2-06` **[MINOR / not this lane]** — 3 PHPStan `method.nonObject` errors surface on `BatchExpiryDailyCheckCommand.php:142,203` and `CriticalBatchExpiryNotification.php:44` under partial-scope analysis

Reproduced verbatim on the base checkout for the same two files, and absent when the lane's own files are analysed. Neither file is in the diff and neither is in `phpstan-baseline.neon`. Same family as `L1-C1-11` (that file's `BatchExpiryDailyCheckCommandTest` is also red on base). Fold into ticket `L-1-FU-batch-expiry-daily-check-context`; do **not** attribute to L-1 at promotion time.

### `L1-C2-07` **[MINOR / spec note]** — a refused **standalone** receipt leaves a `Cancelled` auto-purchase-order row

`StandaloneReceiptService::execute` creates the auto-PO in its own transaction before the receipt transaction; on refusal `compensateFailedReceiptCreation` sets `status = Cancelled` + `cancelled_at`/`cancellation_reason` and deletes the idempotency key rather than deleting the document. So the standalone expired refusal is a no-op on stock/lots/GL but **not** on `documents`. Unchanged by this lane (pre-existing compensation contract, and `StandaloneReceiptIdempotencyRecoveryTest` 2/2 + `StandaloneReceiptServiceTest` 12/12 pin it), and the lane's seven-table repeat-refusal test (`GoodsReceiptExpiredLotPolicyTest.php:536+`) deliberately does not cover `documents`.

**Spec note:** a `wave2-po.part1.spec.ts` standalone arm must not assert "zero new documents" after a refusal, and must use a **fresh idempotency key** on retry only if it wants a fresh auto-PO (the same key is freed and replays cleanly).

---

## Declared, accepted gaps (no action)

- **Draft aging.** `post()` re-authorises the actor (`GoodsReceiptService.php:236` → `:388-398`) but does not re-evaluate expiry. A draft created while the lot was fresh and posted after it expires mints `is_expired = true` without a permission check. The lane declined this deliberately, citing brief r2's fence ("policy boundary at `createDraft`, permission re-authorisation at `post`"). Correct scope call; the outcome is truthful, not corrupt.
- **`is_expired` is set once at mint** (`L1-C1-09`) — an existing lot re-received is not refreshed until `batch-expiry:daily-check`.
- **Converter.** `PurchaseOrderToGoodsReceiptConverter.php:140,143` still passes `[]` for `$batchData`, so converting a batch-tracked PO now refuses at `createDraft` with `BATCH_DATA_REQUIRED` instead of at post. Same net 422, ticket `L-1-FU-converter-batches` already filed.

---

## Seeder / ops precondition — unchanged from r1, still owner-owed

`goods-receipt.receive-expired` is added to `permissionNames()` (`RolesAndPermissionsSeeder.php:149`) and granted to the `manager` grant list (`:572`), proven live by `GoodsReceiptExpiredLotPolicyTest` arms *manager role receives the expired lot override permission* and *tenant team permission carries to a real second company…*. There is **no migration and no backfill command** — by design. Therefore, before re-running the browser spec and before staging promotion:

```
php artisan tenants:run db:seed --class=RolesAndPermissionsSeeder
```

Without it, `W2-LOT-6`'s override arm 422s with `VALIDATION_ERROR` on `allow_expired` (looks like a code defect, is not), and `W2-LOT-10` can never rebuild its expired-lot fixture — after this lane the override is the **only** way to create an expired lot through the receipt path.

---

## AFTER strings for `wave2-po.part1.spec.ts` — re-derived from the final code

All HTTP **422** unless stated. Each row was re-derived from the producing statement **and** cross-checked against a live assertion in a test I ran.

| Spec row | `error.code` | `error.reason` | `error.message` (exact) | `error.details` | Produced at | Pinned by |
|---|---|---|---|---|---|---|
| **W2-LOT-5** — tracked line, no `batches` | `GOODS_RECEIPT_FAILED` | `BATCH_DATA_REQUIRED` | `Batch data is required for batch-tracked products: line 1 (P-LOT-1).` | `lines: [{line_number: 1, sku: "P-LOT-1", description: "P-LOT-1"}]` | `GoodsReceiptService.php:996-1010` via `createDraft:130` | `GoodsReceiptReceiveAllBatchDataTest.php:107-111` |
| **W2-LOT-5** — two tracked lines missing | `GOODS_RECEIPT_FAILED` | `BATCH_DATA_REQUIRED` | `Batch data is required for batch-tracked products: line 1 (P-TRACKED-A), line 2 (P-TRACKED-B).` | `lines` has **2** entries, document order | same | `…:170-172` |
| **W2-LOT-5** — `batches` only, `quantities` omitted | — | — | — | — | **200**; 1 `goods_receipts`, 1 `product_batches`, `inventory_batch_stock` = `stock_levels` | `…` arm *receive all threads batches when quantities are omitted* |
| **W2-LOT-6** — past expiry, no flag | `GOODS_RECEIPT_FAILED` | `EXPIRED_LOT_REFUSED` | `Cannot receive expired lot for line 1 (P-LOT-6): expiry date 2020-01-01 is before today.` | `lines: [{line_number, sku, description}]`, `supplied_expiry: "2020-01-01"` | `GoodsReceiptExpiredLotPolicy.php:69-83`, reached from `ReceiveGoodsRequest.php:76-90` (validation fires first) **and** byte-identically from `GoodsReceiptService.php:935-952` | `GoodsReceiptExpiredLotPolicyTest.php:119-132` + the HTTP/service identity helper `:735-765` |
| **W2-LOT-6** — `allow_expired: true` **with** permission | — | — | — | — | **200**; lot `expiry_date = 2020-01-01`, **`is_expired = true`**, balanced ledger | arm *permitted override creates truthfully expired lot and balanced ledgers* |
| **W2-LOT-6** — `allow_expired: true` **without** permission | `VALIDATION_ERROR` | *(absent — assert `null`)* | Laravel `prohibited` message | standard `error.errors.allow_expired` bag | `ReceiveGoodsRequest.php:53` | `…:174-185` (asserts `error.reason` is `null` and `GoodsReceipt::count() === 0`) |
| **W2-LOT-6** — expired **draft** (`save_as_draft: true`), no flag | `GOODS_RECEIPT_FAILED` | `EXPIRED_LOT_REFUSED` | same sentence | same | validation, before any persistence | `…:438-447` (`GoodsReceipt::count() === 0`) |
| **W2-LOT-7** — 2nd tranche, different expiry | `GOODS_RECEIPT_FAILED` | `BATCH_EXPIRY_CONFLICT` | `Batch LOT-SAME for line 1 (P-LOT-7) already has expiry date 2027-01-31; supplied expiry date 2028-01-31 conflicts.` | `lines: […]`, `batch_number: "LOT-SAME"`, `stored_expiry: "2027-01-31"`, `supplied_expiry: "2028-01-31"` (**raw as supplied**, not canonicalised) | `GoodsReceiptService.php:1019-1062 (throw at :1049)` | `GoodsReceiptBatchExpiryConflictTest.php:117-121` |
| **W2-LOT-7** — 2nd tranche, **same** expiry | — | — | — | — | **200**, one lot, quantities summed | arm *same expiry reuses lot and sums quantities* |
| **W2-LOT-7** — 2nd tranche, equivalent non-canonical date (`January 31, 2027`) | — | — | — | — | **200** — comparison canonicalises the supplied date | arm *equivalent noncanonical date does not create a false conflict* |
| **W2-LOT-8** — line **with** `variant_id` | — | — | — | — | **200**, `product_batches.variant_id` = that variant | `GoodsReceiptVariantBatchScopingTest` |
| **W2-LOT-8** — variant-bearing product, no `variant_id`, **batches supplied** | `GOODS_RECEIPT_FAILED` | `VARIANT_REQUIRED` | `Product P-LOT-8 on line 1 has active variants; batches must be variant-scoped — a variant_id is required.` | `lines: [{line_number: 1, sku: "P-LOT-8", description: …}]` | `GoodsReceiptService.php:648-657` | `GoodsReceiptErrorMessageHygieneTest.php:189-190` |
| **W2-OVER-1** — paid | `GOODS_RECEIPT_FAILED` | `OVER_RECEIPT` | `Cannot receive more than ordered for line 1 (P-OVER-1): requested more than the remaining quantity.` | `lines: [{line_number: 1, sku: null, description: "P-OVER-1"}]`, `ordered: "10.0000"`, `already_received: "0.0000"`, `requested: "10.0001"`, `remaining: "10.0000"` | `GoodsReceiptService.php:333-347` | `GoodsReceiptErrorMessageHygieneTest.php:101-107` |
| **W2-OVER-1** — free | `GOODS_RECEIPT_FAILED` | `OVER_RECEIPT_FREE` | `Cannot receive more free quantity than ordered for line 1 (<desc>): requested more than the remaining free quantity.` | same four keys (`ordered: "1.0000"`, `requested: "1.0001"`) | `GoodsReceiptService.php:354-368` | `…:135-137` |
| **W2-LOT-11** — `batches` entry with **no** `expiry_date` | `VALIDATION_ERROR` | *(absent)* | Laravel `required_with` message | `error.errors["batches.<lineId>.expiry_date"]` | `ReceiveGoodsRequest.php:51` — rule line **byte-identical to base**, no `after_or_equal` was ever left behind | `GoodsReceiptExpiredLotPolicyTest.php:518-534` |
| **W2-LOT-11** — `expiry_date: null` | `VALIDATION_ERROR` | *(absent)* | Laravel `required_with` message | same key | same | same |
| post-time refusals (`POST /goods-receipts/{id}/post`) | `GOODS_RECEIPT_POST_FAILED` | any of the above | — | as above | `GoodsReceiptController.php:126-135` | arm *expired override is persisted and posting actor is reauthorized* (also asserts PO stays `Confirmed`, zero `stock_movements`) |
| standalone refusals (`POST /goods-receipts/standalone`) | `STANDALONE_RECEIPT_FAILED` | as above — `EXPIRED_LOT_REFUSED` verified | same sentences | **now carries `lines[]` with a real `line_number`, `sku`, `description`** + reason-specific keys | `StandaloneReceiptController.php:54-63` | `GoodsReceiptExpiredLotPolicyTest.php:476-498` |
| `/purchase-orders/not-a-uuid/receive` | — | — | — | — | **404** at routing (`routes.php:305`) | `…:187-195` |

### Traps that still bind when updating the spec

1. **`W2-LOT-8` needs `batches` in the payload.** The batch-data pre-pass at `createDraft:130` fires `BATCH_DATA_REQUIRED` before the variant guard at `:648` is ever reached. Send `batches[lineId]` on the null-variant arm or the row measures the wrong reason. (Now pinned from the other side too: `GoodsReceiptErrorMessageHygieneTest` arm *batch prepass does not shadow over receipt when batch data is supplied*.)
2. **`W2-LOT-7`'s second expiry must be in the FUTURE.** A past second expiry is refused as `EXPIRED_LOT_REFUSED` at validation and `BATCH_EXPIRY_CONFLICT` is never produced. `2028-01-31` against a stored `2027-01-31` is the right pair.
3. **The over-receipt label has no SKU.** `assertQuantitiesWithinRemaining` takes no `Product` on either call path, so `details.lines[0].sku` is always `null` and the label renders the *description*. It reads `P-OVER-1` only because the harness defaults `description` to the SKU.
4. **`details.lines[].description` is NOT omitted when it equals the SKU** — only the human *label* de-duplicates. Assert `details.lines.0.description === 'P-LOT-1'`, not `null`.
5. **New this round — `error.details` key order is `lines` first**, then `ordered / already_received / requested / remaining / batch_number / stored_expiry / supplied_expiry` (`GoodsReceiptFailureDetails::toArray():26-49`). Use `assertJsonPath`, never a whole-object identity assertion on ordering.
6. **New this round — the receive route is now `whereUuid`-constrained.** Any spec row that previously expected a 422/500 from a malformed PO id now gets **404**.

---

**What to fix before merge: nothing — merge.** Then: run `tenants:run db:seed --class=RolesAndPermissionsSeeder` against the session-L tenant before re-running `wave2-po.part1.spec.ts`, apply the AFTER-strings table above, and open `L-1-FU-receipt-line-label-single-source`, `L-1-FU-is-expired-refresh-on-reuse`, `L-1-FU-converter-batches`, `L-1-FU-batch-expiry-daily-check-context` (now also covering the 3 partial-scope PHPStan errors).
