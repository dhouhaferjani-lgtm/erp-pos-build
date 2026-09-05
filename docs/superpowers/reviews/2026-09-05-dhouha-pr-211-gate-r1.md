# Adversarial merge gate r1 — PR #211 (F-W2-13, supplier payments in the customer refund lane)

| Field | Value |
|---|---|
| PR | #211 `fix(treasury): refuse supplier payments in the customer refund lane (F-W2-13, P0)` |
| Author | dhouhaferjani-lgtm · head `fix/treasury-supplier-refund-lane-f-w2-13` · commit `c8c4aa55f90e54cbc2b6e934b89338d54c448895` |
| Base | `dev` |
| Gate worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211` (branch `gate/pr-211`) |
| Merged sha reviewed | `1ea64999068a61a531d39c3587a6113a7839a514` (merge of `refs/pr/211` into local dev `fa000edc3`) |
| Diff | 2 files, +395/-0 — `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php` (+80), `apps/api/tests/Feature/Treasury/PaymentRefundRefusalTest.php` (new, +315) |
| Reviewer | treasury-reviewer (Opus), 2026-09-05 |

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

The **supplier-payment half** of the fix is correct, minimal, well-placed and provably falsifiable. It is blocked by one hard regression that the author did not run, plus a user-facing half that does not actually land.

**What is right** (verified, not taken on trust):
- The guard sits on the DB-locked `$original` inside the transaction and **before any write**, on both write entry points — `PaymentRefundService.php:159` (refundPayment) and `:349` (partialRefund) — so every caller of the service is covered, not just the controller. The only two production callers are `PaymentRefundController` (`PaymentRefundController.php:78`, `:116`, `:192`) and `ReceiptReturnService` → `refundReceiptPayments()` (`ReceiptReturnService.php:951`), which is a separate writer and is deliberately (and correctly) untouched.
- SoT reuse is real: `assertRefundableType()` (`PaymentRefundService.php:498-503`) delegates to `PaymentType::reversalSupport()` (`PaymentType.php:223-239`), the same enum the D-6 reversal gate uses.
- No magic strings (enum `match`, no `default`), `declare(strict_types=1)`, constructor injection untouched, no `app()` in production code, **no money arithmetic added at all** — the precision contract (rule 19) is not touched; no float, no `parseFloat`, no `number_format`.
- Falsifiability **empirically proven** (see §Test runs): with the production hunk reverse-applied, 10 of the 11 new tests fail; the 11th is the customer-payment regression test, which correctly passes both ways.
- PHPStan level 8 and Pint clean on both touched files.

---

## Findings

### 1. [BLOCKER] The PR breaks two pre-existing tests it never ran — `tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:435` and `:471`

`PaymentRefundService.php:498-503` refuses **every** `reversalSupport() === Unsupported` shape, which includes `PaymentType::POS` and `PaymentType::POSRefund` (`PaymentType.php:232`, `:235`). The §13 `Payment.origin` writer-inventory suite pins rows 7 and 8 by refunding a **POS-typed** payment through this exact lane:

- `tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:736-752` — `seedPosOriginPayment()` creates `'payment_type' => PaymentType::POS`.
- `:435-453` `test_refund_inherits_pos_origin_when_original_is_pos`
- `:471-480` `test_partial_refund_inherits_pos_origin_when_original_is_pos`

Both now die with `DomainException: a POS payment cannot be refunded here …` thrown at `PaymentRefundService.php:501`.

**Proved this is the PR, not pre-existing drift** — with the production hunk reverse-applied on the same merged tree the suite is `OK (15 tests, 34 assertions)`; with the hunk applied it is `Tests: 15, Assertions: 32, Errors: 2` on sqlite and `2 failed, 13 passed` on PostgreSQL. Verbatim output in §Test runs.

**Why it matters:** the §13 writer inventory is the file whose entire purpose is "one assertion per writer row so a writer that stops stamping `origin` fails loudly" (`PaymentOriginWriterInventoryTest.php:50-58`). Silently red-ing it is exactly the failure mode it exists to prevent, and it lands on `dev` as a new red.

**Fix (preferred):** narrow the guard to the finding's actual scope (rule 4 — F-W2-13 is a *supplier-payment* finding):
```php
if ($payment->payment_type === PaymentType::SupplierPayment) {
    throw new \DomainException($this->unsupportedRefundMessage($payment->payment_type));
}
```
and drop `PaymentType::POS`/`POSRefund` from the `canRefund()` list at `:1150-1156`. Both §13 tests then pass unchanged and finding #4 disappears with them.

**Fix (alternative, needs an owner ruling first):** keep the POS refusal, but then it is its own behaviour change with its own gate — update the §13 rows 7/8 to a supported type, record the ruling, and answer finding #4.

---

### 2. [MAJOR] The refusal message can never reach the operator — the 422 body shape and `getErrorMessage()` do not match

`PaymentRefundController::refundPayment()` returns `['error' => $e->getMessage()]` — a **plain string** (`PaymentRefundController.php:88-90`; same at `:125-127` for `partialRefund`).
The web client reads `data.error.message` then `data.message` then falls back to the axios string (`apps/web/src/lib/api.ts:83-98`):
```ts
return (
  readString(envelope, 'message') ??   // data.error is a STRING here -> null
  readString(data, 'message') ??       // absent -> null
  (error.message !== '' ? error.message : 'An unexpected error occurred')
)
```
so `PaymentDetailPage.tsx:233` (`toast.error(getErrorMessage(error))`) shows **"Request failed with status code 422"**. The carefully worded "use the supplier refund lane" text is unreachable in the product. And on the read side `canRefund() === false` only *disables* the buttons (`PaymentDetailPage.tsx:400`, `:408`) with **no reason rendered anywhere** — the operator gets a greyed-out button and no explanation.

The house pattern already exists two methods away in the same controller: `refundPrepayment()` returns `['error' => ['code' => 'REFUND_FAILED', 'message' => …]]` (`PaymentRefundController.php:255-261`), and Treasury already keys operator-facing refusal text in `apps/api/lang/en/treasury.php:25-31` (`allocation_refused.*`, mirrored in `lang/fr`, `lang/ar`).

**Fix:** catch `\DomainException` separately in `refundPayment()`/`partialRefund()` and return `['error' => ['code' => 'REFUND_LANE_REFUSED', 'message' => __('treasury.refund_refused.supplier_payment'), 'details' => ['payment_type' => …]]]`; add the key to `lang/en|fr|ar/treasury.php`; render a reason next to the disabled buttons in `PaymentDetailPage.tsx`. The new HTTP test at `PaymentRefundRefusalTest.php:236-239` must then assert `error.message`.

---

### 3. [MAJOR] The refusal points at a lane that structurally cannot serve the refused row — and no other path exists

`unsupportedRefundMessage()` tells the operator to "use the supplier refund lane (VendorRefundService)" (`PaymentRefundService.php:518-519`). Verified against code:

- `PaymentType::SupplierPayment` rows are produced **only** when the payment is allocated to a `DocumentType::SupplierInvoice` — `PaymentController.php:527-529` (`$isSupplierPayment = true;`), typed at `:936`.
- `VendorRefundService` has exactly one public method, `refundPrepayment()`, and it refuses anything that is not a Purchase Order: `VendorRefundService.php:53-56` — `if ($po->type !== DocumentType::PurchaseOrder) { throw new \DomainException('Prepayment refund is only available for Purchase Orders'); }`. Its route is `POST /documents/{document}/refund-prepayment` (`Treasury/Presentation/routes.php:215`), i.e. **document-anchored, PO-only**.

The two sets are disjoint. Additionally:
- `reversePayment()` already refuses `SupplierPayment` (`PaymentType.php:231` → `Unsupported`);
- there is **no** `DELETE /payments/{payment}` route in `Treasury/Presentation/routes.php` (the FE's `apiDelete('/payments/${id}')` at `PaymentDetailPage.tsx:203` has no backend counterpart there).

So after this PR a completed supplier-invoice payment (wrong amount, wrong supplier, duplicate) has **no undo path in any surface**. Blocking a wrong-money post is the right emergency call and matches the Q-10 hotfix intent recorded in `docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:29`, so this is not by itself a REJECT — but per the owner rule ("standard ERP logic applies as soon as implemented; no refuse-until defaults on correctly built flows") the PR must not ship a message that sends the operator to a lane which will refuse them too.

**Fix:** (a) reword to name the *action* the operator can actually take today and say plainly that the reversal of a supplier-invoice payment is not yet available; (b) file the follow-up lane (supplier payment reversal: `Dr Bank / Cr 401`, cash IN, document-per-action justified) and reference it in the PR/handover so the gap is tracked, not silently created.

---

### 4. [MAJOR] Refusing POS/POSRefund removes the only web-side POS refund path (scope creep beyond F-W2-13)

F-W2-13 is a supplier finding (`docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:256`, `02-scenario-matrix.md:527`). The PR additionally refuses `POS`/`POSRefund` at `PaymentRefundService.php:520-521` and `:1150-1156`. The redirect target, "the POS void/return lane", is `POST /pos/receipts/{id}/return` — and the web app deliberately does not expose it: `apps/web/src/features/pos/api/receiptApi.ts:21` — *"`POST /pos/receipts/{id}/return` route stays for the desktop POS flow."*

So a back-office user who today refunds a POS payment from `PaymentDetailPage` loses the action with no in-app alternative. Refusing it may well be *accounting*-correct (the lane posts Dr 411 for a sale that has no AR leg), but that is a second ruling on a second flow, not a drive-by inside a supplier hotfix. Rule 4.

**Fix:** remove the POS arms from this PR (see finding #1's preferred fix) and raise them as their own lane with an owner ruling + a web-side return surface, or get the ruling before merging this one.

---

### 5. [MINOR] `canRefund()` duplicates the source of truth instead of using it

`assertRefundableType()` asks `reversalSupport()` (`PaymentRefundService.php:500`), but `canRefund()` hardcodes a three-case list (`:1150-1154`). A future `PaymentType` mapped to `Unsupported` in `PaymentType::reversalSupport()` will be refused by the writer but still **offered** by the UI — the exact class of bug this PR is fixing. The null-safety justification at `:1147-1149` is answered by the null-safe operator.

**Fix:** `if ($payment->payment_type?->reversalSupport() === ReversalSupport::Unsupported) { return false; }` — note this also correctly refuses `Refund`/`Reversal` rows, which is finding #6.

---

### 6. [MINOR] The `canRefund()` comment states something that is not true of `canRefund()`

`PaymentRefundService.php:1146-1147` claims "the negative Refund/Reversal rows are refused earlier on amount". `canRefund()` has **no** amount or subject check — only the status check at `:1138-1141`. Refund rows are written with `'status' => PaymentStatus::Completed` (`:207`, `:388`), so `canRefund()` still returns `true` for a refund row, the UI still offers the action, and the write path then 422s via `assertRefundableSubject()` (`:463-476`). Pre-existing gap, but the PR's stated goal ("the UI never offers an action that will 422") is not met and the comment asserts otherwise. The fix in #5 closes both.

---

### 7. [MINOR] Idempotent-replay asymmetry for rows written before the fix

In `refundPayment()` the guard at `:159` runs **after** the already-reversed replay return at `:150-152` (`findExistingFullRefund`) but **before** `findExistingRefundByRequestId()` at `:162`. So for a supplier payment that was already (wrongly) refunded pre-fix: a replay of the same `refund_request_id` now 422s, while a payment already flipped to `Reversed` replays 200/existing. In `partialRefund()` the guard at `:349` precedes the request-id replay at `:352` unconditionally, so every retry 422s. Defensible for an outlawed shape, but it is an undocumented change to the Task-18 idempotency contract; state the intent in the docblock (one line) or move the guard after the replay lookups.

---

### 8. [MINOR] No census/remediation for rows the bug already wrote

The finding measured real damage: `Dr customer_receivable 5.000` on a supplier payment and `partners.payable_balance` 18.800→23.800 (`docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:136`). The PR stops new occurrences but ships no query, command or note identifying rows already written (`journal_entries.source_type = 'customer_payment_refund'` whose original payment is `payment_type = 'supplier_payment'`). At minimum, add the detection SQL to the PR body / handover so ops can check each tenant before promotion. I could not verify whether any non-test tenant carries such rows (see §Could not verify).

---

### 9. [MINOR] Data provider passes an argument the test signature does not take

`PaymentRefundRefusalTest.php:147-154` yields two-element arrays; `test_can_refund_is_false_for_an_unsupported_shape` (`:188`) declares one parameter. PHP tolerates the extra positional argument so the suite is green, but the data set name is misleading and a future reader will assume the fragment is asserted there. Split the provider or accept and ignore the second parameter explicitly.

---

### 10. [INFO] No second-company / cross-company case in the new test (rule 22)

`PaymentRefundRefusalTest.php:82-96` builds exactly one tenant + one company. `payments` is not a catalogue entity, so `docs/conventions/09-SECOND-OF-EVERYTHING.md` does not mandate the second-company row here — but the lane it guards *is* company-scoped (`PaymentRefundController.php:31-38`) and the cheapest possible addition is one case proving a supplier payment in company B is refused identically. Not a blocker.

---

### 11. [INFO] The operator-facing noun is undeclared, and the message leaks an internal class name

`docs/glossary.md` has no "supplier refund lane" / "vendor refund" row (only `Receipt`/`Purpose` mention refunds, `:52`, `:72`). The 422 text names the PHP class `VendorRefundService` (`PaymentRefundService.php:519`) — internals in an operator string. Fold into the fix for #2/#3 and add the glossary row for whatever the lane ends up being called (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`).

