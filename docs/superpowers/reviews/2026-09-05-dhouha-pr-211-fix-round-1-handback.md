# Fix round 1 handback — PR #211 (F-W2-13, supplier payments in the customer refund lane)

| Field | Value |
|---|---|
| Gate report answered | `docs/superpowers/reviews/2026-09-05-dhouha-pr-211-gate-r1.md` (verdict spec ❌ + quality CHANGES-REQUESTED) |
| Worktree / branch | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211` · `gate/pr-211` |
| Base reviewed | `1ea649990` (merge of `refs/pr/211` into local dev `fa000edc3`) |
| Fixer | Claude Opus 5, 2026-09-05 |
| Scope | gate findings **1 (BLOCKER)**, **2**, **3**, **4** (MAJOR), **9** (MINOR), plus **5** where item 1 forced the edit. MINOR 6/7/8 and INFO 10/11/12 deliberately untouched — listed as follow-ups below. |
| Merged? | **No.** Nothing merged, nothing pushed. |

---

## Per finding

### 1. [BLOCKER] Guard refused every `Unsupported` shape → broke the §13 writer inventory

**Verified before acting.** The claim is exact:
- `PaymentType::reversalSupport()` maps `SupplierPayment`, `POS`, `POSRefund`, `Refund`, `Reversal` to `ReversalSupport::Unsupported` (`apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:230-238`), so the PR's `assertRefundableType()` refused POS too.
- `tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php` seeds a `PaymentType::POS` payment (`seedPosOriginPayment()`, `:736-752`) and refunds it through this lane at `:435` and `:471`. Reproduced red on the merged tree before the fix (output below).

**Changed.**
- `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:510-515` — `assertRefundableType()` now refuses **only** the supplier shape:
  ```php
  private function assertRefundableType(Payment $payment): void
  {
      if ($payment->isSupplierPayment()) {
          throw new RefundLaneRefusedException((string) $payment->id, PaymentType::SupplierPayment);
      }
  }
  ```
- `PaymentRefundService.php:1129-1140` — `canRefund()` uses the same predicate (see finding 5); the hardcoded 3-case `in_array` list is gone.
- Both call sites are unchanged and still run on the DB-locked `$original` before any write (`:160` in `refundPayment()`, `:350` in `partialRefund()`).

**SoT confirmed, not assumed.** `Payment::isSupplierPayment()` (`apps/api/app/Modules/Treasury/Domain/Payment.php:370-373`) is `payment_type === PaymentType::SupplierPayment`, and that enum case is the **only** shape a supplier-invoice payment is ever written with:
- `PaymentController::store()` sets `$isSupplierPayment = true` from `DocumentType::SupplierInvoice` (`PaymentController.php:526-529`) and types the row at `:936`;
- every other payment writer refuses supplier invoices outright — `MultiPaymentController.php:173` and `:389` (`rejectSupplierInvoice()`), and `storeMultiple()` via `rejectSupplierInvoiceInMultiline()` (noted at `PaymentController.php:932`).
So "supplier shapes" = `{SupplierPayment}`; there is no second enum case and no supplier-invoice payment typed otherwise. It is also null-safe by construction (an unhydrated `payment_type` is `null`, which is not the enum case) — the reason the PR gave for its case list.

**Proof.** `PaymentOriginWriterInventoryTest` green on sqlite AND PG (below). `PaymentRefundRefusalTest` keeps its falsifiability for the supplier cases: with the guard neutralised, **5 of 8** fail (below); the 3 that stay green are the customer-payment regression and the two new POS-unchanged cases, which is the intended behaviour.

### 4. [MAJOR] POS refund path removed from the web (scope creep)

Falls away with finding 1: `canRefund()` no longer looks at POS, so `GET /payments/{id}/can-refund` answers exactly as on `dev` and the buttons at `PaymentDetailPage.tsx:412`/`:421` behave identically. Pinned in two places:
- `tests/Feature/Treasury/PaymentRefundRefusalTest.php:192-207` — new `posShapes()` provider + `test_pos_shapes_are_still_offered` (the PR's POS refusal cases **inverted**, per the brief);
- the §13 writer inventory's POS refund rows are green again (`:435`, `:471`).

The FE change I added is inert for POS: `refundRefusalReason` is non-null only when `payment.payment_type === 'supplier_payment'` (`PaymentDetailPage.tsx:372-375`), so a POS payment renders no note, no `title`, and enabled buttons — asserted by the new "offers the refund actions with no refusal note" vitest case.

### 2. [MAJOR] The refusal never reached the operator

**Verified.** `apps/web/src/lib/api.ts:83-98` reads `data.error.message` → `data.message` → the axios string; the controller returned `['error' => $e->getMessage()]`, a bare string, so the toast said "Request failed with status code 422".

**Changed.**
- New typed refusal `apps/api/app/Modules/Treasury/Domain/Exceptions/RefundLaneRefusedException.php` (extends `DomainException`, carries `paymentId` + `paymentType`, exposes `translationKey()`), following the house `DocumentNotAllocatableException` pattern (`.../Exceptions/DocumentNotAllocatableException.php:34-51`).
- `PaymentRefundController.php:90-108` (`refundPayment`) and `:147-165` (`partialRefund`) — a typed catch arm **before** the generic `\Exception` arm, returning the same envelope shape `refundPrepayment()` already uses (`:255-261`):
  ```php
  ['error' => ['code' => 'REFUND_LANE_REFUSED', 'message' => __($e->translationKey()),
               'details' => ['payment_id' => …, 'payment_type' => 'supplier_payment']]]
  ```
- i18n keys, following the existing `allocation_refused.*` style in the same files: `apps/api/lang/en/treasury.php:47`, `apps/api/lang/fr/treasury.php:45`, `apps/api/lang/ar/treasury.php:41` (`refund_refused.supplier_payment`).
- Web: `apps/web/src/features/treasury/PaymentDetailPage.tsx:365-375` computes `refundRefusalReason` via `t()` in the `treasury` namespace; `:415` and `:424` put it on the two disabled buttons as `title`; `:428-437` renders it as a visible `role="note"` paragraph (a disabled button gives no hover tooltip in every browser). Classes use design tokens (`textColors.tertiary`) per rule 18; no hardcoded colour added.
- Web i18n: `apps/web/src/locales/{en,fr,ar}/treasury.json:135` (`payments.refund.refusedSupplierPayment`), same three-locale text as the backend key.

**Proof.** Two new HTTP tests assert `error.code`, `error.message` (identical to `__('treasury.refund_refused.supplier_payment')`) and `error.details.payment_type` on both entry points (`PaymentRefundRefusalTest.php:241`, `:272`). Removing the two catch arms makes both fail with `Failed asserting that null is identical to 'REFUND_LANE_REFUSED'` (verbatim below). Two vitest cases cover the FE (`PaymentDetailPage.test.tsx:140-186`).

### 3. [MAJOR] The message pointed at a lane that would refuse the row too

**Verified.** `VendorRefundService::refundPrepayment()` is the only public method and refuses anything that is not a purchase order (`VendorRefundService.php:53-56`), on a document-anchored route (`Treasury/Presentation/routes.php:215`). A `SupplierPayment` row is by construction allocated to a `SupplierInvoice`, so the sets are disjoint — the PR's text sent the operator to a dead end and named an internal PHP class.

**Changed.** No lane name, no class name. The three locales now say: this payment was made against a supplier invoice → it cannot be refunded on this screen (this screen refunds money received from a customer; this money went out to a supplier) → undoing a supplier-invoice payment belongs to the supplier payment flow and **is not available yet** → contact an administrator. EN/FR/AR text is at `lang/{en,fr,ar}/treasury.php` (`refund_refused.supplier_payment`) and mirrored in the web bundles. The exception's technical `getMessage()` (`RefundLaneRefusedException.php:33-41`) states the same fact for logs/tests and also names no class.

Guarded: `PaymentRefundRefusalTest.php:258-265` asserts the operator message does **not** contain `VendorRefundService`.

**The missing undo path is NOT built here** (out of scope by the brief) — filed as follow-up FU-1 below.

### 9. [MINOR] Data-provider / test-signature mismatch

**Verified.** The PR's provider yielded 2-element arrays (`PaymentRefundRefusalTest.php:147-154`) while `test_can_refund_is_false_for_an_unsupported_shape` (`:188`) declared one parameter.

**Changed.** The provider is gone. With the guard narrowed to one shape, the three provider-driven tests became three plain supplier tests (`:150`, `:160`, `:173`), and the only remaining provider (`posShapes()`, `:192-199`) yields 1-element arrays matching its 1-parameter test (`:201`). The refusal helper lost its now-unused `$expectedFragment` argument and asserts the typed exception's `paymentType`/`paymentId` instead (`:302-318`).

### 5. [MINOR] `canRefund()` duplicated the source of truth — fixed because item 1 forced the edit

`canRefund()` (`PaymentRefundService.php:1138`) now calls the same `Payment::isSupplierPayment()` predicate as the write guard instead of a hardcoded case list. It is deliberately **not** `reversalSupport() === Unsupported` (the gate's suggested form), because that expression includes POS/POSRefund and would reintroduce finding 1/4. The comment at `:1123-1136` states plainly that `canRefund()` does not mirror every write-path refusal (the `Refund`/`Reversal` gap is finding 6, left alone).

---

## Verification (verbatim)

All backend runs in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211/apps/api`, all web runs in `apps/web`. PG legs used the private DB `autoerp_test_f211` (127.0.0.1:5433), dropped at the end of the round.

