# M1 adversarial merge-gate review — round 5

**Range reviewed:** `26b63f0ff..HEAD` (64 commits; round-4 remediation = `b87d528e0..HEAD`) · **Lenses:** inventory-costing **and** fiscal-pos — both apply · **Authority applied:** `ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md` (amended M1 exit: ten-pair suite exists and runs; pairs 1–6 GREEN; pairs 7–10 RED with captured evidence, each failing *for the right reason*).

Everything below was executed by me against live PostgreSQL 5432 / `autoerp_test`, not read from the report.

## Round-4 blockers — all closed, verified against code and runs

| R4 finding | Status | My verification |
|---|---|---|
| **P1-1** V-10 FEFO refusal escaped into the SO→invoice converter | **CLOSED** | `DeliveryNoteFromDocumentFactory.php:69,162-179` gates the throw behind `requireCompleteFefoAllocation`; `grep -rn createDraftFrom app/` returns exactly two callers — `InvoiceController.php:972` passes `true`, `SalesOrderToInvoiceConverter.php:520` does not. Legacy fallback restored byte-for-byte (`$dnLineNumber++; copyLine(...)`). HTTP regression `InvoiceDeliveryNoteConfirmationTest:176-195` asserts 201 + one complete Draft DN. Ran: guided + converter suites **20 passed (99 assertions)**. |
| **P2-2** deptrac BLOCKER +1 in Domain→Application, attribution unestablished | **CLOSED — attribution now established** | Resolver + DTO moved to `Inventory\Domain`. I ran the CI gate: `116` vs baseline `99`, still FAIL. I then dumped the JSON report and intersected all 45 violating files against `git diff --name-only 26b63f0ff..HEAD`: **only two hits, both pre-existing** — `GeneralLedgerService.php:53,55,56` (imports not in the range) and `ReturnNoteService.php:67` (the shipped WAC edge). **M1 contributes zero of the 116.** See P3-1. |
| **P2-3** T11e rule had no test / no mutation | **CLOSED** | `tests/PHPStan/InventoryGlPostingViaBufferOnlyTest.php:19-34` with a positive fixture (`DirectInventoryGlPostingCall.php:15`) and an enqueue-only decoy. Mutation `7bfc5ae61` genuinely short-circuits `processNode` for every `postFor*`; `b55614da0` restores. Ran `./vendor/bin/phpunit tests/PHPStan` → **OK (10 tests, 10 assertions)**. `phpstan.neon:38-39` registers both rules; `ci.yml:277-278` and `:320` now run the directory. |
| **P2-4** voucher trace had no loud `[PG]` skip | **CLOSED** | Ran both red classes on the default sqlite lane: `InventoryGlVoucherLockOrderTraceTest` → 2 × `[PG] T11c voucher lock-order traces require PostgreSQL advisory locks`; `PosReturnScrapWriteOffTest` → 9 passed, 2 skipped. No wrong-reason failures. |
| P3-6/7/8/9/10 | CLOSED | Docblock corrected (`InventoryGlLockOrderContentionTest.php:22-23`); `InventoryServiceProvider.php:101-103` adds the `resolved()` guard (pinned by a new negative test); `ReturnNoteService.php:657-666,717-729` keys by `line_id` and writes once; `QuantityScale` adopted; `phpunit.xml:20-22` + `ci.yml` wire `tests/PHPStan`. |

## Amended ruling — exit condition met (independently re-run)

- **Ten-pair suite exists and runs.** `InventoryGlLockOrderContentionTest` → **11 passed (126 assertions)**, incl. the aborting-subtransaction cannot-verify proof (`:85-124`, two live `pg_connect` sessions).
- **Pairs 7–10 RED for the right reason.** Both traces reach `assertNotNull($firstCompanyAdvisory)` **and** `assertNotNull($lastInventoryStatement)` before failing on the ordering assertion — so production voucher GL and stock projection both executed. Reproduced exactly: pairs 7/8 `53 > 79` (`PosReturnScrapWriteOffTest.php:509`, T16d), pairs 9/10 `13 > 34` (`InventoryGlVoucherLockOrderTraceTest.php:163`, T16e). Neither red is a broken test.
- **Seam:** `InventoryGlPostingSeamTest` → **22 passed (91 assertions)** on PG, incl. the one-rounding pin, all four `MovementGlKind` arms, savepoint/root/next-root/cross-connection isolation, the leak alarm on real root commits (`connectionsToTransact(): []`, `:58-61`), and the migration's duplicate refusal.
- **T15a / GR arm:** `ReturnCostBasisResolverTest` + `GoodsReceiptGlPostingOrderTest` → **15 passed (66 assertions)**, incl. `Received`-status GR ordering.
- **Gates:** touched-file PHPStan (18 files) → **[OK] No errors**; Pint → `{"result":"pass"}`; sealed bytes unchanged (DN hash input is `document_number/posted_at/total/currency` only, `DeliveryNoteService.php:143-148`; RN payload write at `ReturnNoteService.php:729` is pre-seal, `fiscal_status` still Draft).
- Rule 19 holds: no float on the seam; `MovementGlContext::$currencyCode` is non-nullable and every `getScale()` call is passed it explicitly; `bcmul` at `scale+6` → one `bcround`.

