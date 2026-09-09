# T1-10: Transfer idempotency key silently accepts a different payload

Status: open · severity: high · owner: transfer initiate/idempotency seam

Symptom: create quantity 4.0000 with key T1-retry, replay unchanged, then replay quantity 5.0000 with the same key. The changed request returns 201 and the old transfer instead of a conflict/refusal. An operator can believe changed demand was dispatched. The existing transfer is replayed and there is no second stock decrement; this is payload/intent mismatch, not duplicate stock movement.

Seam: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:111` returns any matching key without comparing payload; the unique-collision recovery at `:190` has the same omission.

Benchmark: T-1 brief case 10: “Idempotency key reuse with a different payload: refused, not silently replayed.” Parent §1 identifies initiate idempotency as the existing guarantee.

Proposed fix: canonicalize and persist/compare the initiate request identity (source, destination, line product/variant/batch grain, decimal quantity, freight and other semantic fields), including both normal replay and concurrent collision recovery. Map mismatch to a typed 422. Define decimal equivalence and ordering explicitly. This durable identity contract and both recovery paths exceed the ≤20-line allowance.

Acceptance: identical replay is one transfer/one stock debit; changed quantity/source/destination/variant/allocations/cost refuses without additional stock mutation; equivalent decimal representations replay; second-company keys stay scoped; committed-winner concurrency tests remain green.

Reproduction: `T1_RUN_KNOWN_REDS=1` with `tests/Feature/Inventory/StockTransferEdgeCasesTest.php --filter test_idempotency_key_replays_identical_payload_but_refuses_changed_quantity`. Default ticket-linked skip.

Retirement acceptance: remove the `T1_RUN_KNOWN_REDS` skip from `StockTransferEdgeCasesTest::test_idempotency_key_replays_identical_payload_but_refuses_changed_quantity` when this ticket lands, run that method on PostgreSQL, and update or retire the identical-replay and changed-payload assertions in the same change; if the owner chooses a different policy, encode that approved assertion instead of retaining a permanent skip.