### `PaymentRefundRefusalTest` — sqlite (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundRefusalTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211/apps/api/phpunit.xml

........                                                            8 / 8 (100%)

Time: 00:35.271, Memory: 165.00 MB

OK (8 tests, 33 assertions)
```

### `PaymentRefundRefusalTest` — PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f211 DB_CENTRAL_DATABASE=autoerp_test_f211 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundRefusalTest.php
   PASS  Tests\Feature\Treasury\PaymentRefundRefusalTest
  ✓ full refund of a supplier payment refuses and writes nothing        32.58s
  ✓ partial refund of a supplier payment refuses and writes nothing      3.47s
  ✓ can refund is false for a supplier payment                           2.53s
  ✓ pos shapes are still offered with data set "pos"                     2.46s
  ✓ pos shapes are still offered with data set "pos refund"              5.32s
  ✓ a genuine customer payment still refunds                             3.98s
  ✓ a full refund refusal surfaces as a structured 422 over the http ap… 3.79s
  ✓ a partial refund refusal surfaces as a structured 422 over the http… 3.95s

  Tests:    8 passed (33 assertions)
  Duration: 58.19s
```

### BLOCKER regression fixed — `PaymentOriginWriterInventoryTest`, sqlite (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211/apps/api/phpunit.xml

...............                                                   15 / 15 (100%)