## Register — round 5 findings (all P3; none blocks)

**P3-1 — `backend-architecture` CI will be RED on the M1 PR (base drift, not M1) — CONFIRMED**
`tools/deptrac-ratchet.php` → `TOTAL 99 → 116, RESULT: FAIL`. `ci.yml:1104` makes it a `needs:` of "All Checks Pass", so the PR cannot go green as-is. Attribution proved above: zero of the 116 is attributable to this range. Remedy is a baseline refresh on `dev`, not an M1 change; the handback must say so explicitly or the merge will read as an M1 break.

**P3-2 — the payload quantity and the stock-movement quantity now use *different* rounding modes in the same block — CONFIRMED, inert today**
`ReturnNoteService.php:707` still feeds `recordReturn` a **truncated** `CurrencyScale::bcformatStrict(...)` quantity while `:717-721` records a **HALF_UP** `QuantityScale::round(...)` in `return_cost_basis`. For `1.00005` these disagree (`1.0000` vs `1.0001`), so M3's D-g detector could see a payload quantity that no movement matches. Unreachable while quantities are `decimal(N,4)` at rest — this is exactly R-8's recorded trigger condition ("must close before any >4 dp product unit ships"), now with the divergence sitting inside one method.

**P3-3 — `postForBatchWriteOff` is the only kind with no `isHistorical` rung, and nothing tests that — PLAUSIBLE**
`InventoryGlPostingService.php:88-101`. `postMovement` (`:118`) and `postForCountCorrection` (`:37`) both refuse historical contexts; the write-off arm does not, and `test_historical_movement_stops_before_logging_or_account_lookup` (`InventoryGlPostingSeamTest.php:453`) only exercises the Exit arm. Defensible (batch write-off GL is shipped and has no pre-cutover watermark) but undocumented: an M2/M3 replay path that enqueues a historical `BatchWriteOff` will post. One docblock line + one test would close it. I could not check this against plan §2.1 — `plan-wave3.md` is not present in this worktree.

**P3-4 — the declared regression set still omits `tests/Feature/Document`, which the diff touches — CONFIRMED (all reds proved base-tree)**
Standing rule at `CODEX-DISPATCH…:558-560`. I ran the four return-note suites on PG: **4 failed, 22 passed** — 3 × `ReturnNoteIntegrationTest` (`chk_fiscal_mandatory_core` check violation on a test-authored SEALED invoice with no `fiscal_hash`) and `CompleteSalesCycleWithReturnTest` (`DeliveryRequiredBeforeInvoiceException`). I then extracted the base tree with `git archive 26b63f0ff` into `/tmp/m1base` and ran the same paths: **identical 4 failures**. Not M1 regressions — but unrecorded, same shape as the `tests/Unit/Inventory` gap round 4 raised (which I re-confirmed: 2 base-tree errors in `GoodsReceiptDataTest`, 140 passed).

**P3-5 — the partial-index migration hard-throws on pre-existing duplicates; the probe was local-only — CONFIRMED**
`2026_08_11_000100_unique_journal_entries_source_inventory_movement.php:22-33`. Two of the five indexed source types (`batch_write_off`, `batch_write_off_reversal`) have shipped production rows. A tenant with one duplicate pair aborts `tenants:migrate` on `origin/dev` auto-deploy and blocks every subsequent tenant. The design is correctly fail-closed and named (better than a raw `23505`), and the probe returned `0` — but only against the local DB. This belongs on the M2/M3 deploy checklist as a per-tenant pre-deploy probe.

**P3-6 — carried: pairs 1–3 are synthetic scratch-table probes, permanently green regardless of production code**
`InventoryGlLockOrderContentionTest.php:30-38,127-154` — the pair name is a data-provider string; `runTerminalOrder` cannot fail on a production change. Correctly and plainly disclosed at `M1-evidence.md:21-26`, and the ruling's M2 gate ("all ten pairs GREEN post-cutover ... must run the post-cutover production writer tests") is the right place to convert them. Recorded so M2 does not accept these as the green half.

