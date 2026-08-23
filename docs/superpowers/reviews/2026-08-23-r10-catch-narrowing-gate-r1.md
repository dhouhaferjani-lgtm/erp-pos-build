# Adversarial merge gate — round 1 — lane `fix/r10-catch-narrowing`

- **Worktree reviewed (read-only):** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/r10-catch-narrowing`
- **Commit:** `1b459e876` ("r10: per-site catch narrowing — the two LIVE InvalidArgumentException arms")
- **Base:** `fa807a699` (local dev)
- **Authority:** `docs/handoff/HANDBACK-enforcement-p3-2026-08-21.md:350` (R-10) + `docs/handoff/reviews/enforcement-p3/M1-census.md:571,581,803` (§5.5 rows 7/17, §6 R-10)
- **Reviewer posture:** every claim below re-derived from code read in the worktree. Nothing accepted from the implementer's narrative.
- **Worktree left CLEAN** (`git status --short` → empty after the red-first experiment; verified).

---

## 1. Diff shape and catch-arm ORDER (claim 1) — VERIFIED

`git diff --stat fa807a699..1b459e876` → **4 files, 772 insertions, 0 deletions**. Strictly additive; no deletions anywhere.

Files:
- `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php` (+39)
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php` (+34)
- `apps/api/tests/Feature/Document/GoodsReceiptConversionUnbalancedGlNarrowingTest.php` (new, 309)
- `apps/api/tests/Feature/POS/ReceiptReturnUnbalancedGlNarrowingTest.php` (new, 390)

**Arm order (the load-bearing part — PHP matches top-down):**

Site 1, `DocumentConversionController::receivePurchaseOrderGoods` (method opens at `:391`):
- `:419` `} catch (UnbalancedJournalEntryPostException $e) {` … `throw $e;`
- `:457` `} catch (\InvalidArgumentException $e) {` → 422 `VALIDATION_ERROR`
- `:464` `} catch (\DomainException $e) {`
- `:471` `} catch (\RuntimeException $e) {` → 422 `GOODS_RECEIPT_ERROR`

Site 2, `POS/ReceiptController::processReturn` (method opens at `:270`):
- `:366` `} catch (LegacyCorrectionRetiredException $e) {` (pre-existing narrow arm, untouched)
- `:379` `} catch (UnbalancedJournalEntryPostException $e) {` … `throw $e;`
- `:412` `} catch (\RuntimeException $e) {` → 422 `RETURN_FAILED`
- `:419` `} catch (\InvalidArgumentException $e) {` → 400 `INVALID_RETURN_DATA`

In both methods the new arm **precedes** the broad `\InvalidArgumentException` arm, so it wins. Base line numbers confirmed against `git show fa807a699:…` — IAE arms were at `:418` (Document) and `:385` (POS), exactly the authority's coordinates. The `\RuntimeException` arms and sibling IAE arms are byte-identical (0 deletions proves it structurally).

**No exception re-parenting** (M1-D7(iii) respected): `app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryPostException.php` is NOT in the diff.

## 2. Rethrow disposition and renderer interception (claim 2) — VERIFIED

`bootstrap/app.php` registers 40 typed `render()` callbacks (`:245`–`:918`) plus the catch-all at `:958`. I enumerated every one: **none is a parent of `\InvalidArgumentException`**. The nearest broad handler is `DomainException` at `:920` — `UnbalancedJournalEntryPostException extends \InvalidArgumentException extends \LogicException`, and `DomainException` is a *sibling* `LogicException` subclass, not an ancestor. So the refusal reaches the catch-all at `bootstrap/app.php:958-977` → `500 {error:{code:'INTERNAL_ERROR', message, request_id}}`.

- No `HttpExceptionInterface` / `HttpResponseException` short-circuit applies (`:964`).
- No module-level renderer exists: `grep -rn "renderable(" app/` → 0 hits; `app/Exceptions/` does not exist.
- No `dontReport`/`stopIgnoring` entries in `bootstrap/app.php`, so the Sentry `reportable` hook at `:212` still fires → the "**unmapped 500 + alert**" half of the contract holds.

