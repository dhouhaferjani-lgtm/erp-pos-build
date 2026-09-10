# T-2 receipt spine — implementation handback

status: review

Original source implementation: `c30dcdf9c`, branch `lane/t2-receipt-spine`, worktree `.worktrees/t2-receipt-spine`.
Original DISPATCH_SHA: `9d6bc75c12d7dd9ed8c4226d0b3e7608754a5f28`.
Amendment DISPATCH_SHA: `de3007fe9` (documentation-only fast-forward; no production drift).
Authority: plan rev 9 §0AA, spec rev 11, the movement-creation ruling, and the 2026-09-10 fix-round-1 prompt. The original implementation evidence below is historical; the Fix round 1 section records the current changes and verification.

**Fix round 1 is not fully cleared: the Arabic completeness gate awaits a scope ruling.** S1 implements setting-off receipts, partial receipt/replay, damage land-then-scrap, terminal write-off/return, freight residuals, reconciliation, legacy backfill and remainder readers. No blind setting, masking, receiver-view route, notification listener, merge, push or deployment. All PostgreSQL legs were serial on exclusive `autoerp_test_u`, with BOTH DB_DATABASE and DB_CENTRAL_DATABASE set. No full PHPUnit or Vitest suite was run. Shared root dev was not edited.

## Verification commands and green results

All logs named below are under `docs/sessions/t2-receipt-spine/` in this worktree (ignored local evidence); the relevant outcomes and failing assertions are preserved here for a fresh checkout.

PostgreSQL reproduction command (port now pinned for T-8). The original runs below omitted DB_PORT and resolved to native PostgreSQL 16 on 5432; fix-round-1 runs explicitly set it. Substitute one listed file at a time:

```sh
DB_DATABASE=autoerp_test_u DB_CENTRAL_DATABASE=autoerp_test_u DB_USERNAME=houssamr DB_HOST=127.0.0.1 DB_PORT=5432 DB_PASSWORD='' apps/api/vendor/bin/phpunit -c apps/api/phpunit-pgsql.xml FILE
```

| Class / file | Result | Evidence log |
|---|---|---|
| `apps/api/tests/Feature/Inventory/StockTransferReceiveTest.php` | OK (4 tests, 24 assertions) | `receive-reconciliation-green.log` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php` | OK (4 tests, 11 assertions) | `damage-gl-refresh.log` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveLotsTest.php` | OK (3 tests, 27 assertions) | `gate-StockTransferReceiveLotsTest.log` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveValidationTest.php` | OK (23 tests, 94 assertions) | `final-StockTransferReceiveValidationTest.log` |
| `apps/api/tests/Feature/Inventory/StockTransferCloseTest.php` | OK (6 tests, 29 assertions) | `gate-StockTransferCloseTest.log` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php` | OK (3 tests, 43 assertions) | `final-StockTransferReceiveConcurrencyPostgresTest.log` |
| `apps/api/tests/Feature/Inventory/TransferReceiptEventStreamTest.php` | OK (3 tests, 38 assertions) | `events-restored-green.log` |
| `apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php` | OK (1 test, 8 assertions) | `gate-TransferReceiptGlBoundaryTest.log` |
| `apps/api/tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php` | OK (2 tests, 10 assertions) | `gate-TransferReceiptPatternQueryPostgresTest.log` |
| `apps/api/tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php` | Tests: 7, Assertions: 39, Skipped: 1. | `gate-TransferLegacyCompletionBackfillTest.log` |
| `apps/api/tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php` | OK (2 tests, 30 assertions) | `sealed-TransferReceiptSecondOfEverythingTest.log` |
| `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php` | OK (5 tests, 15 assertions) | `gate-TransferReceiptAuthorityGateTest.log` |
| `apps/api/tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php` | OK (1 test, 8 assertions) | `verified-TransferCloseReplenishmentSettlementTest.log` |
| `apps/api/tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php` | OK (2 tests, 11 assertions) | `verified-TransferReceiptSchemaRerunPostgresTest.log` |

Additional regression evidence:

- Final completion concurrency: 2 tests / 20 assertions, `verified-StockTransferCompleteConcurrencyPostgresTest.log`.
- Final edge suite: 27 / 115, two preexisting ticket skips (recalled-in-transit and initiate payload idempotency), `sealed-StockTransferEdgeCasesTest.log`.
- Idempotency collision: 2 / 13, `final-StockTransferIdempotencyCollisionPostgresTest.log`.
- SQLite transfer service: 22 / 72, `sqlite-final-InventoryTransferServiceTest.log`.
- SQLite entry/exit endpoint regression: 11 / 76, `sqlite-final-EntryExitNoteEndpointTest.log`.
- SQLite legacy backfill: 7 / 34, capture-only and PostgreSQL interruption cases skipped, `sqlite-backfill-restored-green.log`.
- SQLite reader ratchet: 2 / 5; both production scan and negative liveness fixture pass.
- Both DPA ratchets/guard: 8 / 117, `dpa-final-green.log`, with `DPA_BASELINE_PROTECTED_BLOB=1381983d463e6c546535be907d4aa1c7ca94c597`. Scanner and protected blob unchanged; working baseline shrinks exactly one row.
- Original stock event/V2 regressions before receipt behavior: 14 / 39, `extraction-events-final.log`.
- Scoped PHPStan: all 54 changed production PHP files clean. Pint clean. Generated artifacts regenerated by artisan only. Web `tsc --noEmit` passes.
- Scoped preflight completed across the original run and a targeted continuation. `./scripts/preflight.sh` passed Pint, all 54 production PHP files through PHPStan, 6 scoped PHPUnit cases / 29 assertions, both artisan generation/drift gates, TypeScript, ESLint (0 errors / 6410 warnings), key/design/quantity audits and manifest/liveness/harness tests (10/52). It then caught one new Arabic i18n gap. After adding that one key, the unchanged remainder of preflight was executed from the i18n gate through chokepoint completeness: exit 0 (`preflight-resumed.log`). i18n, web/POS ESLint-rule self-tests, tools, 2 selected web Vitest files / 4 tests, 2 fiscal parity files / 29 tests, and all 11 chokepoints pass. The entire script did not produce a single exit-0 run; all its gates passed across these two stages, with no guard disabled and no baseline raised for the gap. The Laravel artisan test renderer emitted four local file_get_contents warnings; direct PHPUnit runs of those same tests are green. Initial preflight before source commits also correctly stopped at the generated-file drift guard because the generator output was not staged yet.

## Approved movement amendment, prerequisite evidence and census

`markMovementAsTransfer()` is deleted, not moved. Receive/issue validate an optional directional final type plus an exclusive valid transfer UUID, and INSERT the final `transfer_in`/`transfer_out`, `StockTransfer::class`, transfer-id tuple. Existing writer defaults and `StockAdjustmentService::transfer()` remain unchanged. V1/V2 announcements use the persisted final label. No existing event class changed shape.

RED: expected transfer_out + StockTransfer FQCN + transfer UUID, actual issue + null + null (`movement-red.log`, 1/1 failure). Additional override/event cases: 9 tests, 1 label failure and 8 unknown-named-argument errors against the original writers (`amendment-extra-red.log`).

The pure movement amendment was verified before receipt behavior (27/115 edge, 2/20 completion race, 2/13 collision, 22/70 transfer service). For the incremental commit, its staged snapshot was separated from the later receipt changes and verified again against the original completion-refusal test: all four passed with the same counts (`index-amendment-*.log`). Full receipt source was restored byte-for-byte afterward. This distinguishes the extraction regression from S1's later deliberate completion replay.