**P3-7 — the deptrac fix was a relocation, not a port**
`ReturnCostBasisResolver.php:5-13` now sits in `Inventory\Domain\Services` while importing `Document\Domain\Document`, `DocumentLine`, `DocumentType` and `DeliveredQuantityResolver` directly. Deptrac permits Domain→Domain so the ratchet is satisfied, but Rule 6 ("never import models directly across modules") is no better served than before; a `Shared/Contracts` port was the structural fix. Net posture unchanged; flagged so the next lane does not treat this as precedent.

**P3-8 — round-4's fix commit was not revert-replayed**
`d91d2e4d9` bundles six fixes plus their tests in one commit. The house rule ("revert-replay every fix commit") was honoured only for the T11e rule (`7bfc5ae61`/`b55614da0`). The converter, payload-keying and rollback-listener tests are each demonstrably discriminating by inspection, and the per-task table at `M1-evidence.md:151-158` carries six genuine production mutation/revert pairs, so the evidence contract is substantively met — but the round-4 fixes themselves have no committed red.

**P3-9 — carried, plan-level:** `ReturnCostBasisResolver.php:68-88` does not net units drawn by prior return notes (D-24 step 2 has the same gap). Correctly deferred; recorded for M3.

## Bypasses I tried that FAILED

1. *"The 4 red tests in `tests/Feature/Document` are an M1 regression from the `receiveStockBack` rewrite"* — **refuted.** Reproduced identically on the extracted base tree at `26b63f0ff`.
2. *"deptrac's +17 is partly M1's"* — **refuted** by JSON-report intersection: 45 violating files, 2 in the range, both with pre-existing edges only.
3. *"`absoluteDeltaForRow()` blows up on NULL `quantity_before/after`"* — **refuted.** `2025_11_30_110000_create_inventory_tables.php:39-40` + the scale-4 widening declare both NOT NULL; only `stock_adjustments` has nullable twins.
4. *"The touched-file PHPStan claim is false — I got 2 errors"* — **refuted, my error.** The `apps/api/app/**.php` pathspec matched nothing, so PHPStan fell back to whole-tree `app/`. Re-run against the real 18-file list: **No errors**. The 2 `CopiesDocumentData.php:309-310` findings are whole-tree base drift; that file has no commit in the range.
5. *"The V-10 flag defaults to `false`, so some other guided caller silently keeps the unsafe fallback"* — **refuted.** Exactly two callers exist; C-5 (T25c's guided endpoint) is M3 scope per the brief's milestone map, not an M1 omission.
6. *"`location_id` on DN lines changes what the DN hash covers"* — **refuted.** `DeliveryNoteService.php:143-148` hashes only `document_number/posted_at/total/currency`.
7. *"The `resolved()` guard can skip discarding a buffer that still holds contexts"* — **refuted.** A non-empty buffer implies it was resolved; and after `forgetScopedInstances()` Laravel leaves `$resolved[...]` true while `make()` returns a fresh empty buffer, so the guard is one-directional and safe. Pinned by `test_unrelated_root_rollback_does_not_construct_the_inventory_gl_buffer`.
8. *"The single post-loop `payload` write clobbers something written during the loop"* — **refuted.** Nothing between `:667` and `:729` mutates `$returnNote`'s attributes; `resolveForReturnLine` loads a separate `$line->document` instance and does not write.
9. *"The unconditional `$returnNote->update(['payload' => …])` on a service-only return trips the immutability trigger"* — **refuted.** It runs pre-seal with `OLD.fiscal_status = DRAFT`, which takes `enforce_document_immutability()`'s early return.
10. *"`createInventoryMovementEntry`'s idempotency read is unscoped by company, unlike its sibling"* — **refuted as a failure.** `source_id` is a movement UUID; no cross-company collision is constructible, and the partial unique index is keyed the same way.
11. *"The PHPStan fixtures declare `App\Modules\Inventory\Application\Services\…` and will be picked up by the real analysis"* — **refuted.** `phpstan.neon:6-7` scans `app/` only; `RuleTestCase` analyses the files directly.

## Assessment

The seam is inert until M2 (`grep -rn "InventoryGlPostingBuffer\|flushIfOutermost" app/` outside `app/PHPStan` still returns only the buffer, its binding and the rollback listener), the amended ruling's exit condition is satisfied on real PostgreSQL with each ruled red failing at its named T16d/T16e boundary, T11e finally guards the thing it was written to guard and can prove its own absence, V-10 is narrow and localized with a no-residue negative, and the two gates round 4 flagged as never-run are now run — one clean, the other proved to be 100% base drift. Everything remaining is a note for M2/M3 or a base-tree condition to state in the handback, not a defect in this milestone.

Three items I want carried into the M2 prompt verbatim: pairs 1–3 must become production writer tests (P3-6), the T16d/T16e reds must flip without editing the tests, and the deptrac baseline must be refreshed on `dev` before the M1 PR can go green (P3-1).

VERDICT: ACCEPT