**Consistency with the P3-pinned contracts:** `tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php:131-165` pins only the *parentage* of the two unbalanced types (`is_subclass_of` assertions) — it contains **no line-number assertions**, so this lane cannot break it. I ran it: green. The CreditNote sibling contract (`CreditNoteController.php:409` renders `500 CONFIGURATION_ERROR` for the post-seal `UnbalancedJournalEntryException`) is a different type on a different arm and is untouched. Both dispositions are 500-class → "never a 4xx" is honoured on both routed paths. The status codes differ (`CONFIGURATION_ERROR` vs `INTERNAL_ERROR`) but so do the exception types; that is the split M1 deliberately created.

## 3. Reachability census, site 1 (claim 3) — VERIFIED, and stronger than the census says

`grep -rn "receivePurchaseOrderGoods" --include='*.php'` over the whole `apps/api` tree returns **only**: the definition (`DocumentConversionController.php:391`), the lane's own comment/test text, and the source-anchored assertion in `tests/Feature/Document/DocumentConversionTenantIsolationTest.php:227-248`.

- `grep -rn "DocumentConversionController" routes/ app/` shows 6 routed methods in `app/Modules/Document/Presentation/routes.php` (`:79,:83,:112,:116,:179,:299`) — `receivePurchaseOrderGoods` is not among them.
- No dynamic registration: `Route::resource` / `apiResource` / `any` / `match` → **0 hits** anywhere in `routes/` or the Document module.
- The routed receive is `Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])` at `app/Modules/Document/Presentation/routes.php:**262**` (implementer said `:263` — see P3-1), and `PurchaseOrderController::receive` calls `$this->goodsReceiptService->createDraft(...)` directly, **not** the DocumentConversionController method.
- Second independent count also holds: `app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php:41` is `} catch (\Throwable $e) {` (swallows), and the unswallowed fail-closed GR-IR call at `app/Modules/Inventory/Application/Services/GoodsReceiptService.php:409` is gated by `$pending['failClosed'] === null` (`:405`), armed only by `post(..., failClosedGrir: true)` whose **sole** caller repo-wide is `app/Modules/Procurement/Application/StandaloneReceiptService.php:134`.

**Conclusion:** the claim is TRUE, and it *supersedes* the census, which marked row 7 `:418` as "reachable" (`M1-census.md:571`). Site 1's arm is a correctness-by-construction guard, not a live-bug fix — and the test's container substitution is therefore not masking a live path.

## 4. Reachability census, site 2 (claim 4) — MECHANISM VERIFIED; one sub-claim in the code comment is FALSE

**The voucher path is genuinely live and lands in the controller's `try`. Verified end-to-end in the framework, not assumed:**

1. `ReceiptReturnService::processReturn()` opens the outermost transaction at `app/Modules/POS/Application/Services/ReceiptReturnService.php:198` (`return DB::transaction(function () use (…)`), called synchronously from inside the controller's `try`.
2. `RefundDestination::StoreVoucher` → `executeVoucherIssuance` (`:383`, defined `:852`) → `VoucherIssuanceService::issueFromRefund` → `GeneralLedgerService::createVoucherLedgerEntry` (`GeneralLedgerService.php:2641`).
3. That method's own `DB::transaction` closes at `:2761`; the post is issued **after** it at `:2765` via `postEntryAndDispatchPostedEventAfterCommit`.
4. `postEntryAndDispatchPostedEventAfterCommit` (`GeneralLedgerService.php:96-111`) branches on `DB::transactionLevel() > 0` → registers `DB::afterCommit(...)`. The outer `DB::transaction` at `ReceiptReturnService:198` keeps the level > 0, so it defers.
5. **The decisive framework detail:** in `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php`, the `try/catch` around commit covers only the PDO commit (`:51-64`); `$this->transactionsManager?->commit(...)` is called at **`:66-70`, OUTSIDE any try**. `DatabaseTransactionsManager::commit()` executes the deferred callbacks at `:94` (`$forThisConnection->map->executeCallbacks()`). So an exception thrown by an afterCommit callback propagates **uncaught out of `DB::transaction()`** into the caller — i.e. into `ReceiptController::processReturn`'s `try`.