The helper census was run on the untouched dispatch source. Its exact output is below; the last ReturnNote hit is a different service's method with the same name, not a StockTransferService caller.

```text
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:320:            $transfer = $this->lockTransfer($transferId);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:334:            $productIds = $this->lineProductIds($transfer);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:369:                    $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:382:                $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:397:        // done in bcmath at the working scale (see capitalizeTransferCost).
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:401:            $this->capitalizeTransferCost($transfer, $transferCost);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:432:            $transfer = $this->lockTransfer($transferId);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:448:                $productIds = $this->lineProductIds($transfer);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:465:                                $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:478:                            $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:516:        $transfer = $this->lockTransfer($transferId);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:582:                    $this->markMovementAsTransfer($movement, MovementType::TransferOut, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:595:                $this->markMovementAsTransfer($movement, MovementType::TransferOut, $transfer->id);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:631:    private function capitalizeTransferCost(StockTransfer $transfer, string $transferCost): void
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:635:        $weights = $this->computeAllocationWeights($transfer);
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:714:    private function computeAllocationWeights(StockTransfer $transfer): array
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:734:    private function lockTransfer(string $transferId): StockTransfer
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745:    private function markMovementAsTransfer(StockMovement $movement, MovementType $type, string $transferId): void
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:1056:    private function lineProductIds(StockTransfer $transfer): array
apps/api/tests/Architecture/baselines/document-per-action-baseline.json:20:    "app/Modules/Inventory/Application/Services/StockTransferService.php::App\\Modules\\Inventory\\Application\\Services\\StockTransferService::markMovementAsTransfer::stock_movements::update#1",
apps/api/tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php:306:        $ids = $this->service->lineProductIds($returnNote);
```

Consumer census pinned to `de3007fe9`: ChannelServiceProvider registers the sole V1 listener, DispatchStockChangeToChannels. It reads tenant/company/product/location/newStockLevel and does not branch on movementType; its queued job and DTO omit the label. No V2 listener was found. WeightedAverageCostService, OpeningBalancePostingService, ResetOpeningBalanceService, ReturnScrapWriteOffService and StockAdjustmentService are emitters. Compliance's subscriber does not subscribe to these events. Therefore zero label-sensitive consumer output regressions were required; writer announcement regressions were added.

Final exactly-two-hit delegate census (`rg -n 'receiveAllRemaining' apps/api/app apps/api/tests`):

```text
apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:86:    public function receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult
apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:313:            ->receiveAllRemaining($transferId, $userId, 'sys:complete:'.$transferId)
```

The caller signature is `StockTransferService::complete(string $transferId, string $userId): StockTransfer`; it forwards that id, never a loaded model. Support exposes only lockTransfer, computeAllocationWeights, capitalizeTransferCost and restockAtSource, plus its constructor.

## Pre-change reader baseline

Both captures ran before ANY production edit, using an explicit file argument in addition to the dispatch's filter, to avoid loading the full suite. Output was returned by the command runner; the following is transcribed from those outputs, not reconstructed from an unrun command.

```
cd apps/api
T2T3_CAPTURE_BASELINE=1 DB_DATABASE=autoerp_test_u DB_CENTRAL_DATABASE=autoerp_test_u DB_USERNAME=houssamr DB_HOST=127.0.0.1 DB_PORT=5432 DB_PASSWORD='' ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php --filter test_capture_the_pre_change_reader_baseline
Time: 00:08.720, Memory: 151.00 MB
OK (1 test, 3 assertions)
exit 0

T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php --filter test_capture_the_pre_change_reader_baseline
Time: 00:04.063, Memory: 151.00 MB
OK (1 test, 3 assertions)
exit 0
```

The 11,176-byte fixture is committed in `b41183f50` (`Phase 2.3.0: Capture pre-change transfer reader baseline`). It contains pgsql and sqlite driver keys, four reader query sites, two companies and two locations, and completed/cancelled/in-transit transfers with and without allocations. The committed fixture remains byte-identical to the pre-change capture. Its preservation method first failed on undefined CARRYING_STATUSES. Preservation passes on PostgreSQL and SQLite; C1 was falsified and the original bytes restored. Temporary mutant capture output was retained separately and never replaces the committed pre-change baseline.


Fixture SHA-256: `1691fb4333b4209c3cfd0304df340cd62297f52f3f35fac0e6eed52f33dfbe12`.

S1-C1: changed the literal L2 UUID `00000005-0000-4000-8000-000000000001` to `99999995-0000-4000-8000-000000000001`, captured the mutant separately, restored the original fixture bytes and ran preservation. It failed on the site maps keyed by different location ids (`control-c1-falsification.log`, 1 test / 6 assertions / 1 failure). Restored the UUID and original fixture; full SQLite preservation/backfill passes. No replacement baseline was committed.

S1-C2: queued the three event objects temporarily and moved persistence after `DB::transaction(...)` returned. The seeded post-persist failure left one committed event: `Failed asserting that 1 is identical to 0.` (`control-c2-falsification.log`, 1/1 failure). The exact production source was restored; the whole event suite then passed 3/38 (`events-restored-green.log`).

## Every plan method: RED-FIRST / CONTROL evidence

The table records the actual captured failure, including earlier schema/class failures when those were the first reachable assertion. No fixture setup error is substituted for feature evidence: the two-lot FEFO fixture was corrected and its 404-versus-422 failure recaptured. Each class's GREEN command/file is in the table above. Data-provider cases are grouped under their method and execute individually in PHPUnit.