---

### 12. [INFO] PR-body claims that the gate could not reproduce

The body states "Neighboring suites pass" and lists five Treasury suites — the Fiscal §13 suite that this change breaks is not among them, and "Baseline-vs-branch comparison in progress; will confirm in a comment" is still open. The ~35 `ClosedFiscalPeriodException` failures the body calls pre-existing are consistent with what I saw (`tests/Feature/Treasury/DeferredSupplierPaymentTest.php` hardcodes `payment_date => '2026-07-18'` at `:177`, `:199`, `:281`, `:341`, `:370`, now inside a closed period); that file calls none of the three changed methods, so it cannot be affected by this diff.

---

## Test runs (verbatim)

All runs in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211/apps/api` on the MERGED tree `1ea649990`. PG legs used the private DB `autoerp_test_g211` (127.0.0.1:5433), created for this gate.

### New test — sqlite (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundRefusalTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: .../apps/api/phpunit.xml
...........                                                       11 / 11 (100%)
Time: 00:10.303, Memory: 165.00 MB
OK (11 tests, 46 assertions)
```

### New test — PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g211 DB_CENTRAL_DATABASE=autoerp_test_g211 \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundRefusalTest.php
   PASS  Tests\Feature\Treasury\PaymentRefundRefusalTest
  ✓ full refund of an unsupported shape refuses and writes nothing wit… 11.23s
  ✓ full refund of an unsupported shape refuses and writes nothing with… 1.93s
  ✓ full refund of an unsupported shape refuses and writes nothing with… 1.68s
  ✓ partial refund of an unsupported shape refuses and writes nothing w… 2.06s
  ✓ partial refund of an unsupported shape refuses and writes nothing w… 1.74s
  ✓ partial refund of an unsupported shape refuses and writes nothing w… 1.95s
  ✓ can refund is false for an unsupported shape with data set "supplie… 2.05s
  ✓ can refund is false for an unsupported shape with data set "pos"     1.87s
  ✓ can refund is false for an unsupported shape with data set "pos ref… 1.76s
  ✓ a genuine customer payment still refunds                             1.87s
  ✓ a full refund refusal surfaces as a 422 over the http api            2.09s
  Tests:    11 passed (46 assertions)
  Duration: 30.25s