The transaction-nesting claim is therefore correct, and it is proven empirically by the red-first run (§6): on base the request returns **400**, which is only possible if the refusal reached that catch.

**FALSE sub-claim (finding P2-1):** the in-code comment at `ReceiptController.php:388-392` asserts the OriginalPayment settlement also posts GL synchronously via
`executePaymentRefund (:906) -> PaymentRefundService::postRefundGlAndMovement -> createPaymentRefundJournalEntry(..., PostingMode::SynchronousInTransaction)`.
I read the whole of `refundReceiptPayments` (`app/Modules/Treasury/Domain/Services/PaymentRefundService.php:2007-2226`): `grep` for `glService|postRefundGlAndMovement` over that exact range returns **nothing**. The method creates negative `Payment` rows (`:2159`) and registers a `DB::afterCommit` that dispatches `PaymentRefunded` (`:2208-2222`); the event's only listener is `app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1084` (audit projection). `postRefundGlAndMovement` is invoked only from `refundPayment` (`:235`) and `partialRefund` (`:377`), neither of which the POS return path reaches.

Reachability of the arm still stands on the voucher path alone (the one the test actually drives), so this is a **documentation defect, not a behavioural one**. But it is a code-anchored provenance claim in a census-backed docblock that future maintainers will trust, and it is exactly the class the P3 handback itself graded "Important" (`HANDBACK-enforcement-p3-2026-08-21.md:440`).

The R-11 cross-reference in the same comment IS accurate: `ReceiptReturnService.php:513-524` is `try { $this->glBuffer->flushIfOutermost(contained: true); } catch (\Throwable $e) { … Log::error(…) }` with a retryable re-throw.

## 5. Genuine-4xx regression coverage and hierarchy collision (claim 5) — VERIFIED, structurally airtight

`UnbalancedJournalEntryPostException` is declared **`final`** (`UnbalancedJournalEntryPostException.php:47`) and is thrown from **exactly one place** repo-wide: `GeneralLedgerService.php:3520` (`throw UnbalancedJournalEntryPostException::forChokepoint(...)` guarded by the balance comparison at `:3519`). `grep -rn "UnbalancedJournalEntryPostException" app/ tests/` confirms no other `throw`, no subclass (impossible — `final`), and `ChokepointUnbalancedGuardTest.php:161` pins that it does not inherit the post-seal type.

Therefore **no genuine validation IAE can ever be intercepted by the new arm.** The narrowing is provably behaviour-preserving for every other `\InvalidArgumentException`. I spot-checked the listed genuine sites:
- `GeneralLedgerService.php:1881` / `:1885` — plain `new \InvalidArgumentException` (GR-IR numeric-string guards). Unaffected.
- `PaymentRefundService.php:2021` (`totalToRefund must be greater than zero`) and `:2075` — plain IAE. Unaffected.
- `DocumentConverterRegistry.php:93` and `PurchaseOrderToGoodsReceiptConverter.php:123` — covered by the two green regression tests.

Regression coverage is representative for the two touched contracts (422 `VALIDATION_ERROR`, 422 `GOODS_RECEIPT_ERROR`, 400 `INVALID_RETURN_DATA`). The `\DomainException` arm at `DocumentConversionController:464` is not pinned by a test (P3-5, minor).

## 6. Red-first evidence (claim 6) — REPRODUCED INDEPENDENTLY

Environment sanity first (stale-vendor trap): `ReflectionClass` resolution confirms both controllers **and** the Laravel framework resolve to files under `.worktrees/r10-catch-narrowing/…`, not the main checkout.

**Green on tip:**
```
php vendor/bin/phpunit tests/Feature/Document/GoodsReceiptConversionUnbalancedGlNarrowingTest.php \
                       tests/Feature/POS/ReceiptReturnUnbalancedGlNarrowingTest.php
OK (5 tests, 16 assertions)
```