| Method | Classification | Captured first failure / control | Evidence |
|---|---|---|---|
| `StockTransferReceiveTest::test_partial_receipt_leaves_remainder_in_transit_and_completes_on_the_second_receipt` | RED-FIRST | Failed asserting that two strings are identical. | `receipt-red.log` |
| `StockTransferReceiveTest::test_over_receipt_is_refused_with_zero_movements` | RED-FIRST | Expected response status code [422] but received 404. | `receipt-red.log` |
| `StockTransferReceiveTest::test_sub_unit_quantities_round_trip_as_four_decimal_strings` | RED-FIRST | Failed asserting that null is identical to '0.0001'. | `receipt-red.log` |
| `StockTransferReceiveDamageTest::test_damaged_units_land_then_scrap_with_one_shrinkage_journal` | RED-FIRST | Expected response status code [201] but received 404. | `red-StockTransferReceiveDamageTest.log` |
| `StockTransferReceiveDamageTest::test_declared_reason_never_changes_the_movement_reason` | RED-FIRST | Expected response status code [201] but received 404. | `red-StockTransferReceiveDamageTest.log` |
| `StockTransferReceiveDamageTest::test_periodic_valuation_company_is_refused_before_any_movement` | RED-FIRST | Expected response status code [422] but received 404. | `red-StockTransferReceiveDamageTest.log` |
| `StockTransferReceiveDamageTest::test_the_posted_event_carries_the_discrepancy_line_count` | RED-FIRST | The expected [App\Modules\Inventory\Domain\Events\StockTransferReceiptPosted] event was not dispatched. | `red-StockTransferReceiveDamageTest.log` |
| `StockTransferCloseTest::test_close_write_off_posts_one_shrinkage_leg_and_persists_the_freight_residual` | RED-FIRST | Failed asserting that null is identical to '70.0000'. | `red-final-StockTransferCloseTest.log` |
| `StockTransferCloseTest::test_two_line_worked_example_persists_exactly` | RED-FIRST | Expected response status code [201] but received 404. | `red-final-StockTransferCloseTest.log` |
| `StockTransferCloseTest::test_close_return_to_source_restocks_the_source_lot_exactly_and_posts_no_journal` | RED-FIRST | Expected response status code [201] but received 404. | `red-final-StockTransferCloseTest.log` |
| `StockTransferReceiveValidationTest::test_every_schema_mirror_rule_refuses_before_any_write` | RED-FIRST | Illuminate\Database\QueryException: SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "stock_transfer_receipts" does not exist | `red-final-StockTransferReceiveValidationTest.log` |
| `StockTransferReceiveValidationTest::test_reserved_sys_namespace_is_refused_and_the_server_key_still_works` | RED-FIRST | Expected response status code [422] but received 404. | `red-final-StockTransferReceiveValidationTest.log` |
| `StockTransferReceiveValidationTest::test_no_error_body_carries_a_fixture_quantity` | RED-FIRST | Expected response status code [422] but received 404. | `red-final-StockTransferReceiveValidationTest.log` |
| `StockTransferReceiveLotsTest::test_lot_rules_and_per_lot_remainder` | RED-FIRST | Expected response status code [422] but received 404. | `red-final-StockTransferReceiveLotsTest.log` |
| `StockTransferReceiveConcurrencyPostgresTest::test_two_parallel_receipts_of_eight_of_twelve_yield_one_201_and_one_over_receipt` | RED-FIRST | PHP Fatal error:  Uncaught ReflectionException: Class "App\Modules\Inventory\Application\Services\StockTransferReceiptService" does not exist in /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/vendor/laravel/framework/src/Illuminate/Container/Container.php:1122 | `red-StockTransferReceiveConcurrencyPostgresTest.log` |
| `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_close_serialise_in_both_forced_orderings` | RED-FIRST | PHP Fatal error:  Uncaught ReflectionException: Class "App\Modules\Inventory\Application\Services\StockTransferReceiptService" does not exist in /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/vendor/laravel/framework/src/Illuminate/Container/Container.php:1122 | `red-StockTransferReceiveConcurrencyPostgresTest.log` |
| `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_complete_serialise_and_capitalise_freight_once` | RED-FIRST | PHP Fatal error:  Uncaught ReflectionException: Class "App\Modules\Inventory\Application\Services\StockTransferReceiptService" does not exist in /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/vendor/laravel/framework/src/Illuminate/Container/Container.php:1122 | `red-StockTransferReceiveConcurrencyPostgresTest.log` |
| `TransferLegacyCompletionBackfillTest::test_every_completed_transfer_gets_one_legacy_receipt_and_rerun_adds_nothing` | RED-FIRST | ErrorException: require(/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php): Failed to open stream: No such file or directory | `red-final-TransferLegacyCompletionBackfillTest.log` |
| `TransferLegacyCompletionBackfillTest::test_lot_grain_follows_the_shipment_not_the_current_product_flag` | RED-FIRST | Error: Class "App\Modules\Inventory\Domain\StockTransferReceiptLine" not found | `red-final-TransferLegacyCompletionBackfillTest.log` |
| `TransferLegacyCompletionBackfillTest::test_interrupted_backfill_rolls_back_and_the_next_run_completes` | RED-FIRST | Illuminate\Database\QueryException: SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "stock_transfer_receipts" does not exist | `red-final-TransferLegacyCompletionBackfillTest.log` |
| `TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline` | CONTROL S1-C1 | PASS at untouched capture; changed UUID breaks preservation | `control-c1-falsification.log` |
| `TransferLegacyCompletionBackfillTest::test_historical_reader_answers_survive_the_backfill_and_the_reader_switch` | RED-FIRST | Error: Undefined constant App\Modules\Inventory\Domain\StockTransfer::CARRYING_STATUSES | `red-final-TransferLegacyCompletionBackfillTest.log` |
| `TransferInTransitReadersUseRemainderTest::test_the_three_readers_use_remainder_sql_and_carrying_statuses` | RED-FIRST | LocationStockQueryService must use carrying statuses and remainder quantities. | `red-reader-ratchet.log` |
| `TransferInTransitReadersUseRemainderTest::test_the_detector_rejects_the_liveness_fixture` | RED-FIRST | The real reader must be migrated before the negative control is meaningful. | `red-reader-ratchet.log` |
| `TransferReceiptGlBoundaryTest::test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm` | RED-FIRST | Expected response status code [201] but received 404. | `red-TransferReceiptGlBoundaryTest.log` |
| `TransferCloseReplenishmentSettlementTest::test_settled_request_stays_fulfilled_after_both_close_dispositions` | RED-FIRST | Expected response status code [201] but received 404. | `red-TransferCloseReplenishmentSettlementTest.log` |
| `TransferReceiptEventStreamTest::test_header_and_line_events_share_the_receipt_stream_with_ordered_versions` | RED-FIRST | Failed asserting that two arrays are identical. | `red-final-TransferReceiptEventStreamTest.log` |
| `TransferReceiptEventStreamTest::test_replay_reproduces_every_row_and_leaves_legacy_receipts_untouched` | RED-FIRST | ErrorException: require(/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t2-receipt-spine/apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php): Failed to open stream: No such file or directory | `red-final-TransferReceiptEventStreamTest.log` |
| `TransferReceiptEventStreamTest::test_a_failure_after_persist_leaves_no_stored_event` | CONTROL S1-C2 | PASS on untouched tree; out-of-transaction persistence leaves 1 event instead of 0 | `control-c2-falsification.log` |
| `TransferReceiptPatternQueryPostgresTest::test_line_grain_and_both_dispositions_are_counted` | RED-FIRST | Error: Class "App\Modules\Inventory\Application\Services\TransferReconciliationService" not found | `red-TransferReceiptPatternQueryPostgresTest.log` |
| `TransferReceiptPatternQueryPostgresTest::test_repeated_partial_receipts_collapse_to_one_line_per_receiver` | RED-FIRST | Error: Class "App\Modules\Inventory\Application\Services\TransferReconciliationService" not found | `red-TransferReceiptPatternQueryPostgresTest.log` |
| `TransferReceiptSecondOfEverythingTest::test_second_company_second_location_and_rerun_for_every_receipt_writer` | RED-FIRST | Expected response status code [201] but received 404. | `red-TransferReceiptSecondOfEverythingTest.log` |
| `TransferReceiptSchemaRerunPostgresTest::test_the_four_counters_three_tables_and_named_checks_exist_after_the_first_run` | RED-FIRST | Failed asserting that two arrays are identical. | `red-TransferReceiptSchemaRerunPostgresTest.log` |
| `TransferReceiptSchemaRerunPostgresTest::test_clean_rerun_adds_no_migration_row_and_the_catalog_is_identical` | RED-FIRST | Failed asserting that 2 is identical to 5. | `red-TransferReceiptSchemaRerunPostgresTest.log` |
| `TransferReceiptAuthorityGateTest::test_complete_only_receiver_reaches_transfer_list_and_show` | RED-FIRST | Expected response status code [200] but received 403. | `red-TransferReceiptAuthorityGateTest.log` |
| `TransferReceiptAuthorityGateTest::test_close_requires_both_reconcile_and_close_permissions` | RED-FIRST | Expected response status code [403] but received 404. | `red-TransferReceiptAuthorityGateTest.log` |
| `TransferReceiptAuthorityGateTest::test_view_only_actor_cannot_post_a_receipt` | RED-FIRST | Expected response status code [403] but received 404. | `red-TransferReceiptAuthorityGateTest.log` |

