# M1 adversarial merge-gate review — round 4

**Range:** `26b63f0ff..HEAD` (57 commits; round-3 remediation = `338b0d28c..HEAD`) · **Lenses:** inventory-costing **and** fiscal-pos — both apply · **Authority applied:** `ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md` (amended M1 exit: pairs 1–6 green, pairs 7–10 red-with-evidence). Round 3's verdict file is an empty tool-error stub (`M1-round3.md:1-2`), so this round re-gates the whole milestone.

**Round-2 findings closed against code:** P1-1 (voucher pairs moved out of the `ci.yml:629` allowlist into `InventoryGlVoucherLockOrderTraceTest`), P2-2 (seam test skips loudly, `InventoryGlPostingSeamTest.php:44-48`), P2-3 (voucher scale reverted — `createVoucherLedgerEntry` is untouched in the range), P2-4 (`reverseInventoryWriteOffEntry` keeps inline `postEntry` + `$user !== null`, `GeneralLedgerService.php:5012`), P2-5 (evidence now states pairs 1–3/6 are sensitivity controls, `M1-evidence.md:21-26`), P2-6 (six real production mutation/revert pairs), P3-7, P3-10 (`QuantityScale::SCALE`), P3-11 (AST arg-scoped rule). What blocks is a live regression neither prior round reached, plus two gates that were never run.

---

## Register

### P1-1 — The V-10 FEFO refusal escapes the guided path into the SO→Invoice auto-DN converter, aborting a shipped conversion and leaving persistent partial state — CONFIRMED
`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteFromDocumentFactory.php:166`; `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:162`, `:520`; `apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:78-84`

R-9 scopes V-10 to "a typed loud refusal **on the guided path**". The throw is unconditional inside `createDraftFrom`, and that factory has a **second** caller: `SalesOrderToInvoiceConverter::createDeliveryNoteForOrder` (`:520`), invoked at `:162` — **outside** the converter's own `DB::transaction` (`:167`). `createDraftFrom` opens no transaction of its own.

Failure scenario: a confirmed sales order, line 1 non-batch, line 2 a `requires_batch_tracking` product whose FEFO allocation short-falls (ordinary: ordered more than on hand). `POST /api/v1/orders/{id}/convert-to-invoice` now leaves behind, committed:
- a Draft delivery note (`DeliveryNoteFromDocumentFactory.php:74`) with a consumed `document_number` and only line 1 copied;
- source line 1 with `quantity_delivered = quantity` (`:174`) although nothing was ever confirmed or issued;
- and returns 422 `ORDER_NOT_CONFIRMED` (`DocumentConversionController.php:80`) whose `message` is the raw machine string `FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH` — no `__()`, so the house rule "en + fr i18n for every user-facing string" is violated on this arm, and the code is actively misleading.

Before this change the branch logged and degraded to one unbatched line, and the conversion completed. No test covers the converter arm: `grep -rl createDraftFrom tests/` returns only `StandaloneInvoiceGuidedDeliveryTest.php`, which drives the guided route (correctly transactional — `:171-181` asserts 0 DNs and 0 movements). The guided half of R-9 is well built; the leak into the converter is out of scope, untested, and not recorded as a deviation in the report.

### P2-2 — The deptrac ratchet FAILS, and M1 adds a new violation in the hard-**BLOCKER** Domain→Application category; no deptrac run is recorded anywhere — CONFIRMED
`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:74`; house rule `CODEX-DISPATCH…:572` ("deptrac must not regress baseline 111")

I ran the exact CI gate (`ci.yml:178`):

```
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
ModuleDomain on ModuleApplication   35 → 42   BLOCKER (+7)
SharedContracts on ModuleDomain     22 → 29   RATCHET (+7)
SharedDomain on ModuleDomain         0 →  4   RATCHET (+4)
TOTAL                               99 → 117
RESULT: FAIL — architecture boundary regression.
```

Of the 117, exactly one is attributable to this range: `ReturnNoteService must not depend on App\Modules\Inventory\Application\Services\ReturnCostBasisResolver (ModuleDomain on ModuleApplication)` at `:74`. I did **not** establish attribution for the remaining delta (checking out the base would have written to the tree), so treat the +1 as CONFIRMED-M1 and the aggregate FAIL as CONFIRMED-observed. The +1 lands in the category the ratchet hard-fails on rather than merely ratchets, `backend-architecture` is a `needs:` of "All Checks Pass" (`ci.yml:1104`), and the report's verification list (`codex-dpa-wave3-3c-3d-report.md:128-129`) names PHPStan, Pint, `git diff --check` and shell syntax — **deptrac is absent**. The neighbouring `WeightedAverageCostService` violation at `:66` is precedent for the shape, but precedent is not the recorded, run gate the house rules require.