**Red on base controllers** (`git checkout fa807a699 -- <the two controllers>`, tests unchanged):
```
F..F.                                                               5 / 5 (100%)
1) GoodsReceiptConversionUnbalancedGlNarrowingTest::test_chokepoint_balance_refusal_is_not_downgraded_to_422_validation_error
   Expected response status code [500] but received 422.
2) ReceiptReturnUnbalancedGlNarrowingTest::test_chokepoint_balance_refusal_is_not_downgraded_to_400_invalid_return_data
   Expected response status code [500] but received 400.
Tests: 5, Assertions: 11, Failures: 2.
```
Exactly the two claimed downgrades, at exactly the claimed codes; the three regression tests stayed green on base (correct — they must not depend on the change). Restored with `git checkout 1b459e876 -- <the two controllers>`; `git status --short` empty and `git diff HEAD --stat` empty.

**Neighbouring pinned suites, on tip:**
- `ChokepointUnbalancedGuardTest` + `DocumentConversionTenantIsolationTest` → OK (17 tests, 38 assertions). Note the tenant-isolation test at `:242-247` slices the method body by source anchor; the +39-line comment does not break it.
- `ReceiptReturnRefactorV3Test` + `ReceiptReturnFlowTest` → OK (30 tests, 119 assertions, 9 skipped — skips pre-exist).
- PHPStan on all four changed files → `[OK] No errors`. Pint `--test` on all four → `{"result":"pass"}`.

## 7. Zero-mock rule and model-hook containment (claim 7) — VERIFIED

The POS test's `unbalanceVoucherLedgerEntries()` (`ReceiptReturnUnbalancedGlNarrowingTest.php:647-677`) matches the precedent at `tests/Feature/Document/DocumentConversionScenarioTest.php:300-322`: same `JournalLine::created` hook, same `source_type` + `Draft` filter, same injected `'debit' => '7.000'` / `line_order => 99` leg. The recursion guard differs (`line_order !== 0` early-return instead of the precedent's `$injecting` flag) and is if anything tighter — it injects exactly one extra leg per entry rather than one per production leg.

Nothing is mocked on this path: the controller, `ReceiptReturnService`, `VoucherIssuanceService`, `GeneralLedgerService` and the chokepoint are all production classes. The test also carries a non-trivial second assertion (`JournalEntry` with `source_type='voucher_ledger'` and `status=Posted` must be 0), and it cannot pass silently if the hook fails to fire — a balanced entry would post and the endpoint would return 201, failing `assertStatus(500)`.

**Leak check (run, not reasoned):** model-event listeners live on the app's event dispatcher, which `RefreshDatabase` rebuilds per test. I confirmed empirically by forcing the narrowing test to run **first** in one process ahead of two GL-heavy suites:
```
phpunit -c <scratch>/leak.xml   # ReceiptReturnUnbalancedGlNarrowing → ChokepointUnbalancedGuard → DocumentConversionScenario
OK (18 tests, 50 assertions)
```
No leakage. No `tearDown` override is needed.

Site 1's test uses a **container substitution** (`$this->app->instance(DocumentConverterRegistry::class, …)`) plus a **test-only route**. The test-only-route technique matches the established precedent `tests/Feature/Bootstrap/RefundFlowExceptionRenderingTest.php:32-44`. The substitution is confined to the refusal test; the two regression tests use the real registry and real documents. Given site 1 is provably unrouted (§3), the substitution replaces a *collaborator*, not the unit under test (the catch arm) — acceptable, and disclosed honestly in the class docblock. There is no repo guard forbidding it (no zero-mock ratchet exists in `scripts/` or `apps/api/tools/`).

## 8. Scope discipline (claim 8) — VERIFIED

Exactly 4 files, all in scope. No routes file, no ratchet/baseline (`deptrac.baseline.json`, `scripts/lint-warning-baseline.json`), no manifest, no i18n, no generated types, no other worktree touched.

