# Codex DPA Wave 3C/3D execution report

## Run identity

- Dispatch: `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md`
- Harness: `docs/handoff/SELF-REVIEW-HARNESS.md`
- Base SHA: `26b63f0ff29be6353015ca1cd2c5b362aa6bfc18`
- 3C branch: `codex/dpa-wave3-3c`
- PostgreSQL: local port 5432; tests by path only

## M0 — preflight

Files touched:

- `scripts/wave3-citation-inventory.php`
- `docs/handoff/reviews/wave3-3c-3d/M0-citation-inventory.csv`
- `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`
- `docs/handoff/progress/wave3-3c-3d.progress.yaml`
- this report

Evidence and actual outputs are recorded in `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`. The run started with `HEAD == BASE_SHA`; after adversarial fix round 1, `N_extracted=256`, `N_mapped=256`, `unresolved=0`, including 13 extensionless citations and zero file-scope fallbacks. The regression test's red state was `Missing extensionless citation: SalesOrderToInvoiceConverter:334`; its green state is `wave3 citation inventory regression: PASS (256 rows)`. R-11's local result is `0` on a zero-denominator sample and is not treated as deploy evidence; both Workshop tickets are present; D-19 reconciles to 18 rows; GR movements use `Document` / purchase-order id and receipt-line identity is carried by unique `movement_id` / `free_movement_id` links.

Decision: POS refund re-entry will use the original POS sale movement cost at the same product grain (R-1 option a).

Fix-round revert/replay: revert `3fabdaa34` reduced the inventory to 243 citations and the covering check exited 1 for the missing `SalesOrderToInvoiceConverter:334` citation; restore `d2b5765e0` returned the regression to 256 passing rows.

Deviation discovered and resolved in the execution model: `RefundService` currently calls `ReturnNoteService::confirmWithin()` at transaction depth 1, while D-28 states depth 2. M2 will add the implied inner savepoint at that call before the writer-tail flush and retain C-2's root-tail flush. This aligns runtime depth with the settled architecture without changing the domain transition or lock set.