### P2-3 — T11e's own rule ships with no rule test, no fixture, and no mutation proof; the evidence table's T11e row exercises a **different** rule — CONFIRMED
`apps/api/app/PHPStan/Rules/InventoryGlPostingViaBufferOnly.php:1-49`; `apps/api/tests/PHPStan/` (three test classes, none for this rule); `docs/handoff/reviews/wave3-3c-3d/M1-evidence.md:154`; commit `43bc692ff`

T11e is defined as "PHPStan: writers may only enqueue; `postFor*` callable **only** from `flushIfOutermost()`" (`CODEX-DISPATCH…:373-374`) — i.e. `InventoryGlPostingViaBufferOnly`. `grep -rn InventoryGlPostingViaBufferOnly tests/ app/` outside the rule file itself returns **nothing**: no `tests/PHPStan/InventoryGlPostingViaBufferOnlyTest.php`, no positive/decoy fixture. `git show --stat 43bc692ff` shows the "T11e" red mutation edited `InventoryPaymentRepositoryLockDisjointness.php` (the I-2 rule), not this one, so the evidence row at `M1-evidence.md:154` mislabels which artifact was proven.

Failure scenario: this rule is the *only* thing standing between an M2 writer and a direct `$this->postingService->postForExit($ctx)` that bypasses the buffer and posts GL inside the stock loop — precisely the ABBA D-9 exists to remove. Nothing today proves it can emit a diagnostic; a `return [];` slipped into `processNode` (exactly the mutation `43bc692ff` applied to its sibling) would go unnoticed. This is the brief's own named M1 antidote — "an alarm test that cannot detect the alarm's absence" (`CODEX-DISPATCH…:540`) — left unapplied to the more load-bearing of the two rules, after round 1's P3-11 forced it for the lesser one.

### P2-4 — The round-3 voucher trace class has no loud `[PG]` skip; on the sqlite lane it fails for the wrong reason, and M2's all-ten-green gate is unsatisfiable there — CONFIRMED
`apps/api/tests/Feature/Inventory/InventoryGlVoucherLockOrderTraceTest.php:52-107` (setUp: no driver guard), `:157`; `apps/api/tests/Feature/POS/PosReturnScrapWriteOffTest.php:73`, `:441`; `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5053-5055`, `:3521-3523`

Both advisory statements are wrapped in `if (DB::connection()->getDriverName() === 'pgsql')`, so on sqlite (`phpunit.xml:41` pins it) the trace never sees `pg_advisory_xact_lock` and the test dies at `assertNotNull($firstCompanyAdvisory, "…did not reach voucher GL")` (`:157`) rather than at the T16e ordering assertion. The wave fixed exactly this pattern one round ago for `InventoryGlPostingSeamTest` (`:44-48`) and did not carry it to the class it created in the same round; house rule: "`[PG]` tests skip **loudly**, never silently" (`CODEX-DISPATCH…:566-568`).

Failure scenario, two-sided: (a) the ruling requires the reviewer to confirm each red "fails for the right reason" — on the workflow-dispatch full sqlite lane (`ci.yml:299-326`, which enumerates `tests/Feature/*`) all four ruled-red rows fail for a driver reason, contradicting the captured PG evidence; (b) at the M2 gate "all ten pairs GREEN" (ruling §2) can never hold on the sqlite lane, and the brief's stop rule ("D-9's architecture is wrong and 3C STOPS") would fire on a false signal — the identical trap round 1's P1-1 identified.

### P3-5 — The declared regression set covers files, not the touched **suite directory**, and `tests/Unit/Inventory` is red
`docs/sessions/codex-dpa-wave3-3c-3d-report.md:57` ("inventory unit paths: `7 passed (15 assertions)`"); `apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptData.php:73`

The diff modifies `tests/Unit/Inventory/StockMovementDirectionTest.php`, so the standing rule ("the declared regression set must include **every suite directory** the diff touches", `CODEX-DISPATCH…:558-560`) makes the directory the unit. I ran it: `./vendor/bin/phpunit tests/PHPStan tests/Unit/Inventory` → `Tests: 150, Assertions: 409, Errors: 2` — both in `GoodsReceiptDataTest` (`Attempt to read property "name" on null`). Neither `GoodsReceiptData.php` nor its test appears in `git log 26b63f0ff..HEAD`, so this is a base-tree failure, **not** an M1 defect — but it is the exact "advrev I-A blind spot" the rule was adopted for, and it is unrecorded.