Supplemental review regressions also recorded: company precision expected 399.9999 vs 399.9996; zero-value fallback expected 10/0 vs 5/5; tiny pool expected last-line 0.0001 vs 0 (`costing-extra-valid-red.log`, 3/3 failures, then Close 6/29 green). Modern backfill rerun first hit the received-within-sent CHECK after attempting to overwrite damage with received (`backfill-modern-red.log`); now passes. Reconciliation first expected −7.0000 and observed 0.0000 (`reconciliation-variance-red.log`); now Receive 4/24 green. Generated actor-shape probe first found Array<any>, then verified all four concrete shapes after artisan generation. Additional authority denial and second-company data snapshots are reviewer-driven regression assertions, separate from the original plan capture.

## Manifest and CI wiring

Exact ceilings: Inventory 129→141, Replenishment 8→9, Migrations 14→15, gated_ceiling 1253→1267. Fourteen actual classes appended to backend-test-pgsql in the same test commit as the raise. Three group notes and the global note contain deliberate raise, parked-gate consequence, live-PR/main execution caveat (not push→dev), eventual removal/lowering and local evidence; no observed CI run is claimed.

Unraised checker failure:

```text
tests/Feature lane manifest — FAILED
  ✗ PARKED-LANE COVERAGE GREW: group "Inventory" now holds 130 class(es), ceiling is 129. Its lane "feature-lane-inventory/Inventory" is wired but parked behind an unflipped execution gate, so a new class here still runs nowhere — the ceiling stays enforced until the gate is flipped.
  ✗ GATED-LANE COVERAGE GREW: 1254 class(es) now sit in lanes parked behind an unflipped execution gate, ceiling is 1253. Lower the ceiling when a gate is flipped or a class leaves; raising it is a deliberate edit.
```

Final checker output (`php apps/api/tools/feature-lane-manifest-check.php`, exit 0):

```text
tests/Feature lane manifest OK — 1532 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched against 1944 test classes across all suites.
  ⚠ PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1267 class(es) are laned but not yet running —
    their job(s) are guarded by a repository-variable flag that is off. Flipping it is the owner
    ops step in docs/handoff/DESIGN-f2-feature-lane-execution-2026-08-21.md §7.
  ⚠ COVERAGE DEBT: 1 group(s) / 1 class(es) sit in groups that NO CI lane runs as a whole,
    pending the F-2 CI-budget decision. Some are individually named in a --filter allowlist;
    a NEW class in any of these groups is selected by nothing. Ceilings are enforced above.
    See docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md §M2.
```

## Necessary implementation corrections and scope accounting

- Explicit transferId preserves the FQCN linkage without adding a second transfer enum identity, as the approved amendment permits. Unused WAC injection was removed from StockTransferService after extraction; support owns it.
- Backfill uses the real `inventory_batch_movements.movement_id/batch_id` relation; the plan's direct stock_movements.batch_id query failed with undefined-column SQL. Shipment-time allocation grain remains authoritative. Counter repair now selects legacy receipt transfers only.
- Sequence key is `transfer_receipt` rather than the plan's 22-character `stock_transfer_receipt`, which overflowed the existing document_sequences.type varchar(20). Public receipt prefix remains TRR. No unrelated sequence migration.
- Currency precision/eligible residual fixes honor §7.11 and I7. The simple residual fixture uses 10 sent / 5 good / cost 140 for exact residual 70; 5/12 × 120 truncates to 49.9999 under the plan's mandated operation order, so it cannot also assert residual 70.0000. The prescribed two-line worked example is unchanged and passes exactly.
- Movement, receipt and transfer rows are refreshed before their values feed GL/events. New receipt events override occurredAt() so Spatie serializes their constructor timestamp instead of DomainEvent's private current timestamp. Existing classes are untouched.
- LiteralTypeScriptType annotations on the four actor/reason array fields preserve their PHP shapes in generated TypeScript; without those attributes Spatie emitted Array<any>. The 14 DTOs retain their specified fields and wire shapes. Mapper list results use array_values for strict list typing; reconciliation includes lot expiry and uses received-minus-sent variance.
- Existing completion regression expectations changed from refusal to replay only after the movement-only gate passed. Their no-duplicate stock/freight assertions remain; committed-fixture cleanup now removes receipt children before transfer rows. Existing subclass constructors receive the shared support and receipt service.
- Three minimal translation keys in en/fr/ar inventory.json are required by the new canonical receipt reference type. Existing EntryExitNoteEndpointTest first failed on the missing key and then passed; no UI workflow was added. The en/fr paths will also be touched by S4; Arabic was added because the existing i18n completeness gate requires it. All three label additions are in the actual source ledger.
- Test commit precedes production; the untouched baseline commit precedes it. Four migration files are in the schema commit, and the historical preservation/rerun extensions have their own later test commit. The amendment commit was separated from later S1 behavior and verified from the staged snapshot before sealing.

## Reviewer gate

Reviewer gates: see the orchestrator's registers (`docs/superpowers/reviews/2026-09-10-t2-s1-impl-gate-r1-tenancy.md`, `…-r1-inventory.md`, `…-r1-stock-gl.md`). Fix round 1 is submitted for review; this handback does not assign reviewer verdicts.

## Promotion documentation and owed operations

Created `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` byte-for-byte from §7.16. The entire predecessor edit is one added line:

```diff
+- Lane-scoped successor for the 2026-09-09 T-2/T-3 promotion: `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`.
```

REALIGNMENT-LOG lines owed to the orchestrator (not written to the shared root): S1 receipt/reconciliation writer and schema; remainder-reader/WAC denominator switch; legacy completion backfill; approved movement identity/event-label amendment and DPA shrink; exact manifest raise/allowlist; permission rollout and generated contracts; S1 control and reviewer evidence. Include the en/fr locale contention paths and preserve the Arabic key when reconciling S4. T-1 recall, request settlement, historical freight and initiate-payload tickets remain unchanged and are not claimed fixed.

Owed owner/orchestrator operations: review this source slice, merge only after review, perform promotion checklist tenant migration/permission verification when authorized, then later S2–S4 integration and activation. This task ran no deployment or live-tenant migration, and made no observed-CI claim.

## Exact source file / carrying-commit ledger

One row per actual source file relative to ERP root, including amendment and compatibility deltas. The handback itself is the subsequent evidence-only commit and is outside this source ledger. Latest carrying commit is shown; the earlier baseline/test/extraction commits remain in history for incremental review.

