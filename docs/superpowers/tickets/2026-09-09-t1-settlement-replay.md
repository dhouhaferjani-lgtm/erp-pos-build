# T1-11: Replaying transfer initiation settles newer demand again

Status: open · severity: high · owner: replenishment settlement

Symptom: request 2.0000, create its transfer (still in transit), capture a new request 3.0000 and bump it by 1.0000. Ordinary capture correctly leaves the original fulfilled and the new request pending. Replaying the same StockTransferInitiated event changes the newer 4.0000 request to fulfilled against the already-used 2.0000 transfer.

Seam: `apps/api/app/Modules/Replenishment/Application/Listeners/SettleRequestsOnTransferInitiated.php:57` selects all currently open matching grains; `:68` assigns the old transfer without a durable event/request settlement identity or demand-time boundary.

Benchmark: T-1 brief case 11: “settlement must not double-settle nor reopen”; parent §1 says settlement occurs on StockTransferInitiated. A second capture must represent new demand, not consume the same shipment again.

Proposed fix: persist a settlement identity/snapshot per transfer line and request, with replay and cancellation/reopen semantics. Preserve legitimate first-time settlement, protect event ordering, and avoid swallowing a partially failed listener as successful replay. Durable storage and reconciliation exceed ≤20 lines.

Acceptance: delivery of the same event twice never fulfills requests captured after its original settlement; original stays linked once; new quantity/count remain unchanged; cancel merge still works on PG; tenant/company/destination/variant grains remain isolated.

Reproduction: `T1_RUN_KNOWN_REDS=1` with `tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php --filter test_replayed_initiated_event_must_not_settle_demand_captured_after_dispatch`. Default ticket-linked skip. Ordinary bump case remains live and green.