```

### Falsifiability probe — production hunk reverse-applied, new test RED (10/11)
Method: `git diff dev HEAD -- …/PaymentRefundService.php | git apply -R`, run, then `git checkout HEAD -- <file>`; tree verified clean (`git status --porcelain` empty) afterwards. No code was left modified.
```
7) …::test_can_refund_is_false_for_an_unsupported_shape with data set "supplier payment" …
supplier_payment: the UI must not offer a refund action for a shape this lane refuses
Failed asserting that true is false.
…
10) …::test_a_full_refund_refusal_surfaces_as_a_422_over_the_http_api
Expected response status code [422] but received 201.
Failed asserting that 201 is identical to 422.
FAILURES!
Tests: 11, Assertions: 14, Failures: 10.
```
(The single passing test is `test_a_genuine_customer_payment_still_refunds`, which is the intended regression guard.)

### REGRESSION — `PaymentOriginWriterInventoryTest`, sqlite, WITH the fix (FAIL)
```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
.....E.E.......                                                   15 / 15 (100%)
There were 2 errors:
1) Tests\Feature\Fiscal\PaymentOriginWriterInventoryTest::test_refund_inherits_pos_origin_when_original_is_pos
DomainException: a POS payment cannot be refunded here — POS posts direct to revenue with no accounts-receivable leg; use the POS void/return lane.
  .../PaymentRefundService.php:501
  .../PaymentRefundService.php:159
  .../tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:443