| File | Latest carrying commit |
|---|---|
| `.github/workflows/ci.yml` | `dee922d5f` |
| `apps/api/app/Http/Middleware/RequireAnyPermission.php` | `7713b1e62` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineBatchAllocationData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptLineData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptLineLotData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseLineData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseLineLotData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseReceiptData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationLineData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationLotData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationReceiptData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationSummaryData.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php` | `145b15885` |
| `apps/api/app/Modules/Inventory/Application/Services/ReceiptPayloadCanonicalizer.php` | `b6db956d8` |
| `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php` | `145b15885` |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php` | `ede6a990a` |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php` | `ede6a990a` |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` | `ede6a990a` |
| `apps/api/app/Modules/Inventory/Application/Services/TransferPayloadBuilder.php` | `7713b1e62` |
| `apps/api/app/Modules/Inventory/Application/Services/TransferReceiptResult.php` | `b6db956d8` |
| `apps/api/app/Modules/Inventory/Application/Services/TransferReconciliationService.php` | `ede6a990a` |
| `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` | `145b15885` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferActorRole.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferCloseDisposition.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferDiscrepancyReason.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptFailureReason.php` | `5e61060de` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptKind.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptStatus.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Events/StockTransferClosedV1.php` | `38a8456ab` |
| `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceiptLineRecordedV1.php` | `38a8456ab` |
| `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceiptPosted.php` | `38a8456ab` |
| `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceivedV1.php` | `38a8456ab` |
| `apps/api/app/Modules/Inventory/Domain/Exceptions/TransferReceiptFailureException.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | `bf2cd841a` |
| `apps/api/app/Modules/Inventory/Domain/StockTransfer.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/StockTransferReceipt.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/StockTransferReceiptLine.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Domain/StockTransferReceiptLineLot.php` | `b8351a080` |
| `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` | `e6ea6fde7` |
| `apps/api/app/Modules/Inventory/Presentation/Requests/CloseStockTransferRequest.php` | `b6db956d8` |
| `apps/api/app/Modules/Inventory/Presentation/Requests/ReceiveStockTransferRequest.php` | `b6db956d8` |
| `apps/api/app/Modules/Inventory/Presentation/routes.php` | `7713b1e62` |
| `apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php` | `b6db956d8` |
| `apps/api/app/Shared/Domain/QuantityScale.php` | `ede6a990a` |
| `apps/api/database/migrations/tenant/2026_09_09_100000_add_receipt_counters_to_transfer_lines.php` | `74392421f` |
| `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php` | `74392421f` |
| `apps/api/database/migrations/tenant/2026_09_09_100200_add_close_columns_to_stock_transfers.php` | `74392421f` |
| `apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php` | `74392421f` |
| `apps/api/database/seeders/RolesAndPermissionsSeeder.php` | `7713b1e62` |
| `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` | `c3a982ca3` |
| `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php` | `ede6a990a` |
| `apps/api/tests/Architecture/baselines/document-per-action-baseline.json` | `bf2cd841a` |
| `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php` | `5e61060de` |
| `apps/api/tests/Feature/Inventory/StockTransferCloseTest.php` | `376745c92` |
| `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` | `5e61060de` |
| `apps/api/tests/Feature/Inventory/StockTransferEdgeCasesTest.php` | `5e61060de` |
| `apps/api/tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php` | `7713b1e62` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php` | `e6ea6fde7` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php` | `ede6a990a` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveLotsTest.php` | `5e61060de` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveTest.php` | `b6db956d8` |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveValidationTest.php` | `b6db956d8` |
| `apps/api/tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php` | `8b395fbee` |
| `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php` | `e6ea6fde7` |
| `apps/api/tests/Feature/Inventory/TransferReceiptEventStreamTest.php` | `82f362d72` |
| `apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php` | `5e61060de` |
| `apps/api/tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php` | `82f362d72` |
| `apps/api/tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php` | `a17de0dc5` |
| `apps/api/tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php` | `82f362d72` |
| `apps/api/tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php` | `82f362d72` |
| `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` | `b41183f50` |
| `apps/api/tests/Unit/Shared/QuantityScaleFormatForUnitTest.php` | `ede6a990a` |
| `apps/api/tests/feature-lane-manifest.json` | `82f362d72` |
| `apps/web/src/features/stock-transfers/__tests__/StockTransferDetailPage.test.tsx` | `23ea9835a` |
| `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.test.tsx` | `23ea9835a` |
| `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx` | `23ea9835a` |
| `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx` | `23ea9835a` |
| `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx` | `23ea9835a` |
| `apps/web/src/features/stock-transfers/types/index.ts` | `23ea9835a` |
| `apps/web/src/hooks/permissionsMap.generated.ts` | `7713b1e62` |
| `apps/web/src/lib/i18n.ts` | `23ea9835a` |
| `apps/web/src/locales/ar/inventory.json` | `c30dcdf9c` |
| `apps/web/src/locales/ar/stock-transfers.json` | `23ea9835a` |
| `apps/web/src/locales/en/inventory.json` | `b6db956d8` |
| `apps/web/src/locales/en/stock-transfers.json` | `23ea9835a` |
| `apps/web/src/locales/fr/inventory.json` | `b6db956d8` |
| `apps/web/src/locales/fr/stock-transfers.json` | `23ea9835a` |
| `docs/glossary.md` | `ede6a990a` |
| `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` | `a17de0dc5` |
| `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` | `HEAD (fix-round documentation)` |
| `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-9.md` | `HEAD (fix-round documentation)` |
| `packages/shared/types/generated.d.ts` | `5e61060de` |

## Resume recipe

Open `.worktrees/t2-receipt-spine` on `lane/t2-receipt-spine`, read this handback and plan rev 9 §0AA, and inspect `git diff de3007fe9..HEAD`. Do not regenerate the historical baseline. Use the named-file commands above with both PostgreSQL database variables fixed to autoerp_test_u; never run a full PHPUnit/Vitest suite. Reviewer gates: see the orchestrator's registers. Fix-round verification and any unresolved gate are recorded below; this slice remains at status review. No merge or push is authorized here.

## Fix round 1

Authority: rev 9 §0AA and the three orchestrator gate registers. Base: `93b106461`. Fix-round source tip: `ede6a990a`. All references below are one-based lines in the final worktree files; source files are committed before the documentation handback. Status remains `review`; no push, merge, tenant rollout or observed-CI claim.