**Boundary check (rule 6):** both controllers gain `use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;` — a cross-module import of another module's Domain exception. `deptrac.yaml` is glob-per-tier and explicitly does **not** enforce cross-module coupling (`deptrac.yaml:17-22`); `ModulePresentation → ModuleDomain` is an allowed edge (`:114-121`). So **no new deptrac violation**, and the import is consistent with existing practice in the same file (`ReceiptController.php:11,12,38` already import Fiscal/Identity/Voucher classes). Not raised as a finding.

---

## Findings

### P2-1 — False code-anchored call chain in the site-2 justification comment
**File:** `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:388-392`
The comment states the arm is reachable via
`executePaymentRefund (':906') -> PaymentRefundService::postRefundGlAndMovement -> createPaymentRefundJournalEntry(..., PostingMode::SynchronousInTransaction)`.
That chain does not exist. `refundReceiptPayments` — the only thing `executePaymentRefund` calls (`ReceiptReturnService.php:906`) — spans `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:2007-2226` and contains **no** `glService` reference and **no** `postRefundGlAndMovement` call; it writes negative `Payment` rows and dispatches `PaymentRefunded` in `DB::afterCommit` (`:2208-2222`), whose only listener is the Compliance audit subscriber (`app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1084`). `postRefundGlAndMovement` is reached only from `refundPayment` (`:235`) and `partialRefund` (`:377`), neither on the POS return path.
**Why it matters:** this comment is the durable census record for why the arm exists. A future maintainer auditing "is this arm still needed?" will chase a chain that does not exist, and may conclude the OriginalPayment path is GL-covered when it is not. The lane's *behavioural* conclusion is unaffected — the voucher path alone makes the arm live and the red-first run proves it.
**Fix:** delete the `executePaymentRefund` clause from lines 388-391 and keep only the voucher path, e.g. "It is REACHABLE here via `executeVoucherIssuance` (`ReceiptReturnService:882`) → `VoucherIssuanceService` → `GeneralLedgerService::createVoucherLedgerEntry` (`:2641`), whose `postEntryAndDispatchPostedEventAfterCommit` (`:96`) defers to the outer `DB::transaction` at `ReceiptReturnService:198`; `ManagesTransactions::transaction()` calls `transactionsManager->commit()` OUTSIDE its try (`:66`), so the refusal propagates into this method's `try`. The OriginalPayment settlement (`executePaymentRefund` → `PaymentRefundService::refundReceiptPayments`, `:2007`) posts no GL on this path." No test change required.

### P3-1 — Off-by-one route citation
**File:** `apps/api/tests/Feature/Document/GoodsReceiptConversionUnbalancedGlNarrowingTest.php:52` (and the equivalent prose in `DocumentConversionController.php:436-437`)
Cites `Document/Presentation/routes.php:263` for `PurchaseOrderController::receive`; the actual registration is `app/Modules/Document/Presentation/routes.php:262` (`:258` is the `confirm` route).
**Fix:** change `:263` → `:262`.

### P3-2 — Line-ref rot injected into two neighbouring pinned artifacts
**Files:** `apps/api/app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryPostException.php:36` and `apps/api/tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php:124-125`
Both cite `DocumentConversionController:432` and `POS/ReceiptController:378` as the `catch (\RuntimeException)` arms. After this lane those arms are at `:471` and `:412` respectively; `:432` and `:378` now land inside the lane's own comment blocks (and `:378` is one line above the new arm — maximally confusing).
**Why it matters:** comment-only, no assertion breaks (verified: `ChokepointUnbalancedGuardTest` asserts only `is_subclass_of`, and it is green). But these are the M1 census's cited anchors and this lane silently invalidated them.
**Fix:** refresh both to `DocumentConversionController:471` / `POS/ReceiptController:412`, or annotate them as base-relative (`as of fa807a699`). Two-line change; if the parent prefers zero out-of-scope edits, record it as a ledger owe instead.