2) Tests\Feature\Fiscal\PaymentOriginWriterInventoryTest::test_partial_refund_inherits_pos_origin_when_original_is_pos
DomainException: a POS payment cannot be refunded here — …
  .../PaymentRefundService.php:501
  .../PaymentRefundService.php:349
  .../tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:473
ERRORS!
Tests: 15, Assertions: 32, Errors: 2.
```

### Same file, sqlite, WITHOUT the fix (baseline PASS) — proves the PR caused it
```
$ git apply -R <prod hunk> && ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
...............                                                   15 / 15 (100%)
Time: 00:21.396, Memory: 167.00 MB
OK (15 tests, 34 assertions)
```

### Same file, PostgreSQL, WITH the fix (FAIL)
```
   FAILED  Tests\Feature\Fiscal\PaymentOriginWriterInventor…  DomainException
  a POS payment cannot be refunded here — POS posts direct to revenue with no accounts-receivable leg; use the POS void/return lane.
  at app/Modules/Treasury/Domain/Services/PaymentRefundService.php:501
  Tests:    2 failed, 13 passed (32 assertions)
  Duration: 66.66s
```

### Neighbouring suites, sqlite (all PASS)
```
tests/Feature/Treasury/PaymentRefundTest.php              OK (20 tests, 79 assertions)
tests/Feature/Treasury/PaymentRefundProrationTest.php     OK (26 tests, 79 assertions)
tests/Feature/Treasury/RefundSpineTest.php                OK (7 tests, 36 assertions)
tests/Feature/Treasury/PaymentReversalRefusalTest.php     OK (7 tests, 49 assertions)
tests/Feature/Treasury/VendorPrepaymentRefundTest.php     OK (11 tests, 31 assertions)
tests/Feature/Treasury/SupplierPaymentGuardTest.php       OK (6 tests, 24 assertions)
tests/Feature/Treasury/PaymentIdempotencyTest.php         Tests: 5, Assertions: 26 (5 PHPUnit deprecations, pre-existing)
tests/Feature/Treasury/MultiPaymentSpineTest.php          Tests: 6, Assertions: 45 (6 deprecations, pre-existing)
tests/Feature/Treasury/PaymentControllerSpineTest.php     Tests: 6, Assertions: 43 (6 deprecations, pre-existing)
tests/Feature/Treasury/PaymentRefundSecurityTest.php      OK (6 tests, 6 assertions)
tests/Feature/Treasury/PaymentReversalApiAndEventTest.php OK (4 tests, 26 assertions)
tests/Feature/Treasury/TreasuryEventDispatchTest.php      OK (7 tests, 17 assertions)
tests/Feature/POS/ReceiptReturnFlowTest.php               OK (21 tests, 119 assertions)
tests/Feature/POS/PosAnalyticsRefundNettingTest.php       OK (8 tests, 80 assertions)
tests/Feature/Dashboard/DashboardStatsTest.php            OK (17 tests, 47 assertions)
tests/Feature/Treasury/AdvanceReversalGlShapeTest.php     OK (12 tests, 71 assertions)
```

### Known pre-existing red (NOT caused by this PR)
```
tests/Feature/Treasury/DeferredSupplierPaymentTest.php    Tests: 6, Assertions: 1, Errors: 5, Failures: 1
  Cannot post journal entry dated 2026-07-18 … the fiscal period covering this date is closed.