| Item | Disposition and evidence | Current path:line |
|---|---|---|
| T-1 | Fixed; the comment block precedes the continued command. bash -n and a PATH-stub replay of the complete extracted step both exit 0; actual argv below. | `.github/workflows/ci.yml:1141` |
| T-2 | Fixed; three parent-scoped exclusions, no waiver or ceiling change. PostgreSQL: 3 tests / 137 assertions. | `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:168` |
| T-3 | Fixed; inventory.view alone is refused list/show, and complete alone is refused reconciliation. | `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php:24` |
| T-4 | Disclosed: backend any-of reads still have no corresponding web route guard until S4; FE Inventory module gate is also deferred. | `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md:11` |
| T-5 | Fixed; receive/close/receiveAllRemaining require explicit tenant and company; transact predicates both. The lock helper accepts a scoped identity, validates UUID and predicates both again. Direct foreign-company call throws ModelNotFoundException with unchanged data. Existing complete/cancel/initiate compatibility callers supply their resolved identity; no CompanyContext is introduced into receipt/lock code. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:95`; `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php:41`; `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php:37` |
| T-6 | Intentional for S1: the existing LOCATION_ACCESS_DENIED 403 body retains location_id and caller user_id, shared with complete/cancel. S4 decides 403 details versus 404 hiding. | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:395` |
| T-7 | Removed the ignored 7,016,448-byte SQLite file apps/api/autoerp_test_u after verifying its SQLite format header. Trap: DB_DATABASE alone does not select the driver; all PG legs use phpunit-pgsql.xml and all SQLite legs use :memory:. | `.gitignore:81` |
| T-8 | All fix-round PostgreSQL invocations explicitly set DB_PORT=5432, both database names, host and user. Native PostgreSQL 16, exclusive autoerp_test_u; one file at a time. | `docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md:19` |
| M-1 | Fixed; cancel records transfer_cost as uncapitalized freight. Initiate 10 units with 140.0000 freight, cancel, allocated + residual = 140.0000. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:365`; `apps/api/tests/Feature/Inventory/StockTransferCloseTest.php:22` |
| M-2 | Option (a) implemented: generated TransferStatus alias, seven token-based badges, all status filters and partial completion; en/fr/ar status keys and Arabic registration. Arabic namespace completeness requires the pending scope decision (118 older keys). No receive/close UI added. | `apps/web/src/features/stock-transfers/types/index.ts:2`; `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5`; `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38`; `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15` |
| I-1 | Fixed; MONEY_SCALE names the two four-decimal freight columns. Positive pools/allocations truncate toward zero; uncapitalized freight or the last eligible allocation receives the exact residual. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php:29`; `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:501` |
| I-2 | Recorded S4 decision: join receipt/close lines on transfer_line_id to full transfer line quantity_decimals; receipt DTOs remain scale-4 strings. | `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-9.md:2742` |
| I-3 | Fixed; counts are per site (LocationStockQueryService 2, matrix 1, WAC 1). Negative controls remove either required token at either location site. | `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php:27` |
| I-4 | Fixed; shared decimalPlacesForUnit resolves precision for both QuantityScale formatting and reconciliation metadata, including null/zero/high-precision units. | `apps/api/app/Shared/Domain/QuantityScale.php:65`; `apps/api/app/Modules/Inventory/Application/Services/TransferReconciliationService.php:45` |
| I-5 | Recorded: SQLite remainder subtraction uses binary arithmetic; its green legs are not PostgreSQL numeric-reader proof. Reader rounding remains unchanged. | `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:143` |
| I-6 | InventoryTransferServiceTest is explicitly in the ledger and is in-scope by constructor/replay/transaction-boundary consequence. Its 22 cases retain all stock/freight assertions. | `apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php:74` |
| I-7 | Fixed; lot-less return explicitly tests allocation === null. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:443` |
| I-8 | Fixed; Blind receiving, Receiver view and Visibility version glossary rows marked planned (S2/S3). | `docs/glossary.md:89` |
| I-9 | Fixed; real mixed good/damaged lot receipt preserves products.cost_price and nets BatchStock to 3.0000 good units. | `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php:51` |
| I-10 | Recorded provenance: reviewed source c30dcdf9c; initial handback-only HEAD 93b106461. Fix-round source commits follow those pins. | `docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md:5` |
| G-1 | Fixed; both plain and lot damage assert shrinkage debit/inventory credit at company scale, opposite sides zero, Posted, chained, and JournalEntryPosted dispatched. | `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php:35`; `apps/api/tests/Feature/Inventory/StockTransferReceiveLotsTest.php:87` |
| G-2 | Fixed; missing inventory/shrinkage purposes cause GL_ACCOUNTS_UNMAPPED typed 422 before stock/GL writes. Seven-table snapshot unchanged. Preflight uses the existing purpose resolver for both scrap forms. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:140`; `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php:82`; `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md:12` |
| G-3 | Fixed; nonzero transaction level throws LogicException before receipt work. The nesting test catches inside a committing outer transaction and proves no writes. Legacy fixture transactions removed; completion race now starts its contender while the receipt root holds the header lock. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:97`; `apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php:15`; `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:94` |
| G-4 | Disclosed destination attribution for land-then-scrap close write-offs. | `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md:13` |
| G-5 | Fixed; removed the orphan lineProductIds docblock; collectProductIds keeps its own documentation. | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:806` |
| G-6 | Fixed; handback assigns no reviewer verdict. Reviewer gates: see the orchestrator’s registers. | `docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md:209` |
| G-7 | Disclosed freight-related GL/subledger divergence; existing T-1 ticket unchanged and no compensating GL entry added. | `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md:14` |
| G-8 | Recorded: SQLite omits receipt CHECK constraints and the migration’s PostgreSQL-only foreign-key/index work. SQLite green is not constraint proof; prior named PostgreSQL schema evidence remains historical, not a new run. | `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php:83` |

### Fix-round verification

Logs are in `docs/sessions/t2-receipt-spine/fix-round-1/` (ignored). PostgreSQL: native 16 at 127.0.0.1:5432, exclusive autoerp_test_u, serial named files.

```sh
DB_DATABASE=autoerp_test_u DB_CENTRAL_DATABASE=autoerp_test_u DB_USERNAME=houssamr DB_HOST=127.0.0.1 DB_PORT=5432 DB_PASSWORD='' apps/api/vendor/bin/phpunit -c apps/api/phpunit-pgsql.xml FILE
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_CENTRAL_DATABASE=:memory: DPA_BASELINE_PROTECTED_BLOB=1381983d463e6c546535be907d4aa1c7ca94c597 apps/api/vendor/bin/phpunit -c apps/api/phpunit.xml FILE
```

| Driver | Named class | Result |
|---|---|---|
| pg | `InventoryTransferServiceTest` | OK (22 tests, 72 assertions) |
| pg | `QuantityScaleFormatForUnitTest` | OK (5 tests, 12 assertions) |
| pg | `StockTransferCloseTest` | OK (7 tests, 31 assertions) |
| pg | `StockTransferCompleteConcurrencyPostgresTest` | OK (2 tests, 20 assertions) |
| pg | `StockTransferEdgeCasesTest` | Tests: 27, Assertions: 115, Skipped: 2. |
| pg | `StockTransferReceiveConcurrencyPostgresTest` | OK (3 tests, 43 assertions) |
| pg | `StockTransferReceiveDamageTest` | OK (6 tests, 22 assertions) |
| pg | `StockTransferReceiveLotsTest` | OK (3 tests, 32 assertions) |
| pg | `TenantOnlyUniqueOnCatalogueTablesRatchetTest` | OK (3 tests, 137 assertions) |
| pg | `TransferInTransitReadersUseRemainderTest` | OK (3 tests, 9 assertions) |
| pg | `TransferReceiptAuthorityGateTest` | OK (8 tests, 19 assertions) |
| pg | `TransferReceiptGlBoundaryTest` | OK (2 tests, 16 assertions) |
| sqlite | `DocumentPerActionBaselineRatchetTest` | OK (2 tests, 109 assertions) |
| sqlite | `InventoryTransferServiceTest` | OK (22 tests, 72 assertions) |
| sqlite | `StockTransferCloseTest` | OK (7 tests, 31 assertions) |
| sqlite | `StockTransferEdgeCasesTest` | Tests: 27, Assertions: 115, Skipped: 2. |
| sqlite | `StockTransferReceiveDamageTest` | OK (6 tests, 22 assertions) |
| sqlite | `StockTransferReceiveLotsTest` | OK (3 tests, 32 assertions) |
| sqlite | `StockTransferReceiveTest` | OK (4 tests, 24 assertions) |
| sqlite | `TransferInTransitReadersUseRemainderTest` | OK (3 tests, 9 assertions) |

The PG-configured architecture reader detector and unit-precision test are source/unit checks, not database-behavior proofs. SQLite uses binary arithmetic for SQL remainders and does not install receipt CHECK constraints (I-5/G-8). Two edge-test skips remain the existing ticket-linked recall and initiate-payload-idempotency cases.

### Red evidence and CI argv

- T-5: direct receive under another company reached the writer before scoping; `red-authority.log` failed its ModelNotFound expectation, then 8/19 green.
- M-1: `red-cancel.log` expected 140.0000, received 0.0000; the full close file now passes.
- G-2: `red-damage.log` expected 422, received 201; typed refusal and unchanged seven-table snapshot now pass.
- G-3: `red-nested.log` failed “A nested receipt must be refused.” The guard now throws before work. `red-legacy-nesting.log` exposed two obsolete nested-completion fixtures; the replacement preserves the real lock wait, stock/freight and replay assertions (2/20 green).
- M-2: the three new badge labels were absent (`red-badge.log`). The detail probe was corrected to the existing button label “Confirm receipt” before repeating the old-behavior negative control (`red-detail-corrected-label.log`); it fails without partial completion and passes with it.
- I-4: `red-unit-precision.log` failed because the shared precision resolver did not exist; the matching metadata/formatting test is green (5/12). G-1, T-3 and I-9 are added regression pins for existing correct behavior.

