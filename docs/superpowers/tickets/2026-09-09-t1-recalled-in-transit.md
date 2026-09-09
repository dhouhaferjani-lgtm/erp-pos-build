# T1-4: Recalled stock silently received after dispatch

Status: open · severity: high · owner: transfer lifecycle lane T-2

Symptom: dispatch a batch while sellable, recall it, then POST complete. Actual HTTP 200; the allocated recalled lot lands at the destination without a refusal or quarantine decision. The regression expects 422 and no destination batch stock.

Seam: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:358` calls receive for saved allocations without rechecking recall state. Source allocation happens earlier and cannot protect a later receipt.

Benchmark: T-1 brief case 4: “Odoo: reserved move lines stay; ERPNext: transit stock unaffected”, followed by “Ticket if a recalled lot silently lands at destination.” Keeping physical lot identity is distinct from silently accepting recalled stock into ordinary destination inventory. This ticket does not prescribe deleting or rewriting the original allocation.

Proposed fix: T-2 defines refusal versus explicit quarantine receipt, checks the current batch state under a race-safe lock at destination receipt, and maps the domain outcome to a typed API error. Cover recall racing receipt, multiple lines, and atomic rollback. Deferred because a safe receipt policy and shared batch-state locking/error contract exceed a local ≤20-line patch.

Acceptance: recalled-after-initiate cannot silently complete; no partial destination movements on refusal; lot identity and quantities remain traceable; second company and permitted expiry-only receipt stay independent.

Reproduction: set `T1_RUN_KNOWN_REDS=1`, run `tests/Feature/Inventory/StockTransferEdgeCasesTest.php --filter test_recalled_after_dispatch_must_not_silently_land_at_destination`. Retained as a ticket-linked skip by default.