Time: 00:18.096, Memory: 167.00 MB

OK (15 tests, 34 assertions)
```

### BLOCKER regression fixed — `PaymentOriginWriterInventoryTest`, PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f211 DB_CENTRAL_DATABASE=autoerp_test_f211 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
   PASS  Tests\Feature\Fiscal\PaymentOriginWriterInventoryTest
  ✓ payment controller store stamps web admin                           12.57s
  ✓ payment controller store multiple stamps web admin                   1.65s
  ✓ multi payment create split stamps web admin                          1.60s
  ✓ multi payment record deposit stamps web admin                        1.45s
  ✓ multi payment record payment on account stamps web admin             1.59s
  ✓ refund inherits pos origin when original is pos                      1.88s
  ✓ refund inherits web admin origin when original is web admin          1.97s
  ✓ partial refund inherits pos origin when original is pos              1.49s
  ✓ partial refund inherits web admin origin when original is web admin  1.92s
  ✓ refund payment falls back to unknown legacy when original origin is… 1.67s
  ✓ partial refund falls back to unknown legacy when original origin is… 1.46s
  ✓ proration refund falls back to unknown legacy when original origin…  1.39s
  ✓ receipt proration refund inherits pos origin                         1.57s
  ✓ proration refund inherits web admin origin when original is web adm… 1.70s
  ✓ vendor refund prepayment stamps web admin                            2.07s

  Tests:    15 passed (34 assertions)
  Duration: 36.03s
```