The complete run body was extracted from `.github/workflows/ci.yml` (lines 1048–1178) into `ci-full-step.sh`. `bash -n` and `PATH=<stub-bin>:$PATH bash -e ci-full-step.sh` both exit 0; the stub is the only php on PATH and never runs tests. The receipt-bearing argv line is:

```text
php argv: <artisan> <test> <-c> <phpunit-pgsql.xml> <--filter=/\\(VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest|FiscalEventProjectionsTableTest|FiscalEventQuarantineTableTest|PosReceiptsCanonicalBytesTest|PaymentsOriginColumnsTest|OutboxIngestorTest|AccountStatusChangedServerOnlyTest|Task33FiscalFullFlowVerificationTest|FiscalEventIngestionEndpointTest|PosCoreReceiptProjectionTest|AccountChargeProjectionTest|TreasuryAccountChargeBridgeTest|DocumentAccountChargeFactureBridgeTest|TaskPhase3AccountChargeFullFlowTest|TreasuryReceiptBridgeTest|PaymentOriginWriterInventoryTest|ParseFailureResumeTest|TerminalRegistrySnapshotTest|VerifyEventChainCommandTest|NewSaleServerAuthoringDispositionTest|ChokepointCompletenessTest|ReceiptChainRebuildTest|DeviceLossIncidentTest|VirtualAdminTerminalResolverTest|PaymentAllocationPrecisionTest|PartnerMoneyPrecisionTest|WacSerializationConcurrencyTest|ZReportImmutabilityTest|ZReportProjectionTest|GenerateZReportWithCountsTest|AnalyticsTest|ZReportListTest|ProcurementPolicyCheckConstraintTest|StockRebalanceEndpointTest|StockMovementLocationFilterTest|StockMatrixEndpointTest|StockThresholdTest|GoodsReceiptDestinationTest|PaymentRepositoryLocationTest|PosBridgeLocationAttributionTest|BackfillLocationAttributionTest|LocationReconciliationTest|CashPositionEndpointTest|MaturingInstrumentsTest|UpcomingPaymentsTest|ExpenseAnalyticsTest|ZReportCashRoundingSummaryTest|PosReceiptsCashRoundingCheckTest|PosCoreReceiptProjectionCashRoundingTest|TreasuryReceiptBridgeNettingTest|TreasuryReceiptBridgeRoundingGlTest|SaleReceiptV3PayloadConstraintTest|ConfigureCashRoundingCommandTest|BackfillTolerancePurposesCommandTest|BackfillChartPurposesMigrationTest|CountryPaymentSettingsCashRoundingTest|PosPaymentPolicyEndpointTest|TenantLaunchContractTest|PosCoreReceiptProjectionRefundQuantityCapTest|PosCoreReceiptProjectionRefundQuantityCapMixedLegacyTest|PosReceiptImmutabilityTriggerSealedHashAlgorithmTest|PosCoreReceiptProjectionOriginalLineTrustTest|PosCoreReceiptProjectionConcurrentRedeliveryTest|ReceiptReturnRefactorV3Test|FiscalPayloadConstraintValidatorTest|ApplyFiscalEventProjectionJobNonRetryableTest|RefundCompensationControllerTest|BackfillSealedHashAlgorithmCommandTest|DeadLetteredProjectionsControllerTest|Nf525VerifyChainParityTest|ReceiptHashServiceVerifyLegacyArmV4Test|LegacyCorrectionGuardTest|EnableV4RefundAuthoringCommandTest|DisableV4RefundAuthoringCommandTest|PosCoreReceiptProjectionApprovalEvidenceTest|PosCoreReceiptProjectionRefundDispositionStockTest|PosCoreReceiptProjectionRefundPolicyAlertTest|PosCoreReceiptProjectionTrainingRefundRefusedTest|PosCoreReceiptProjectionVoucherRefundNoRedemptionTest|TerminalResourcePolicyTest|BatchChainE2ETest|SupportAccessPostgresEndToEndTest|VatDataRepositoryTest|Nf525CanonicalZGrandTotalPeriodTotalsTest|ReturnPeriodBackdatingGuardTest|ReturnNoteConfirmSealAndPeriodTest|DeliveredQuantityResolverTest|GuidedCancelFlowTest|GuidedCancelFlowTupleSplitTest|CanCancelReturnDecisionReadModelTest|GuidedCancelFlowMultiLineAndNettingTest|GuidedCancelFlowAuthorizationTest|PosReceiptsIndexMigrationStructureTest|AccountantReceiptPermissionsTest|ReceiptAggregateIntegrityTest|ReceiptAuthorizationTest|ReceiptFilterDateBoundaryTest|ReceiptFilterOptionsTest|ReceiptIndexArchivedTerminalTest|ReceiptIndexEnvelopeTest|ReceiptIndexFiscalStatusFilterTest|ReceiptIndexLocationScopeTest|ReceiptIndexTrainingExclusionTest|ReceiptIndexTypeFilterTest|ReceiptLocationScopeAuthorizationTest|ReceiptPdfPrintAuditTest|ReceiptResourceNoCanonicalBytesRecursiveTest|ReceiptShowRefundLineageTest|ReceiptShowResourceTest|RefundRegisterPaginationTest|RefundReportingFieldsTest|CorrectingEntryEndpointTest|SupplierGoodsReturnNoteTest|SupplierGoodsReturnNotesSchemaTest|TerminalLocationPosEnabledTest|BackfillLocationPosEnabledB3MigrationTest|PosReceiptFrozenStateImmutabilityTriggerTest|TerminalClaimHardeningTest|PosTerminalsIdentityLifecycleConstraintsTest|FiscalPeriodReopenEndpointTest|ExpensePostTest|ResolveLineEntryCodeCostRedactionTest|PurchaseOrderUnpricedLineConfirmTest|ExpensePaidFromRepositoryTest|OpeningCashFloatSeedsRepositoryTest|ProformaOutputTest|ProformaTemplateCensusTest|ProformaResourceTest|CompanyContextMiddlewareTypedErrorsTest|SaleReceiptV5PostRemiseVatBaseTest|PosReceiptV5DiscountVatBaseProjectionTest|FiscalPeriodCloseEndpointTest|BackfillDefaultLocationCodeF1MigrationTest|StagedDeploymentBootTest|AuthoritySchemaUnactivatedStateTest|ResultWorkbookTest|PartiesImportBalancesTest|LiveCountingSchemaTest|StockReservationDefaultBatchTest|AtomicFEFOConsumptionTest|BatchStockServiceVariantTest|BatchesVariantUniqueTest|RepositoryMovementsEndpointTest|PosCoreReceiptProjectionRefundNoDecrementTest|PosCoreReceiptProjectionRefundStockTest|PosCoreReceiptProjectionVariantStockTest|ReceiptReturnRefactorTest|ReceiptStockPolicyTest|BatchTrackedSalesOrderConfirmFefoTest|ImplicitReservationFefoLotTest|RepairPhantomDefaultBatchesCommandTest|PosCoreReceiptProjectionBatchLotTest|NullInventedDefaultLotExpiryMigrationTest|OpeningLotExpiryW41Test|SpreadsheetParserDateCellTest|ProductsRoundTripTest|PartiesRoundTripTest|OpeningBalancesRoundTripTest|CompositeItemsRoundTripTest|ProductImagesZipRoundTripTest|UnitsInvariantTest|UnitsNotSeededRefusalTest|UnitsProvisioningTest|ProductSkuCompanyScopeMigrationTest|VariantSkuCompanyScopeMigrationTest|PartnerVatCompanyScopeMigrationTest|PartnerVatLifetimeScopeTest|ProductSkuCompanyScopeImportTest|VariantIndexScopeTest|ProductUpsertKeyPrecedenceTest|ImportJobCompanyBackfillMigrationTest|ImportCompanyPinTest|ImportModuleEntitlementTest|ImportJobClaimConcurrencyTest|ReapStuckImportsTest|PurgeExpiredImportArtifactsTest|ImportRowOutcomeBackfillTest|ImportRowCodedErrorTest|ImportJsonbCastHydrationTest|UnitResolutionTest|ProductIdentityResolutionTest|DuplicateCensusTest|CoalescingMergeTest|ImportOutcomeAtomicityTest|UnitCatalogQueryTest|FreshTenantCensusInvariantsTest|DayOneCensusCommandTest|TenantOnlyUniqueOnCatalogueTablesRatchetTest|TenantOnlyUniqueRatchetLivenessTest|RepositoryNormalisationTest|StockTransferIdempotencyCollisionPostgresTest|DocumentDueDateGuardTest|CreditNotePaidInvoiceSourceTest|AddAttributeValueEndpointTest|CountingIndexStatusFilterTest|CountingMovementReferenceTest|StockTransferEdgeCasesTest|StockTransferCompleteConcurrencyPostgresTest|ReplenishmentEdgeCasesTest|StockTransferReceiveTest|StockTransferReceiveDamageTest|StockTransferReceiveLotsTest|StockTransferReceiveValidationTest|StockTransferCloseTest|StockTransferReceiveConcurrencyPostgresTest|TransferReceiptEventStreamTest|TransferReceiptGlBoundaryTest|TransferReceiptPatternQueryPostgresTest|TransferLegacyCompletionBackfillTest|TransferReceiptSecondOfEverythingTest|TransferReceiptAuthorityGateTest|TransferCloseReplenishmentSettlementTest|TransferReceiptSchemaRerunPostgresTest)::/>
```