```
The file hardcodes `payment_date => '2026-07-18'` (`:177`, `:199`, `:281`, `:341`, `:370`) and calls none of the three changed methods.

### Static analysis / style (PASS)
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Treasury/Domain/Services/PaymentRefundService.php \
    tests/Feature/Treasury/PaymentRefundRefusalTest.php
 [OK] No errors

$ ./vendor/bin/pint --test <same two files>
{"result":"pass"}
```

---

## What I could not verify

1. **Whether any real (non-test) tenant already carries rows written by the bug.** I only had the private gate DB; I did not query staging or any tenant database. The detection query would be `journal_entries.source_type = 'customer_payment_refund'` joined to the refund's `original_payment_id` where that payment is `payment_type = 'supplier_payment'`.
2. **Whether the owner has actually ruled Q-10.** `docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:29` says "confirm the ruling with the owner" and `docs/superpowers/reviews/2026-09-01-wave2-po-spec-gate-r1-stock-gl.md:253` records Q-10 as *blocked on evidence*. I found no file recording the ruling itself, so I cannot confirm the owner authorised "refuse" over "make the refund supplier-aware".
3. **The PR body's "~35 pre-existing Treasury failures" figure.** I did not run the whole Treasury suite (forbidden). I verified the one file I sampled is date-driven and diff-independent; I did not count the rest.
4. **Whether a back-office operator actually uses the POS-payment refund action today.** I verified the route/UI exists and that the web has no receipt-return surface, but not usage.
5. **Frontend test/lint/typecheck impact.** The diff touches no FE file, and I ran no `pnpm` gate.
6. **Whether the CI whole-suite lane is otherwise green on `fa000edc3`.** Out of scope for this gate.

---

## What to fix before merge

Drop `PaymentType::POS`/`POSRefund` from the guard and from `canRefund()` so the two §13 writer-inventory tests go green again, then make the refusal actually visible to the operator (structured `{error:{code,message}}` 422 + `lang/en|fr|ar/treasury.php` key + a reason on the disabled button) and reword it so it stops pointing at `VendorRefundService`, which only refunds PO prepayments.