### P3-3 — Census row 7's "reachable" marking is now superseded and unrecorded
**File:** `docs/handoff/reviews/enforcement-p3/M1-census.md:571` (row 7) and `:803` (R-10 register)
The census records `DocumentConversionController:418` as "reachable → R-10 (pre-existing)". This lane proves it is **not** reachable (no route; GR-IR listener swallows). The lane documented the correction only in a code comment; the census/register carries no supersession marker, and R-10 is not marked closed anywhere.
**Fix (parent-ledger item, outside the lane's ruled scope):** add a supersession row to the R-10 register noting (a) row 7 is unreachable-by-route, (b) the two narrow sites are now closed by `1b459e876`, and (c) the remaining R-10 tail (`RefundController:154,254`; `MultiPaymentController:214,338`; `PaymentRefundController:89,128,177`; `TenantScopedCommand:333`) is still open.

### P3-4 — Undocumented client-visible consequence of 4xx → 5xx on the LIVE arm
**Files:** `apps/pos/src/lib/refundFlow/refundSettlementService.ts:205-210` and `:248`; `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:218-223,241-247`
The POS device retries any status `>= 500` (`isRetryableServerError`), replaying a byte-identical payload with the same `refund_request_id`. Because the return receipt is **already committed** when the refusal fires (the PDO commit at `ManagesTransactions:54` precedes the afterCommit callback at `DatabaseTransactionsManager:94`), the retry hits the idempotency replay at `ReceiptReturnService:218-223` / `:241-247` and returns the existing receipt as **201**.
Net effect: no double refund and no data-outcome change vs base (verified — the unique partial index on `(company_id, refund_request_id)` and the two replay checks make it safe), but the cashier now ultimately sees SUCCESS after one 500 + backoff, where base showed a terminal 400. The server-side Sentry alert still fires on the first attempt (`bootstrap/app.php:212`), so the "500 + alert" contract holds where it matters.
**Fix:** no code change required. Record this in the lane note / ledger so nobody later reads the docblock's "500" as what the cashier sees, and so the extra `MAX_RETRIES × backoff` latency on a genuinely broken GL is a known property.

### P3-5 — `\DomainException` arm at site 1 has no regression pin
**File:** `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:464`
The new test class pins the IAE arm (`:457`, 422 `VALIDATION_ERROR`) and the RuntimeException arm (`:471`, 422 `GOODS_RECEIPT_ERROR`) but not the `\DomainException` arm sandwiched between them. Not a defect introduced here (the arm is untouched and the new arm cannot shadow it), but the regression net has a hole if a future edit reorders arms.
**Fix (optional, cheap):** one more case driving a `\DomainException` through the same test-only route, or a note that the arm is intentionally unpinned.

---

## Assessment

The engineering is sound and, on the points that decide correctness, better-evidenced than claimed:
- The change is provably behaviour-preserving for every non-chokepoint `\InvalidArgumentException`, because the caught type is `final` with a single `throw` site (`GeneralLedgerService.php:3520`).
- Arm order is right in both methods; the global renderer really does emit `500 INTERNAL_ERROR` with the report hook intact; no intermediate renderer or middleware intercepts.
- The afterCommit propagation claim — the one load-bearing assumption that could have made the POS test prove nothing — is verified in the framework source and confirmed by an independent red-first reproduction (400 → 500).
- Scope, style, static analysis, and neighbouring pinned suites are all clean, and the worktree was left clean.

The single blocking item is P2-1: a code-anchored provenance claim that is factually wrong about `PaymentRefundService`. In a program where these comments *are* the census record, shipping a false call chain into the justification for a fiscal-integrity guard is the defect class this delivery exists to eliminate. It is a comment-only, one-hunk fix with no test impact — but it must land before merge. P3-1/P3-2 are cheap enough to fold into the same commit.

**Re-gate scope for round 2:** re-read `ReceiptController.php:379-411` only, plus `git diff --stat` to confirm the fix stayed comment-only. No re-run of the suites is required if the diff is comments-only.

VERDICT: CHANGES-REQUIRED