### Scope and commit ledger for this round

- `InventoryTransferServiceTest`, `StockTransferEdgeCasesTest` and the completion-concurrency fixture adapt to the explicit root-transaction contract; their existing stock, WAC, replay and lock-wait assertions remain. They are in-scope by G-3 consequence.
- `QuantityScale::decimalPlacesForUnit` is shared by the formatter and reconciliation because there was no shared integer precision resolver; its null/zero/high-precision contract is tested.
- `packages/shared/types/generated.d.ts` was regenerated by artisan for the new failure enum; no generated file was hand-edited. The frontend alias uses the actual generated name `App.Modules.Inventory.Domain.Enums.TransferStatus`.
- `apps/web/src/lib/i18n.ts` registers the new three-key Arabic namespace overlay; this necessary wiring exposed the completeness gate discussed above.
- Rev 9 is copied from the orchestrator’s shared-root authority into this worktree with only the requested §7.12 I-2 note added. No earlier revision was edited.
- No new test class, feature-lane ceiling or CI alternation entry is added in this round. The fourteen existing receipt class selections and four raise-note statements remain.
- The historical reader fixture is byte-identical to b41183f50; DPA protected blob/scanner and the i18n baseline/pin are unchanged. The old promotion-checklist file still has only its original one-line successor pointer.

```text
dee922d5f Phase 2.3.13: Preserve the PostgreSQL CI filter argument
c3a982ca3 Phase 2.3.14: Classify receipt parent scoped unique indexes
e6ea6fde7 Phase 2.3.15: Enforce receipt company scope and read permissions
376745c92 Phase 2.3.16: Preserve uncapitalized freight on cancellation
5e61060de Phase 2.3.17: Guard receipt GL posting and pin shrinkage journals
23ea9835a Phase 2.3.18: Render all transfer statuses and complete partial receipts
ede6a990a Phase 2.3.19: Tighten receipt precision and regression evidence
```

### Static and frontend verification; remaining gate

- Pint `--test` passed on all 19 touched PHP files. PHPStan level 8 passed on the seven touched production files, following this repository’s `app/` analysis scope. `preflight-scope.txt` records the exact scoped arguments.
- The canonical `./scripts/preflight.sh` was run with named-file scopes: the reader architecture file for PHPUnit and the badge file for Vitest. It passed Pint, PHPStan, 3 PHPUnit cases / 9 assertions, both artisan generation/drift guards, web TypeScript, ESLint (0 errors / 6410 existing warnings), TanStack/design/quantity audits, manifest checker/liveness (76 tests / 431 assertions), and local-harness guards (10 / 52). It then exited 1 at i18n with 118 missing Arabic keys. Gates after i18n were not reached in this run. No successful full preflight is claimed. An earlier attempt correctly stopped at generated-file drift; the artisan-generated failure enum was then included in the GL commit, and both generation guards passed on the next run.
- `(cd apps/api && php tools/feature-lane-manifest-check.php)` exited 0: 1532 Feature classes / 74 groups, uniquely anchored filters against 1944 test classes; 70 groups / 1267 classes remain parked and one class remains coverage debt. No ceiling, raise note, baseline or CI activation was changed in this round.
- `pnpm typecheck` at repository root passed for shared, web and POS after generation. Web `pnpm audit:keys` passed with zero violations; `pnpm audit:design-system` passed with 802 acknowledged / zero new violations.
- Named Vitest invocations ran separately: `StockTransferStatusBadge.test.tsx` 8 passed (all seven statuses plus Arabic registration); `StockTransferDetailPage.test.tsx` 3 passed (including partially received → Confirm receipt); `StockTransferListPage.test.tsx` 2 passed. Scoped ESLint on touched web code exited 0 with one existing route-string warning on an untouched list link.
- React Doctor skill regression check: `npx react-doctor@latest --verbose --diff`, before and after, reports exactly 49/100, 10 errors and 376 warnings (386 findings) against origin/main. Both commands exit 1 for pre-existing repository diagnostics; score and finding counts did not regress. No diagnostics were suppressed, no tool configuration changed, and no unrelated React cleanup was performed.

**Unresolved scope decision (M-2 / i18n):** the pre-existing Arabic resource was a whole-namespace English alias. The permitted three new translations now load correctly, but activating an Arabic bundle exposes 118 old missing keys to the existing completeness ratchet. The user was asked whether to widen the three-key scope or retain it and report the failure; no answer or approval is assumed. The i18n baseline, protected blob and detector remain unchanged.

A concrete draft is available locally at `docs/sessions/t2-receipt-spine/fix-round-1/stock-transfers.ar.proposed.json` (ignored, not applied to the app). It covers all 121 English source keys plus five required Arabic plural variants; key coverage and interpolation names were verified. If approved, apply that draft to `apps/web/src/locales/ar/stock-transfers.json`, run the i18n gate and named web checks, then finish the remaining preflight gates and refresh this handback. Without that scope ruling, this is not a fully green handback.

Orchestrator review remains owed, including the added frontend-conventions reviewer for rev 9 option (a). Reviewer gates: see the orchestrator’s registers. No reviewer verdict is assigned here. No merge or push was performed.