### P3-6 — Stale docblock sends the next reviewer to the wrong class for pairs 9/10
`apps/api/tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php:22-23` still says the red arms "live beside their real writers in `PosReturnScrapWriteOffTest` and **`PosCoreReceiptProjectionRefundDispositionStockTest`**". Round 3 removed them from the latter (that was P1-1's fix) into `InventoryGlVoucherLockOrderTraceTest`. `M1-evidence.md:165-169` is correct; the code comment is not.

### P3-7 — The rollback listener eagerly resolves the whole GL object graph on every root rollback
`apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:92-106`. `$this->app->make(InventoryGlPostingBuffer::class)` constructs `InventoryGlPostingService` → `GeneralLedgerService` → its full dependency tree, on **every** `TransactionRolledBack` at level 0 on the default connection, app-wide — including rollbacks in paths that will never touch inventory. Guard with `$this->app->resolved(...)` before `make()`; a construction failure inside an event handler would also mask the original exception.

### P3-8 — `return_cost_basis` accumulation is not idempotent and writes N times per confirm
`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:702-713`. The block appends to `payload['return_cost_basis']` and calls `$returnNote->update(['payload' => …])` once **per line**. A retried/replayed confirm appends a second full set of records; M3's D-g detector reads `source = 'current_cost'` off exactly this array and would double-count. Key by `line_id` instead of appending, and write once after the loop.

### P3-9 — New code replicates R-8's `CurrencyScale`-for-quantities pattern instead of `QuantityScale`
`apps/api/app/Modules/Inventory/Application/Services/ReturnCostBasisResolver.php:28`, `:55`. `QUANTITY_SCALE = 4` + `CurrencyScale::bcformatStrict($returnedQty, 4)` mirrors the shipped `ReturnNoteService` pattern R-8 records as a standing follow-up ("truncate-vs-half-up divergence above 4 dp"). R-8 says record, do not expand — but this is *new* code adopting the deprecated form, so the trigger condition ("must close before any >4 dp product unit ships") now has one more site.

### P3-10 — Round-2 P3-9 is only half closed: `tests/PHPStan` runs in preflight but in **no** CI job
`apps/api/phpunit.xml:20-22`; `.github/workflows/ci.yml:275`, `:299-326`. The new `<testsuite name="PHPStan Rules">` is picked up by preflight's bare `php artisan test` (`scripts/preflight.sh:75`), but `backend-test` runs `--testsuite=Unit`, and the manual full-suite step enumerates `tests/Unit`, `tests/Feature/*`, `tests/Integration`, `tests/Architecture` — never `tests/PHPStan`. The I-2 fixtures still protect nothing in CI.

### P3-11 — Full-tree PHPStan is red (pre-existing, not M1)
`apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:309-310` — 2 × `precision.hardcodedBcmathScale`. Neither the file nor `phpstan-baseline.neon` is in `26b63f0ff..HEAD`, so this is base drift; the house rule only demands touched-file cleanliness, which the report satisfies. Flagged because `backend-analyse` will be red on the M1 PR and the handback should say so rather than let it read as an M1 break.

### P3-12 — Carried: `ReturnCostBasisResolver` does not net units drawn by prior returns
`ReturnCostBasisResolver.php:63-89`. Plan-level (D-24 step 2 has the same gap), correctly deferred; recorded so it is not lost at M3.

---

## Bypasses I tried that FAILED

1. **"Round-2 P1-1 isn't really fixed — the ruled-red rows still sit in the shared PG gate"** — refuted. `ci.yml:629` names `PosCoreReceiptProjectionRefundDispositionStockTest`; pairs 9/10 now live in `InventoryGlVoucherLockOrderTraceTest`, which is absent from the filter, and `PosReturnScrapWriteOffTest` (pairs 7/8) was never in it.
2. **"The voucher-scale change (round-2 P2-3) is still in the tree"** — refuted. `git diff 26b63f0ff..HEAD -- …/GeneralLedgerService.php` contains no hunk in `createVoucherLedgerEntry`; `reverseInventoryWriteOffEntry` also keeps the shipped inline `postEntry` and `$user !== null` condition (`:5012`), with only the idempotency guard added.
3. **"The six per-task mutations edited tests, not production"** — refuted. `git show --stat` on `6ab4cb163`, `43bc692ff`, `2ad32b56f`, `7ffda98ff`, `d0cce02c0`, `005aa9434`: every one edits a production file (buffer, PHPStan rule, `JournalCode`, the migration, the resolver, `InvoiceController`).
4. **"M1 already wires a production writer to the buffer, so silent zero COGS is reachable now"** — refuted. `grep -rn "InventoryGlPostingBuffer\|flushIfOutermost" app/` outside `app/PHPStan` returns only the buffer itself and the provider binding/listener. The seam is inert until M2, which removes several risk classes from this milestone.
5. **"R-15 is not honoured — `bcround` truncates like `bcmul`"** — refuted. `CurrencyScale.php:185-197` adds/subtracts a half-increment at `scale+1` then truncates: round-half-away-from-zero. `InventoryGlPostingSeamTest::test_exit_rounds_once_posts_balanced_and_replays_idempotently` pins `3 × 1.6666666 → 5.000`.
6. **"R-14 violated — 3C added a product lock inside the projection"** — refuted. The diff touches no file under `app/Modules/POS/`.
7. **"`createInventoryMovementEntry`'s `?string $currencyCode = null` breaks rule 19 in a queued/console context"** — refuted as reachable. Every production caller is `InventoryGlPostingService`, which passes the non-nullable `MovementGlContext::$currencyCode` (`:63`, `:95`, `:154`, `:192`); the DTO forbids null. The null default is latent, not live.
8. **"The new blocking partial unique index breaks legitimate write-off retries"** — refuted. Both writers now hold an existence pre-check outside *and* inside the inner `DB::transaction` (`GeneralLedgerService.php:4828-4841`, `:4863-4872`, `:4955-4970`), and the outer path repairs a Draft via `postEntryNow` before returning.
9. **"The leak alarm still cannot detect its own absence"** — refuted. `InventoryGlPostingSeamTest.php:59-61` returns `connectionsToTransact(): []`, giving real root commits so `DB::afterCommit` genuinely fires; round 1 verified removal-sensitivity and nothing in rounds 2–3 altered `registerLeakAlarm` or that test.
10. **"The RN `payload` write corrupts the fiscal seal"** — refuted (carried). `receiveStockBack` runs pre-seal, and the brief's §0b.10 excludes `payload` from the hash — which is what makes `return_cost_basis[]` legal.
11. **"The I-2 rule still trips on string decoys (round-2 P3-11)"** — refuted. `InventoryPaymentRepositoryLockDisjointness.php:44-62` now inspects only the first argument of `table/from/join/leftJoin/rightJoin` calls, and `tests/PHPStan/InventoryPaymentRepositoryLockDisjointnessTest.php` runs a positive plus a disjoint decoy fixture.
12. **"`where('reason', MovementReason::Delivery)` never matches because the enum isn't cast in the binding"** — refuted. Laravel's query builder casts `BackedEnum` bindings, and `ReturnCostBasisResolverTest::test_fifo_drain_uses_a_stable_weighted_average_of_exit_movements` returns `exit_movement` with real movement ids, proving the predicate matches.

## What is solid

The amended-ruling substance is met: the ten-pair suite exists and runs, pairs 1–6 are green with the sensitivity-control caveat now stated plainly in the evidence (`M1-evidence.md:21-26`) instead of being dressed as production traces, pairs 7–10 are cause-specific production reds driven through `ReceiptReturnService::processReturn` and `PosCoreReceiptProjection::apply` with the advisory identified by SQL shape *and* bound company id, the GR `Received` arm is independently production-driven, and the aborting-subtransaction cannot-verify is proven directly against PostgreSQL. All four `MovementGlKind` arms are pinned including count-correction direction; buffer isolation (savepoint / root / next-root / cross-connection) is covered; T15a persists per-line basis end-to-end at the exact movement cost; T16c's "negative branch inapplicable" is recorded with the `applyScrapPair` evidence; rule 19 holds across the seam (explicit non-nullable currency, `bcmul` at `scale+6` → one `bcround`, no floats); V-10's guided arm ships en+fr with a transactional no-residue negative; and six genuine production mutation/revert pairs close the per-task evidence contract.

The blockers are not in the seam. They are a FEFO refusal that escaped its declared path into a shipped converter, and two merge gates — deptrac and T11e's own rule test — that were never run or never written.

VERDICT: CHANGES-REQUIRED
