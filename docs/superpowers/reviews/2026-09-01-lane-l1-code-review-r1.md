# Lane L-1 — adversarial code review, round 1

**Reviewer:** inventory-costing-reviewer (adversarial merge gate)
**Date:** 2026-09-01
**Under review:** uncommitted working tree of `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/l1-receipt` (branch `fix/l1-receipt-hardening`, base `dev` `d1a056465`)
**Brief:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L1-RECEIPT-HARDENING-BRIEF.md` (r2)
**Lane summary:** `docs/sessions/session-L-wave2-po-2026-09-01/lane-l1-summary.md` (in `l1-receipt`, git-ignored)
**Nothing in `l1-receipt` was modified by this review.**

---

## VERDICT: spec ✅ (all five findings implemented as briefed) + quality **MERGE-WITH-CONDITIONS**

Every acceptance leg I ran is green, PHPStan level 8 is clean on the touched files, Pint passes, deptrac is flat at the dev baseline (183 → 183), and I found **no BLOCKER**: no wrong quantity or cost at rest, no double or missing stock movement, no WAC arithmetic change, no float on money/quantity, no negative-lot path, no new `app()`, no `mixed`, no edits under `apps/web/**`, and the K-1 fence (`PurchaseOrderController.php:91-130`) is untouched.

Four MAJORs are about **declared blast radius, duplicated policy and envelope consistency**, not about data at rest. Two of them (`L1-C1-01`, `L1-C1-03`) should land before merge because they change or ambiguate behaviour on paths the lane declares "proven zero".

**What to fix before merge:** pin the `is_expired` edit on the two NON-receipt lot writers with a **past** operator-supplied date (`L1-C1-01`) and align the FormRequest expired-lot guard with the service guard on scope + line ordering + `details` shape (`L1-C1-03`, `L1-C1-04`).

---

## Commands I ran (verbatim results)

All PG legs: `cd apps/api && DB_DATABASE=autoerp_test_l DB_CENTRAL_DATABASE=autoerp_test_l_central php artisan test -c phpunit-pgsql.xml <path>`, one file at a time, never the full suite.

### The six new lane files

```
   PASS  Tests\Feature\Inventory\GoodsReceiptReceiveAllBatchDataTest
  Tests:    6 passed (38 assertions)     Duration: 37.77s

   PASS  Tests\Feature\Inventory\GoodsReceiptErrorMessageHygieneTest
  Tests:    7 passed (40 assertions)     Duration: 45.66s

   PASS  Tests\Feature\Inventory\GoodsReceiptVariantBatchScopingTest
  Tests:    4 passed (19 assertions)     Duration: 22.46s

   PASS  Tests\Feature\Inventory\GoodsReceiptExpiredLotPolicyTest
  Tests:    17 passed (113 assertions)   Duration: 59.13s

   PASS  Tests\Feature\Inventory\GoodsReceiptBatchExpiryConflictTest
  Tests:    5 passed (31 assertions)     Duration: 32.34s

   PASS  Tests\Feature\BatchExpiry\DefaultLotExpiryDriftRegressionTest
  Tests:    3 passed (12 assertions)     Duration: 21.86s
```

The counts match the lane summary's PG block exactly. The PG-only two-variant arm runs (4 tests, not 3 + 1 skipped), confirming the `markTestSkipped` guard is driver-conditional as briefed.

### Pre-existing neighbours most likely to regress

```
   PASS  Tests\Feature\Inventory\GoodsReceiptTest                      25 passed (88 assertions)   72.47s
   PASS  Tests\Feature\Inventory\GoodsReceiptLedgerWriteTest           17 passed (105 assertions)  41.16s
   PASS  Tests\Feature\Inventory\GoodsReceiptPriceOverrideTest          6 passed (33 assertions)   29.81s
   PASS  Tests\Feature\Inventory\GoodsReceiptServiceVariantTest         4 passed (23 assertions)   41.58s
   PASS  Tests\Feature\Inventory\GoodsReceiptDestinationTest            3 passed (21 assertions)   41.14s
   PASS  Tests\Feature\Inventory\ReceiptBatchAllocationTest             3 passed (7 assertions)    48.20s
   PASS  Tests\Feature\Inventory\PurchaseBonusGoodsReceiptTest          3 passed (18 assertions)   34.56s
   PASS  Tests\Feature\Procurement\StandaloneReceiptServiceTest        12 passed (72 assertions)   65.44s
   PASS  Tests\Feature\Procurement\StandaloneReceiptIdempotencyRecoveryTest  2 passed (12 assertions)  31.32s
   PASS  Tests\Feature\BatchExpiry\EnsureDefaultBatchTest               7 passed (20 assertions)   35.27s
   PASS  Tests\Feature\BatchExpiry\BatchStockServiceVariantTest         5 passed (12 assertions)   28.54s
   PASS  Tests\Feature\Document\GoodsReceiptConversionUnbalancedGlNarrowingTest  4 passed (12 assertions)  26.75s
```

`GoodsReceiptConversionUnbalancedGlNarrowingTest` green is the load-bearing one: it proves the new `catch (GoodsReceiptException …)` insertions did **not** downgrade the chokepoint-balance 500 arm or the `\DomainException` / `\RuntimeException` arms.

### One RED — and it is PRE-EXISTING, not this lane

```
######## tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php   (l1-receipt worktree)
  ✓ command marks expired batches and notifies each tenant exactly onc… 52.05s
  ✓ command is registered with scheduler and the old job entry is gone   0.38s
  ⨯ for each tenant enters and ends tenant context in db per tenant mod… 0.32s

  Failed asserting that two arrays are identical.
  at tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php:135
  Tests:    1 failed, 2 passed (15 assertions)
```

Re-ran the **same file on the untouched base** (`/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, HEAD `d1a05646556cdf966613dc15a967d87dbf54b4d6`):

```
  Failed asserting that two arrays are identical.
  at tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php:135
  Tests:    1 failed, 2 passed (15 assertions)
```

Identical failure at the identical assertion on the base commit ⇒ **pre-existing dev red, not an L-1 regression.** It is a tenancy-iteration assertion (`array_column($seen,'argument')` empty), unrelated to `is_expired`. Worth its own ticket; do not attribute it here.

### Static gates on the touched files

Touched list derived from `git diff --name-only` + `git ls-files --others --exclude-standard` (20 files: 11 modified, 3 new production classes, 6 new test files).

```
$ ./vendor/bin/phpstan analyse --memory-limit=1G <20 touched files>
Note: Using configuration file …/l1-receipt/apps/api/phpstan.neon.
 20/20 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors

$ ./vendor/bin/pint --test <20 touched files>
{"result":"pass"}
```

### Extra gate I ran that the brief did not ask for

```
$ ./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress
  l1-receipt:  Violations 183   Uncovered 13707   Allowed 14618
  dev base:    Violations 183   Uncovered 13690   Allowed 14547
```

No new module-boundary violation, despite `Document\Presentation\Requests\ReceiveGoodsRequest` newly importing `Product\Domain\Product` and `Inventory\Domain\Enums\GoodsReceiptFailureReason` (Presentation is not gated by the current ruleset — see `L1-C1-02`).

---

## Brief conformance — item by item

| Constraint (brief) | Verdict | Evidence I read |
|---|---|---|
| Refusal at the goods-receipt call site only; `findOrCreateBatch` matching/reuse/signature untouched | ✅ | `BatchStockService.php:340-403` — guard block `:361-368`, existing-lot early return `:376-380`, signature `:352-360` all byte-identical to base; only `:395-396` changed |
| Its only policy edit = `is_expired` from `expiry_date < now()->startOfDay()` | ✅ | `BatchStockService.php:395-396`: `'is_expired' => $expiryDate !== null && CarbonImmutable::parse($expiryDate)->startOfDay()->lt(now()->startOfDay())` |
| That edit does not change DEFAULT-lot callers' behaviour | ⚠ **partially** — see `L1-C1-01`. Reuse/matching unchanged; the **stored flag** on a newly minted DEFAULT lot with a PAST operator date does change | `StockAdjustmentService.php:1102-1119` (machine-derived, never past ⇒ inert); `OpeningBalancePostingService.php:198-207` passes `expiryDate: $line->expiryDate` (**operator-supplied, may be past**); `StockAdjustmentDocumentService.php:630-636` consumes the flag |
| `allow_expired` persisted in `goods_receipts.payload` and re-authorised at post | ✅ | `GoodsReceiptService.php:147-152` (payload), `:438-446` (`draftInput` + `is_bool` narrowing, `@return` shape `:411-419`), `:235` → `:387-409` (post-time re-auth) |
| Standalone surface has the same policy + envelope | ⚠ same policy, **different `details` shape** — see `L1-C1-04` | `CreateStandaloneReceiptRequest.php:27-30,:48-52,:60-76`; `StandaloneReceiptInput.php:23`; `StandaloneReceiptService.php:131`; `StandaloneReceiptController.php:49,:54-63` |
| `GoodsReceiptException` caught before `\DomainException` in ALL THREE controllers | ✅ | `PurchaseOrderController.php:867-871` (before `\DomainException` at `:872`); `GoodsReceiptController.php:126-135` (before `:136`); `StandaloneReceiptController.php:54-63` (before `:64`) |
| No quantity printed in any refusal sentence | ✅ | Asserted mechanically, not eyeballed: `GoodsReceiptErrorMessageHygieneTest.php:231` `preg_match('/\d+\.\d/', $message) === 0` after stripping the line number, on all 7 arms |
| No UUID in any refusal sentence | ✅ | `GoodsReceiptErrorMessageHygieneTest.php:225` `preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', …) === 0`, 7 arms + `GoodsReceiptReceiveAllBatchDataTest.php:113` |
| K-1 hunk `PurchaseOrderController.php:91-130` untouched | ✅ | Diff hunks in that file are only `@@ -26` (one `use`), `@@ -795`, `@@ -821`, `@@ -837`, `@@ -854`. Nothing in 91-130 |
| No edits under `apps/web/e2e-local/**` or `apps/web/e2e/**` | ✅ | `git status --porcelain` outside `apps/api/` ⇒ empty |
| Rule-19 regex ceilings on new request fields | ✅ n/a | The only new field is `allow_expired` (boolean). Existing money/qty regexes untouched: `ReceiveGoodsRequest.php:46-48,:59` |
| No `app()` helper, no `mixed`, constructor injection | ✅ | `grep 'app('` on the four changed service/request files ⇒ none; `grep ': mixed'` ⇒ none. `ReceiveGoodsRequest.php:22` uses `private readonly CompanyContext` — FormRequest ctor injection has precedent (`Expense/Presentation/Requests/ExpenseRequest.php:23`, `Workshop/Technician/Presentation/Requests/StoreTimeEntryRequest.php:22`) |
| Enums for the new reason codes | ✅ | `GoodsReceiptFailureReason.php:9-21` — 8 backed cases, every one has a throw site (`OVER_RECEIPT`/`OVER_RECEIPT_FREE` `:332`/`:353`; `BATCH_DATA_REQUIRED` `:591`/`:924`; `VARIANT_REQUIRED` `:646`; `BATCH_EXPIRY_CONFLICT` `:1073`; `EXPIRED_LOT_REFUSED` `:404`/`:964`; `RECEIVED_PRICE_INVALID` `:1036`; `NOTHING_TO_RECEIVE` `:205`/`:809`) |
| `GoodsReceiptException extends \DomainException` (never reaches the 500 arm) | ✅ | `GoodsReceiptException.php:10`; pinned live by `GoodsReceiptConversionUnbalancedGlNarrowingTest` 4/4 |
| Second-of-everything arms real (second company + re-run) | ✅ mostly — one gap, `L1-C1-08` | Second company via the **real** endpoint `POST /api/v1/companies` (`GoodsReceiptExpiredLotPolicyTest.php:253-259`), two company-scoped `product_batches` rows asserted `:286-289`, cross-company `inventory_batch_stock` isolation `:290-293`; re-run/no-op across the seven tracked tables `:640+` (`snapshot()` `:703-711`) |

**Extra credit the brief did not require and the lane shipped anyway:** two non-disclosure arms proving the pre-controller expired-lot label cannot be used to read a foreign company's SKU (`GoodsReceiptExpiredLotPolicyTest`, arms *"expired validation envelope does not leak a foreign company line"* / *"…a non purchase order line"*). Both were red-first per the summary. That is exactly the right instinct for a guard that queries a route-supplied document id.

---

## Findings

### `L1-C1-01` **[MAJOR]** — `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:395-396`

**What's wrong.** The `is_expired` computation is inside `findOrCreateBatch`, which is *not* receipt-only. Two non-receipt production writers reach it with an **operator-supplied** date that the product owner has explicitly ruled may be in the past:

- `OpeningBalancePostingService.php:198-207` → `ensureDefaultBatchForUntrackedRemainder` → `ensureDefaultBatch` (`BatchStockService.php:81-107`) → `findOrCreateBatch`, passing `expiryDate: $line->expiryDate` verbatim (`:207`).
- `StockAdjustmentService.php:1105-1120` (machine-derived `asOfDate + shelf_life`; inert today, but `asOfDate` is a parameter elsewhere in the family).

`is_expired` is **not inert**: `StockAdjustmentDocumentService::lotRefusesInboundStock` (`:630-636`) returns true for `$batch->is_expired` on any positive, non-contra line, and `:569-571` turns that into `BatchNotApplicableException`. So an opening-stock row with a past expiry — a shape `ProductOpeningStockPhase.php:161-166` documents as **RULED legal** ("a parapharmacy may legitimately open with expired stock in order to scrap it") — now mints a DEFAULT lot that refuses every subsequent positive stock adjustment **from the moment of import**, instead of from the next nightly `batch-expiry:daily-check`. That is the same class of consequence the brief made the lane declare and pin for the receipt-override path (L1-G2-08, pinned at `GoodsReceiptExpiredLotPolicyTest.php:312-345`) — but on two other modules' write paths, undeclared.

**Why it matters.** The lane summary asserts *"Blast radius proven zero"* and the self-review checklist item is ticked. It is not proven: **every** blast-radius arm in `DefaultLotExpiryDriftRegressionTest.php` uses a FUTURE date — `:159` `2027-05-31`, `:160` `2028-01-31`, `:176` `2027-05-31`, `:183` `2028-01-31`, and `:124`/`:139` set "today" to 2026-09-01. The exact input that flips the new predicate is never exercised on a non-receipt path. This is the parapharmacy launch tenant's day-one import path.

Note the behaviour is arguably **more correct** — `ProductOpeningStockPhase.php:163` already tells the operator "The lot is born EXPIRED" — which is why this is MAJOR (undeclared + unpinned), not BLOCKER.

**Fix.** Add two arms to `tests/Feature/BatchExpiry/DefaultLotExpiryDriftRegressionTest.php`:
1. `[pin]` an opening balance with a **past** supplied expiry mints the DEFAULT lot with `is_expired = true` (and still posts, still returns its `OpeningLotExpiryOutcome`);
2. `[pin]` a positive `StockAdjustmentDocumentService` line naming that lot is refused with `BatchNotApplicableException`, zero `stock_movements` delta.

Then replace the summary's "Blast radius proven zero" with the same declared-consequence wording used for L1-G2-08: *"an opening lot opened with a past expiry is un-toppable-up from import rather than from the next nightly run — convergent, matches `ProductOpeningStockPhase.php:161-166`'s documented intent."*

---

### `L1-C1-02` **[MAJOR]** — expired-lot policy now lives in **five** places (convention 11)

`ReceiveGoodsRequest.php:50-54` (the `after_or_equal` rule) · `ReceiveGoodsRequest.php:81-96` (`firstPastExpiry` + a hand-rolled sentence at `:71` and a hand-rolled `details` at `:74-81`) · `CreateStandaloneReceiptRequest.php:48-52` (rule) · `:60-76` (hijack, second hand-rolled sentence) · `GoodsReceiptService.php:947-977` (`assertExpiredLotsAllowed`) · plus the storage predicate at `BatchStockService.php:395-396`.

**What's wrong.** The two FormRequest hijacks re-implement, by hand, what `describeLine()` (`GoodsReceiptService.php:1091`), `lineDetails()` (`:1099`), `labelFromDetails()` (`:1113`) and `GoodsReceiptFailureDetails::toArray()` already produce — including the `line %d (%s)` label format (`ReceiveGoodsRequest.php:69`) and the sku/description de-duplication rule (`:68`). The sentences happen to be byte-identical **today**; nothing enforces that. A future wording change made in `GoodsReceiptService` will silently diverge the HTTP surface from the service surface, and the browser spec asserts the HTTP one.

**Why it matters.** `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`: one concept, one primary producer. The service guard at `:947-977` is already a complete, tested producer of this exact 422 (`GoodsReceiptExpiredLotPolicyTest` arm *"create draft service refuses unpermitted expired override"*), so the `after_or_equal` rule + hijack are **redundant on the PO surface** — validation simply fires first and pre-empts it. It also directly causes `L1-C1-03` and `L1-C1-04`.

**Fix (pick one, and say which in the summary).**
(a) Drop `after_or_equal` and `failedValidation` from **both** request classes and let `createDraft`'s guard be the single producer — the refusal stays a 422 no-op either way, `GoodsReceiptException` already reaches all three controllers, and the tenant/company/`ofType` scoping code at `ReceiveGoodsRequest.php:57-66` disappears with it; or
(b) keep the validation short-circuit but have both requests call one shared builder (e.g. a small `GoodsReceiptRefusal` factory in `Inventory\Domain`) that returns the sentence + `GoodsReceiptFailureDetails`, so there is exactly one place that knows the wording.
If neither, file `L-1-FU-expired-policy-single-surface` and add a test that asserts the request-produced and service-produced envelopes are byte-identical for the same input.

---

### `L1-C1-03` **[MAJOR]** — the two `EXPIRED_LOT_REFUSED` producers disagree on **scope** and on **which line they name**

`ReceiveGoodsRequest::firstPastExpiry` (`:82-96`) iterates `$this->input('batches')` in **payload order** and returns the first past expiry **regardless of whether that line carries a receive quantity**.
`GoodsReceiptService::assertExpiredLotsAllowed` (`:948-976`) iterates `$po->lines` in **document order** and skips any line failing `hasPositiveReceiptQuantity` (`:949` → `:1008-1015`).

Consequences on the API contract:
- a `batches` entry on a line with `quantities[line] = 0` is refused by validation and accepted by the service;
- with two offending lines whose payload order differs from document order, the two layers name **different lines** in `error.message` and in `details.lines[0].line_number`.

**Why it matters.** The orchestrator's `wave2-po.part1.spec.ts` asserts the message string and `error.details.lines[0]`. A harness that sends `batches` in a different key order than the PO's line order gets a different, equally "valid" answer. The FE is currently inert — `ReceiveGoodsDialog.tsx:231` only writes `batches[line.id]` when `hasPaidQuantity || hasFreeQuantity` — but the API is reachable directly and the spec is API-shaped.

**Fix.** In `ReceiveGoodsRequest::firstPastExpiry`, iterate the resolved document's `lines` (the method already loads the document at `:57-63` for the label) and skip lines whose `quantities`/`free_quantities` entry is not `> 0` — i.e. mirror `hasPositiveReceiptQuantity`. Add an arm: two batch-tracked lines, past expiry on the second in document order but first in payload order ⇒ the named line is the **document-order** one, and the same call refused by the service names the same line.

---

### `L1-C1-04` **[MAJOR]** — `apps/api/app/Modules/Procurement/Presentation/Requests/CreateStandaloneReceiptRequest.php:60-76` — the standalone refusal returns a **different `details` shape** and a **payload index** instead of a line number

```php
'message' => sprintf('Cannot receive expired lot for line %d: expiry date %s is before today.', $index + 1, $expiry),
'details' => ['supplied_expiry' => $expiry],
```

`$index` comes from `array_values($lines)` (`:66`) — the request payload's array position, not a document `line_number`; and `details` carries **no `lines[]` key**, while every other producer of this reason does (`GoodsReceiptService.php:971-974` → `GoodsReceiptFailureDetails::toArray():30-32`, and `ReceiveGoodsRequest.php:74-81`). The *same logical failure* on the *same surface* therefore returns two different `details` shapes depending on whether validation or `createDraft` catches it (the service arm is reachable when `allow_expired` is set but the actor is unpermitted, `GoodsReceiptService.php:941-945`).

**Why it matters.** Brief deliverable 2 / L1-G2-13 required the standalone controller to emit `reason`/`details`; a consumer that reads `error.details.lines[0].sku` works on one path and gets `null` on the other. It also drops the item designation entirely — the SKU is resolvable from `lines.*.product_id`, so `B51` ("an error an operator sees names the item") is only half-honoured on this surface.

**Fix.** Emit `details.lines = [[line_number, sku, description]]` from the request too (resolve the `Product` by `lines.*.product_id`, tenant+company-scoped exactly as `ReceiveGoodsRequest.php:60-64` does), and use that `line_number`. Or delete the hijack per `L1-C1-02(a)` and let `createDraft` produce it — which yields the correct shape for free.

---

### `L1-C1-05` **[MINOR]** — `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:129` vs `:169` — refusal **ordering** changed without a pin

`assertBatchDataForTrackedLines` now runs at the top of `createDraft` (`:129`), *before* the per-line `assertQuantitiesWithinRemaining` (`:169`). On a vertical where every physical product is batch-tracked (parapharmacy default), an over-receipt submitted **without** lot data now returns `BATCH_DATA_REQUIRED` where it previously returned the over-receipt refusal. The brief reasoned explicitly about ordering for this guard (`brief:183`, "over-receipt currently fires before 'not a physical product'") but never sanctioned this reorder, and no test pins it.

Narrow in practice: `ReceiveGoodsDialog.tsx:231` always sends `batches` for a tracked line with a positive quantity, so the FE never produces the affected shape, and both outcomes are actionable 422 no-ops.

**Fix.** Add a `[pin]` to `GoodsReceiptErrorMessageHygieneTest`: batch-tracked product, over-quantity, **batches supplied** ⇒ `reason = OVER_RECEIPT` (proves the pre-pass does not shadow the quantity guard), and record the reorder in the summary.

---

### `L1-C1-06` **[MINOR]** — `apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:57-63` — unguarded `find()` on a route-supplied id; the route carries no `whereUuid`

`->find((string) $this->route('purchaseOrder'))` with a non-UUID path segment produces PG `22P02 invalid input syntax for type uuid` ⇒ `QueryException` ⇒ **500**. `routes.php:302-305` has no `->whereUuid('purchaseOrder')` (contrast `:284`, and `whereUuid` is used on 10+ other routes in the same file).

This is **not a new 5xx class** — `PurchaseOrderController.php:772-775` has the identical unguarded `find($purchaseOrder)` on the same route today, and it runs on every request while the new one only runs when a past expiry is present. But the wave-2 browser leg runs under a zero-5xx guard and this lane just added a second instance.

**Fix.** Add `->whereUuid('purchaseOrder')` to `routes.php:302` (one line, closes both instances), or guard the new query with `Str::isUuid()` and fall through to `parent::failedValidation`.

---

### `L1-C1-07` **[MINOR]** — `apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php:312-317` — two stacked docblocks

The original `/** Return a standardized validation error response. */` was left in place and the `@param` block added **below** it, so the method now carries two consecutive docblocks. PHP and Pint accept it; PHPStan reads the last one. Cosmetically the human description is detached from the signature and some doc tooling will drop it.

**Fix.** Merge into one block: description line, blank line, `@param … $extra`.

---

### `L1-C1-08` **[MINOR]** — second-of-everything coverage is one arm short of the brief

Brief §Second-of-everything item 1 asks for the second-company arm on tasks **1, 3, 4 and 5**; item 2 asks for a `[pin]` that the same lot number received into MAIN and a second location yields **one** `product_batches` row and **two** `inventory_batch_stock` rows.

- Second company: present once, in `GoodsReceiptExpiredLotPolicyTest.php:248-294`. It happens to cover task 1 (batches-only receive-all, `:280`) and task 3 (variant-scoped lots, `:289`), but **task 5 (expiry conflict) has no second-company arm** — `GoodsReceiptBatchExpiryConflictTest` has only the second-location arm (`:190-199`).
- The "one lot / two batch-stock rows" `[pin]` is absent: the second-location arms assert refusals and `BatchStock…count() === 0` (`GoodsReceiptBatchExpiryConflictTest.php:198`, `GoodsReceiptExpiredLotPolicyTest.php:372`), never the two-row success shape.

Both are pins over pre-existing behaviour (`BatchStockService.php:138-141`), so this is coverage debt, not a defect.

---

### `L1-C1-09` **[MINOR]** — `is_expired` is computed at mint only; an existing lot is never refreshed

`findOrCreateBatch` returns an existing lot untouched (`BatchStockService.php:376-380`), so a lot created today with tomorrow's date and re-received the day after tomorrow keeps `is_expired = false` until the nightly command. The new predicate therefore guarantees truthfulness *at creation*, not *at rest*. The summary records this as a deliberate decline of a prior reviewer's suggestion, citing the brief's set-once fence — correct call for this lane. Recording it so a later reader does not read `is_expired` as an invariant. Ticket: `L-1-FU-is-expired-refresh-on-reuse`.

---

### `L1-C1-10` **[MINOR]** — `error.details` carries canonical scale-4 quantity strings; the FE must not render them raw

`GoodsReceiptService.php:1137-1140` (`bcadd($value, '0', self::QUANTITY_SCALE)`) emits `ordered/already_received/requested/remaining` at scale 4 — deliberate per L1-G2-02 and correct here (the sentence is figure-free; canonical values live in `details`). But rule 19's display clause means whoever surfaces them must route through `formatQuantity` at the product unit's `decimal_places`; a raw paste into a toast shows `10.0001` for a `pc` (0-dp) product. **Not a defect in this lane** — a hand-off note for lane L-2 and for the spec's `details.*` assertions (the spec should read `details` as strings, which the brief already says at `brief:346`).

---

### `L1-C1-11` **[MINOR / not this lane]** — `tests/Feature/BatchExpiry/BatchExpiryDailyCheckCommandTest.php:135` is red on the base commit

Reproduced identically on `dev` `d1a056465` (see Commands). Needs its own ticket so it does not get attributed to L-1 at promotion time.

---

## Callers traced

Every caller of each changed or new signature, confirmed by grep across `app/`, `database/`, `tests/` and read at the call site.

| Method | Change | Callers | Still compiles / behaves? |
|---|---|---|---|
| `GoodsReceiptService::receiveGoods` | +9th param `bool $allowExpired = false` | `PurchaseOrderController.php:834` (passes it), `PurchaseOrderToGoodsReceiptConverter.php:140` (7 positional args), `DemoPharmacySeeder.php:1235`, 30+ tests (≤8 positional args) | ✅ trailing default; no caller passes 9 args except the controller. Converter green via `GoodsReceiptConversionUnbalancedGlNarrowingTest` |
| `GoodsReceiptService::receiveAll` | +4th `array $batchData = []`, +5th `bool $allowExpired = false` | `PurchaseOrderController.php:847` (passes both), `PurchaseOrderToGoodsReceiptConverter.php:143` (2 args), `DemoPharmacySeeder.php:1244` (1 arg), `GoodsReceiptTest.php:255,669,692,868`, `GoodsReceiptLedgerWriteTest.php:337` | ✅ appended with defaults; all green |
| `GoodsReceiptService::createDraft` | +11th `bool $allowExpired = false`; two new guards at `:129-130` **before** the transaction | `PurchaseOrderController.php:815`, `StandaloneReceiptService.php:121` (named `allowExpired:`), `GoodsReceiptLedgerWriteTest.php:178,223,427,463,464,482,534,562`, `GoodsReceiptDestinationTest.php:197`, `GoodsReceiptGlPostingOrderTest.php:984`, `GoodsReceiptErrorMessageHygieneTest.php:201`, `GoodsReceiptExpiredLotPolicyTest.php:159` | ✅ signature safe. **Behaviour change**: batch-data + expired guards now fire for `StandaloneReceiptService` and for the converter too; standalone compensation path (`StandaloneReceiptService.php:147-151` → `compensateFailedReceiptCreation`) still runs, proven by `StandaloneReceiptServiceTest` 12/12 + `StandaloneReceiptIdempotencyRecoveryTest` 2/2 |
| `GoodsReceiptService::processReceiptLines` (private) | + pre-pass call at `:511` | `post()` `:287` only | ✅ |
| `GoodsReceiptService::assertBatchDataForTrackedLines` (new, private) | — | `createDraft:129`, `processReceiptLines:511` | ✅ two extra scoped `whereIn` product queries on the straight-through path, one on draft-only/post-only — matches the D1 declared cost |
| `GoodsReceiptService::assertExpiredLotsAllowed` (new, private) | — | `createDraft:130` only | ⚠ see `L1-C1-03` (scope differs from the FormRequest twin) |
| `GoodsReceiptService::assertCanReceiveExpiredLots` / `assertActorCanReceiveExpiredLots` (new, private) | — | `post:235`; `assertExpiredLotsAllowed:942` | ✅ mirrors `assertCanApplyDraftPriceOverrides:370-385` exactly (`User::find` + `->can()`), same team-context assumption |
| `BatchStockService::findOrCreateBatch` | body: `is_expired` only (`:395-396`) | `GoodsReceiptService.php:635`, `StockAdjustmentService.php:1105`, `BatchStockService::ensureDefaultBatch:100` (→ `ensureDefaultBatchForUntrackedRemainder` → opening balances, reservations, flip-to-tracked, adjustment top-up, seeder) | ⚠ signature/matching/reuse untouched ✅; **stored flag changes** on operator-past dates — `L1-C1-01` |
| `BatchStockService::findByBatchNumber` (new, public) | — | `GoodsReceiptService.php:1055` only | ✅ pure delegation to `BatchRepositoryInterface::findByBatchNumberAndVariant`, no policy, no write |
| `HandlesDocuments::validationErrorResponse` | +3rd `?array $extra = null` | 39 pre-existing call sites unchanged (2-arg); new 3-arg call at `PurchaseOrderController.php:868` | ✅ trailing nullable default; `array_merge` keeps `code`/`message` first |
| `StandaloneReceiptInput::__construct` | +9th `bool $allowExpired = false` | `StandaloneReceiptController.php:49`, `SupplierInvoice*` invoice-first callers (fewer args) | ✅ trailing default |
| `PurchaseOrderToGoodsReceiptConverter.php:140,143` (declared follow-up) | not edited | — | ✅ untouched; still passes `[]` for `$batchData`, so a batch-tracked PO conversion now refuses at `createDraft` instead of at post — same net 422, ticket `L-1-FU-converter-batches` correctly filed |

---

## AFTER strings for the spec — exactly what the API now returns

Derived from the code, not from the summary. All are HTTP **422**. `error.code` is unchanged on all three surfaces.

| Spec row | `error.code` | `error.reason` | `error.message` (exact) | `error.details` | Produced at |
|---|---|---|---|---|---|
| **W2-LOT-5** (`{}` on a tracked PO) | `GOODS_RECEIPT_FAILED` | `BATCH_DATA_REQUIRED` | `Batch data is required for batch-tracked products: line 1 (P-LOT-1).` | `lines: [{line_number: 1, sku: "P-LOT-1", description: "P-LOT-1"}]` | `GoodsReceiptService.php:1027-1031` via `:129` |
| **W2-LOT-5** (`batches`-only, no `quantities`) | — | — | — | — | **200**, 1 `goods_receipts`, 1 `product_batches`, `inventory_batch_stock` = `stock_levels` (`GoodsReceiptReceiveAllBatchDataTest.php:119-135`) |
| **W2-LOT-6** (past expiry, no flag) | `GOODS_RECEIPT_FAILED` | `EXPIRED_LOT_REFUSED` | `Cannot receive expired lot for line 1 (<SKU>): expiry date 2020-01-01 is before today.` | `lines: [{line_number, sku, description}]`, `supplied_expiry: "2020-01-01"` | `ReceiveGoodsRequest.php:66-83` (validation fires first) |
| **W2-LOT-6** (`allow_expired: true` + permission) | — | — | — | — | **200**, lot `expiry_date=2020-01-01`, **`is_expired = true`**, `stock=6.0000` |
| **W2-LOT-6** (`allow_expired: true` **without** permission) | validation envelope | — | Laravel `prohibited` message on `allow_expired` | standard `errors` bag | `ReceiveGoodsRequest.php:53` |
| **W2-LOT-7** (2nd tranche, different expiry) | `GOODS_RECEIPT_FAILED` | `BATCH_EXPIRY_CONFLICT` | `Batch LOT-SAME for line 1 (<SKU>) already has expiry date 2027-01-31; supplied expiry date <supplied> conflicts.` | `lines: […]`, `batch_number: "LOT-SAME"`, `stored_expiry: "2027-01-31"`, `supplied_expiry: "<supplied, raw>"` | `GoodsReceiptService.php:1073-1088` |
| **W2-LOT-8** (line **with** `variant_id`) | — | — | — | — | **200**, `product_batches.variant_id` = that variant |
| **W2-LOT-8** (line **without** `variant_id`, batches supplied) | `GOODS_RECEIPT_FAILED` | `VARIANT_REQUIRED` | `Product P-LOT-8 on line 1 has active variants; batches must be variant-scoped — a variant_id is required.` | `lines: [{line_number: 1, sku: "P-LOT-8", description: …}]` | `GoodsReceiptService.php:646-654` |
| **W2-OVER-1** | `GOODS_RECEIPT_FAILED` | `OVER_RECEIPT` | `Cannot receive more than ordered for line 1 (P-OVER-1): requested more than the remaining quantity.` | `ordered: "10.0000"`, `already_received: "0.0000"`, `requested: "10.0001"`, `remaining: "10.0000"`, `lines: [{line_number: 1, sku: null, description: "P-OVER-1"}]` | `GoodsReceiptService.php:332-345` |
| **W2-OVER-1** (free variant) | `GOODS_RECEIPT_FAILED` | `OVER_RECEIPT_FREE` | `Cannot receive more free quantity than ordered for line 1 (<desc>): requested more than the remaining free quantity.` | same four keys | `GoodsReceiptService.php:353-366` |
| post-time refusals (`POST /goods-receipts/{id}/post`) | `GOODS_RECEIPT_POST_FAILED` | any of the above | — | as above | `GoodsReceiptController.php:126-135` |
| standalone refusals | `STANDALONE_RECEIPT_FAILED` | as above | — | ⚠ `supplied_expiry` **only** on the validation path (`L1-C1-04`) | `CreateStandaloneReceiptRequest.php:70-74` / `StandaloneReceiptController.php:54-63` |

**Four traps the orchestrator must respect when updating `wave2-po.part1.spec.ts`:**

1. **`W2-LOT-8` needs `batches` in the payload.** Without them the pre-pass fires `BATCH_DATA_REQUIRED` *before* the variant guard is ever reached (`GoodsReceiptService.php:129` runs before `:624`). Send `batches[lineId]` for the null-variant arm or the row measures the wrong reason.
2. **`W2-LOT-7`'s second expiry must be in the FUTURE.** If the harness supplies a past date for the second tranche, `after_or_equal` (`ReceiveGoodsRequest.php:53`) rejects it at validation as `EXPIRED_LOT_REFUSED` and `BATCH_EXPIRY_CONFLICT` is never produced. `2028-01-31` against a stored `2027-01-31` is the right pair.
3. **The over-receipt label has no SKU.** `assertQuantitiesWithinRemaining` calls `describeLine($line)` with **no** `Product` (`GoodsReceiptService.php:336`), so `details.lines[0].sku` is `null` and the label renders the *description*. It reads `P-OVER-1` only because the harness defaults `description` to the SKU (`wave2-po.part1.spec.ts:373-375`). If a description is ever set independently, the label changes and the spec must follow.
4. **`details.lines[].description` is NOT omitted when it equals the SKU** — only the human *label* de-duplicates (`labelFromDetails:1119`). Assert `details.lines.0.description === 'P-LOT-1'`, not `null`.

---

## Seeder rollout — verified

- **Permission registered:** `RolesAndPermissionsSeeder.php:149` adds `'goods-receipt.receive-expired'` to `permissionNames()`, immediately after `goods-receipt.edit-price` — exactly the precedent the brief named.
- **Granted:** `RolesAndPermissionsSeeder.php:572` adds it to the same role list as `goods-receipt.edit-price` (the manager grant). Proven live, not assumed: `GoodsReceiptExpiredLotPolicyTest` arm *"manager role receives the expired lot override permission"* (`:243-245`, a fresh user assigned `manager` then `->can(...)`).
- **Route reachability:** no new route. The permission is consumed in `ReceiveGoodsRequest.php:41` / `CreateStandaloneReceiptRequest.php:29` (`prohibited` gate) and re-checked at `GoodsReceiptService.php:403`. 422-without / 200-with are both covered (`allow expired is prohibited without permission`, `permitted override creates truthfully expired lot…`).
- **Rollout to existing tenants:** there is **no migration and no command in the diff** — correctly so, per the brief's L1-G1-08 disposition. The rollout is the documented operational step, present in the summary: `php artisan tenants:run db:seed --class=RolesAndPermissionsSeeder`.

**Does the browser re-verification precondition hold?** Only if the orchestrator actually runs that re-seed against the session-L tenant **before** re-running `wave2-po.part1.spec.ts`. Without it:
- `W2-LOT-6`'s override arm 422s at validation (`prohibited`, because the seeded role has no such permission in an already-provisioned tenant DB) — reads as a code defect;
- `W2-LOT-10` can never rebuild its expired-lot tuple, because after this lane the *only* way to create an expired lot through the receipt path is the override.

The same re-seed is a **staging promotion precondition** and must be written into `PROMOTION-CHECKLIST-*`. The lane summary states both; treat it as an owner-owed ops item, not a lane deliverable.

---

## Merge conditions

1. `L1-C1-01` — two `[pin]` arms in `DefaultLotExpiryDriftRegressionTest` for a **past** operator expiry on the opening-balance and explicit-lot-adjustment paths, plus the declared-consequence sentence in the summary (replace "Blast radius proven zero").
2. `L1-C1-03` — align `ReceiveGoodsRequest::firstPastExpiry` with `hasPositiveReceiptQuantity` and with document order, + one arm proving the two producers name the same line.
3. `L1-C1-04` — emit `details.lines[]` (with a real `line_number` and the SKU) from the standalone validation refusal, or delete the hijack in favour of the service guard.
4. `L1-C1-02` — either de-duplicate the policy or file `L-1-FU-expired-policy-single-surface` with an envelope-equality test. Ticket is acceptable; silence is not.

`L1-C1-05` … `L1-C1-11` are follow-ups and do not block.

**What to fix before merge:** pin the `is_expired` edit on the two non-receipt lot writers with a past operator-supplied date, and make the FormRequest expired-lot guard agree with the service guard on scope, line ordering and `details` shape.
