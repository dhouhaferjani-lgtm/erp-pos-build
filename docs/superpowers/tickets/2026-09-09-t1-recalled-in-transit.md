# T1-4: Recalled stock silently received after dispatch

Status: open · severity: high · owner: transfer lifecycle lane T-2

Symptom: dispatch a batch while sellable, recall it, then POST complete. Actual HTTP 200; the allocated recalled lot lands at the destination without a refusal or quarantine decision. The regression expects 422 and no destination batch stock.

Seam: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:358` calls receive for saved allocations without rechecking recall state. Source allocation happens earlier and cannot protect a later receipt.

Benchmark: T-1 brief case 4: “Odoo: reserved move lines stay; ERPNext: transit stock unaffected”, followed by “Ticket if a recalled lot silently lands at destination.” Keeping physical lot identity is distinct from silently accepting recalled stock into ordinary destination inventory. This ticket does not prescribe deleting or rewriting the original allocation.

Proposed fix: T-2 defines refusal versus explicit quarantine receipt, checks the current batch state under a race-safe lock at destination receipt, and maps the domain outcome to a typed API error. Cover recall racing receipt, multiple lines, and atomic rollback. Deferred because a safe receipt policy and shared batch-state locking/error contract exceed a local ≤20-line patch.

Acceptance: recalled-after-initiate cannot silently complete; no partial destination movements on refusal; lot identity and quantities remain traceable; second company and permitted expiry-only receipt stay independent.

Reproduction: set `T1_RUN_KNOWN_REDS=1`, run `tests/Feature/Inventory/StockTransferEdgeCasesTest.php --filter test_recalled_after_dispatch_must_not_silently_land_at_destination`. Retained as a ticket-linked skip by default.

Retirement acceptance: remove the `T1_RUN_KNOWN_REDS` skip from `StockTransferEdgeCasesTest::test_recalled_after_dispatch_must_not_silently_land_at_destination` when this ticket lands, run that method on PostgreSQL, and update or retire the current recall and expiry companions `test_recalled_lot_receipt_pins_current_behaviour_until_ticket_t1_4` and `test_expired_lot_receipt_pins_current_behaviour_until_ticket_t1_4` in the same change; if the owner chooses a different policy, encode that approved assertion instead of retaining a permanent skip.

Valuation acceptance: an accepted recalled lot that is later scrapped requires a write-off movement plus GL posting through `InventoryGlPostingBuffer`; quarantine cannot be implemented as only a flag if stock is then written off. Today the transfer path itself books no GL, including freight (see `2026-09-09-t1-freight-capitalization-no-gl.md`). T-2 must define and test this valuation arm alongside the refusal/quarantine ruling.
