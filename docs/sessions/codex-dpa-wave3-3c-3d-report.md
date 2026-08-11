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

Evidence and actual outputs are recorded in `docs/handoff/reviews/wave3-3c-3d/M0-evidence.md`. Key results: `HEAD == BASE_SHA`; `N_extracted=243`, `N_mapped=243`, `unresolved=0`; R-11 total `0` across 14 tenant databases; both Workshop tickets present; D-19 reconciles to 18 rows; GR movements use `Document` / purchase-order id.

Decision: POS refund re-entry will use the original POS sale movement cost at the same product grain (R-1 option a).

Deviation discovered and resolved in the execution model: `RefundService` currently calls `ReturnNoteService::confirmWithin()` at transaction depth 1, while D-28 states depth 2. M2 will add the implied inner savepoint at that call before the writer-tail flush and retain C-2's root-tail flush. This aligns runtime depth with the settled architecture without changing the domain transition or lock set.