### Falsifiability probe A — guard neutralised (`if (false && $payment->isSupplierPayment())` in both `assertRefundableType()` and `canRefund()`), sqlite: 5/8 RED
```
1) …::test_full_refund_of_a_supplier_payment_refuses_and_writes_nothing
the refund lane must refuse this shape
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:303
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:154

2) …::test_partial_refund_of_a_supplier_payment_refuses_and_writes_nothing
the refund lane must refuse this shape
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:303
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:167

3) …::test_can_refund_is_false_for_a_supplier_payment
supplier_payment: the UI must not offer a refund action for a shape this lane refuses
Failed asserting that true is false.
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:177

4) …::test_a_full_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422.

5) …::test_a_partial_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422.

FAILURES!
Tests: 8, Assertions: 11, Failures: 5.
```
(The 3 green ones are `test_a_genuine_customer_payment_still_refunds` and the two `test_pos_shapes_are_still_offered` cases — exactly the tests that must NOT depend on the guard.) File restored from a scratchpad copy immediately after; `git status --porcelain` verified afterwards.

### Falsifiability probe B — both typed catch arms deleted from `PaymentRefundController` (generic `\Exception` arm answers instead), sqlite: 2/2 RED
```
$ ./vendor/bin/phpunit --filter 'structured_422' tests/Feature/Treasury/PaymentRefundRefusalTest.php
FF                                                                  2 / 2 (100%)

There were 2 failures:

1) …::test_a_full_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Failed asserting that null is identical to 'REFUND_LANE_REFUSED'.
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:252

2) …::test_a_partial_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Failed asserting that null is identical to 'REFUND_LANE_REFUSED'.
…/tests/Feature/Treasury/PaymentRefundRefusalTest.php:284

FAILURES!
Tests: 2, Assertions: 4, Failures: 2.
```
File restored from a scratchpad copy immediately after.

### Neighbouring Treasury refund suites — sqlite (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundProrationTest.php \
    tests/Feature/Treasury/RefundSpineTest.php tests/Feature/Treasury/PaymentReversalRefusalTest.php \
    tests/Feature/Treasury/PaymentRefundSecurityTest.php tests/Unit/Treasury/PaymentTypeReversalTest.php
.....................................................             53 / 53 (100%)
Time: 00:53.329, Memory: 175.00 MB
OK (53 tests, 195 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/VendorPrepaymentRefundTest.php \
    tests/Feature/Treasury/SupplierPaymentGuardTest.php tests/Feature/POS/PosAnalyticsRefundNettingTest.php
.........................                                         25 / 25 (100%)
Time: 00:28.828, Memory: 171.00 MB
OK (25 tests, 135 assertions)
```

### Neighbouring Treasury refund suites — PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_f211 DB_CENTRAL_DATABASE=autoerp_test_f211 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundProrationTest.php \
    tests/Feature/Treasury/RefundSpineTest.php tests/Feature/Treasury/PaymentReversalRefusalTest.php \
    tests/Feature/Treasury/PaymentRefundSecurityTest.php
  ✓ cross tenant reverse payment returns 404                             2.16s
  ✓ cross tenant check refundable returns 404                            3.05s
  ✓ cross tenant refund history returns 404                              2.26s
  ✓ same tenant refund payment succeeds                                  2.12s

  Tests:    46 passed (170 assertions)
  Duration: 139.12s
```

### `PaymentRefundTest` — sqlite + PostgreSQL (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundTest.php tests/Feature/Treasury/PaymentTest.php
…
Tests: 53, Assertions: 198, Failures: 1.      ← the single failure is PaymentTest (see next block)

$ DB_HOST=… php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundTest.php
  ✓ a reversal row cannot be refunded                                    1.94s
  ✓ a refund row cannot be refunded                                      4.84s
  ✓ partial refund of a negative payment fails by rule not by accident   7.45s
  ✓ an ordinary positive payment is still refundable                     5.70s
  ✓ multiple partial refunds accumulate                                  1.90s

  Tests:    20 passed (79 assertions)
  Duration: 64.73s
```

### ⚠️ `PaymentTest` — ONE RED, proved PRE-EXISTING on `dev` (not this PR, not this fix)
sqlite and PG both fail the same single test:
```
1) Tests\Feature\Treasury\PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance
Failed asserting that two values of enumeration App\Modules\Treasury\Domain\Enums\PaymentType are equal,
SupplierPayment does not match expected DocumentPayment.
…/tests/Feature/Treasury/PaymentTest.php:578

Tests: 1 failed, 32 passed (119 assertions)   [PG]
```
Baseline probe — both changed production files replaced with their `dev` (`fa000edc3`) contents, same test, same tree:
```
$ git show dev:apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php > …/PaymentRefundService.php
$ git show dev:apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php > …/PaymentRefundController.php
$ ./vendor/bin/phpunit --filter test_supplier_invoice_payment_clears_401_and_reduces_payable_balance tests/Feature/Treasury/PaymentTest.php
1) Tests\Feature\Treasury\PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance
Failed asserting that two values of enumeration App\Modules\Treasury\Domain\Enums\PaymentType are equal,
SupplierPayment does not match expected DocumentPayment.
…/tests/Feature/Treasury/PaymentTest.php:578
FAILURES!
Tests: 1, Assertions: 2, Failures: 1.
```
Same failure without the fix ⇒ stale test assertion on `dev`: `PaymentTest.php:578` still expects `DocumentPayment` for a payment allocated to a supplier invoice, which `PaymentController.php:936` has typed `SupplierPayment` since before this PR. Files restored from scratchpad copies; the neither-PR-nor-fix conclusion is asserted only on this evidence. Raised as follow-up FU-3.

### PHPStan level 8 — touched PHP files (PASS)
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Treasury/Domain/Services/PaymentRefundService.php \
    app/Modules/Treasury/Domain/Exceptions/RefundLaneRefusedException.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php \
    tests/Feature/Treasury/PaymentRefundRefusalTest.php
Note: Using configuration file …/apps/api/phpstan.neon.

 [OK] No errors
```

### Pint — touched PHP + lang files (PASS)
```
$ ./vendor/bin/pint --test app/Modules/Treasury/Domain/Services/PaymentRefundService.php \
    app/Modules/Treasury/Domain/Exceptions/RefundLaneRefusedException.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php \
    tests/Feature/Treasury/PaymentRefundRefusalTest.php \
    lang/en/treasury.php lang/fr/treasury.php lang/ar/treasury.php
{"result":"pass"}
```

### Web — vitest by path (PASS)
```
$ ./node_modules/.bin/vitest run src/features/treasury/PaymentDetailPage.test.tsx
 ✓ src/features/treasury/PaymentDetailPage.test.tsx (5 tests) 214ms

 Test Files  1 passed (1)
      Tests  5 passed (5)
   Duration  1.76s

$ ./node_modules/.bin/vitest run src/features/treasury/__tests__/PaymentDetailPage.tenantScope.test.tsx
 ✓ src/features/treasury/__tests__/PaymentDetailPage.tenantScope.test.tsx (5 tests) 817ms

 Test Files  1 passed (1)
      Tests  5 passed (5)
   Duration  2.76s
```
(The 5 in the first file = 3 pre-existing + the 2 new refusal/no-refusal cases. React `act(...)` advisories are printed by the pre-existing cases too and are not failures.)

### Web — typecheck + scoped ESLint
```
$ ./node_modules/.bin/tsc --noEmit
(no output — clean; 1:53 wall)

$ ./node_modules/.bin/eslint src/features/treasury/PaymentDetailPage.tsx src/features/treasury/PaymentDetailPage.test.tsx
✖ 13 problems (0 errors, 13 warnings)
```
All 13 warnings are on pre-existing lines I did not touch (`precision/no-parsefloat-on-money` at `:317`, `:326`, `:327`, `:493`, `:501`, `:597`, `:783`; `no-unnecessary-condition` at `:187`, `:197`, `:330`; `require-await` in the test's pre-existing mock arrows). **0 errors**, none from the new code. Full `pnpm lint` was not run (brief: laptop swap).

### Diff
```
$ git diff --stat dev HEAD
 .../Exceptions/RefundLaneRefusedException.php      |  68 ++++
 .../Domain/Services/PaymentRefundService.php       |  64 ++++
 .../Controllers/PaymentRefundController.php        |  37 +++
 apps/api/lang/ar/treasury.php                      |   8 +
 apps/api/lang/en/treasury.php                      |  14 +
 apps/api/lang/fr/treasury.php                      |  12 +
 .../Feature/Treasury/PaymentRefundRefusalTest.php  | 364 +++++++++++++++++++++
 .../features/treasury/PaymentDetailPage.test.tsx   |  47 +++
 .../src/features/treasury/PaymentDetailPage.tsx    |  26 ++
 apps/web/src/locales/ar/treasury.json              |   3 +-
 apps/web/src/locales/en/treasury.json              |   3 +-
 apps/web/src/locales/fr/treasury.json              |   3 +-
 12 files changed, 646 insertions(+), 3 deletions(-)
```

### Not run / not verifiable here
- Full PHPUnit suite (forbidden), full `pnpm lint` (laptop swap; scoped ESLint + tsc run instead).
- `node tools/audit-i18n-completeness.mjs` — **fails closed locally**: `i18n completeness — FAIL CLOSED: I18N_BASELINE_PROTECTED_BLOB is unset.` (owner-set repo variable, CI-only). The three web bundles were edited symmetrically (same key path, `payments.refund.refusedSupplierPayment`, in en/fr/ar at `:135`), verified by re-parsing each JSON after the edit.
- No browser leg: no local stack was started for this round.
- A `react-doctor` commit advisory fired on the staged web file. Run on `PaymentDetailPage.tsx` it reports 6 warnings, score 90/100, all on PRE-EXISTING lines (`:144` giant component / complexity, `:165` state-only-in-handlers, `:286` and `:312` floating `mutateAsync`, `:318` Intl formatter) — none on the 26 lines added here (`:365-437`). The tool also prints "React Doctor is not installed in this project", so it is not a project gate.
- Whether any real tenant already carries rows written by the bug (gate "could not verify" #1) — unchanged, see FU-2.

---

## Follow-ups for the orchestrator to file

**FU-1 (from finding 3 — the gap this PR creates, brief item 3).**
*Title:* Supplier-invoice payment reversal lane (the missing undo path).
*Why:* after this PR a completed `PaymentType::SupplierPayment` row (wrong amount, wrong supplier, duplicate) has no undo in any surface: the customer refund lane refuses it (this PR), `reversePayment()` refuses it (`PaymentType.php:231` → `Unsupported`), `VendorRefundService::refundPrepayment()` only serves purchase orders (`VendorRefundService.php:53-56`), and there is no `DELETE /payments/{payment}` route in `Treasury/Presentation/routes.php`. The operator message now says so honestly and points at an administrator, which is a stopgap, not a lane.
*Where it belongs:* `apps/api/app/Modules/Treasury/Domain/Services/` (a supplier-side reversal writer beside `VendorRefundService`), a payment-anchored route in `Treasury/Presentation/routes.php`, GL `Dr supplier payable 401 / Cr bank` with cash IN, document-per-action justified, plus the `PaymentDetailPage` action. Needs an owner ruling on the document that justifies it before implementation.

**FU-2 (from finding 8, verbatim from the gate — left untouched by this round).**
> ### 8. [MINOR] No census/remediation for rows the bug already wrote
> The finding measured real damage: `Dr customer_receivable 5.000` on a supplier payment and `partners.payable_balance` 18.800→23.800 (`docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:136`). The PR stops new occurrences but ships no query, command or note identifying rows already written (`journal_entries.source_type = 'customer_payment_refund'` whose original payment is `payment_type = 'supplier_payment'`). At minimum, add the detection SQL to the PR body / handover so ops can check each tenant before promotion. I could not verify whether any non-test tenant carries such rows (see §Could not verify).

**FU-3 (new, found during this round).** `tests/Feature/Treasury/PaymentTest.php:578` asserts `PaymentType::DocumentPayment` for a payment allocated to a `SupplierInvoice`, which `PaymentController.php:936` types `SupplierPayment`. Red on `dev` (`fa000edc3`) before this PR and before this fix — proof block above. It is a one-line stale assertion, but it is out of this fix round's scope (rule 4) and someone should own it before promotion.

**Left untouched, verbatim from the gate (orchestrator decides):**

> ### 6. [MINOR] The `canRefund()` comment states something that is not true of `canRefund()`
> `PaymentRefundService.php:1146-1147` claims "the negative Refund/Reversal rows are refused earlier on amount". `canRefund()` has **no** amount or subject check — only the status check at `:1138-1141`. Refund rows are written with `'status' => PaymentStatus::Completed` (`:207`, `:388`), so `canRefund()` still returns `true` for a refund row, the UI still offers the action, and the write path then 422s via `assertRefundableSubject()` (`:463-476`). Pre-existing gap, but the PR's stated goal ("the UI never offers an action that will 422") is not met and the comment asserts otherwise. The fix in #5 closes both.

(Note: the untrue *comment* is gone — the replacement at `PaymentRefundService.php:1123-1136` states the `Refund`/`Reversal` gap explicitly instead of denying it. The *behaviour* half of finding 6 is untouched.)

> ### 7. [MINOR] Idempotent-replay asymmetry for rows written before the fix
> In `refundPayment()` the guard at `:159` runs **after** the already-reversed replay return at `:150-152` (`findExistingFullRefund`) but **before** `findExistingRefundByRequestId()` at `:162`. So for a supplier payment that was already (wrongly) refunded pre-fix: a replay of the same `refund_request_id` now 422s, while a payment already flipped to `Reversed` replays 200/existing. In `partialRefund()` the guard at `:349` precedes the request-id replay at `:352` unconditionally, so every retry 422s. Defensible for an outlawed shape, but it is an undocumented change to the Task-18 idempotency contract; state the intent in the docblock (one line) or move the guard after the replay lookups.

> ### 10. [INFO] No second-company / cross-company case in the new test (rule 22)
> `PaymentRefundRefusalTest.php:82-96` builds exactly one tenant + one company. `payments` is not a catalogue entity, so `docs/conventions/09-SECOND-OF-EVERYTHING.md` does not mandate the second-company row here — but the lane it guards *is* company-scoped (`PaymentRefundController.php:31-38`) and the cheapest possible addition is one case proving a supplier payment in company B is refused identically. Not a blocker.

> ### 11. [INFO] The operator-facing noun is undeclared, and the message leaks an internal class name
> `docs/glossary.md` has no "supplier refund lane" / "vendor refund" row (only `Receipt`/`Purpose` mention refunds, `:52`, `:72`). The 422 text names the PHP class `VendorRefundService` (`PaymentRefundService.php:519`) — internals in an operator string. Fold into the fix for #2/#3 and add the glossary row for whatever the lane ends up being called (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`).

(Note: the class-name half is fixed — no operator string names a class, asserted by a test. The **glossary row is NOT added**: with FU-1 unbuilt the lane has no operator name yet, so declaring one now would name a surface that does not exist. It belongs with FU-1.)

> ### 12. [INFO] PR-body claims that the gate could not reproduce
> The body states "Neighboring suites pass" and lists five Treasury suites — the Fiscal §13 suite that this change breaks is not among them, and "Baseline-vs-branch comparison in progress; will confirm in a comment" is still open. The ~35 `ClosedFiscalPeriodException` failures the body calls pre-existing are consistent with what I saw (`tests/Feature/Treasury/DeferredSupplierPaymentTest.php` hardcodes `payment_date => '2026-07-18'` at `:177`, `:199`, `:281`, `:341`, `:370`, now inside a closed period); that file calls none of the three changed methods, so it cannot be affected by this diff.
