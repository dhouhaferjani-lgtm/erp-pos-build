# Request-hygiene Phase A — CI reconciliation prep (item 12, 2026-09-07)

Read-only prep for the owner's promotion decision. No push was made. Both whole-suite runs were dispatched by the testing session on 2026-09-05 on throwaway branches.

| Run | Branch | SHA | Conclusion | Failing jobs |
|---|---|---|---|---|
| 33975221815 (baseline) | `ci/origin-dev-baseline-2026-09-05` | `4d5b8812e` = origin/dev tip | failure | 13 jobs: Pint, Deptrac ratchet, PG-only invariants, PHPStan, §14.3 chokepoint gate, PHPUnit, POS Vitest, Route manifest drift, Treasury spine PG, ESLint, Generated types drift, Vitest, All Checks |
| 33972668125 (candidate) | `ci/rh-phase-a-2026-09-05-b` | `189fe7d8a` = local dev tip on 2026-09-05 | failure | 5 jobs: PHPUnit, ESLint, Vitest, Treasury spine PG, PG-only invariants (7 self-hosted feature lanes skipped) |

**Net:** the candidate fixes 8 job families that are red on origin/dev today (Pint, Deptrac, PHPStan, chokepoint gate, route manifest, generated types, POS vitest, All-Checks wiring) and keeps 5 red.

## PHPUnit delta (candidate vs baseline), from `--log-failed`

- Baseline: 56 distinct failing/erroring test methods. Candidate: 57. **Fixed by the candidate: 5. New in the candidate: 6** (4 classes):
  - `Tests\Feature\Fiscal\TreasuryAccountChargeBridgeTest` — 2 methods (`bridge res…`, `treasury b…`) — account-charge arm; K-1 territory (PosCoreReceiptProjection account-charge + TreasuryAccountChargeBridge).
  - `Tests\Unit\POS\ReceiptReturnServiceTest` — 2 methods (`full return after parti…`, `partial return restores…`).
  - `Tests\Feature\Uom\UnitsInvariantTest` — 1 method (`vis…`).
  - `Tests\Unit\Modules\Catalog\Media\MediaUrlResolverTest` — 1 method (`for attac…`).
- PG-only lanes (both runs red): `TaskPhase3AccountChargeFullFlowTest` (5), `RefundCompensationControllerTest` (2), `PosCoreReceiptProjectionRefundDispositionSto…`, `Accounting\Reports\Upc…` (ClosedFiscalPeriodException), `TreasuryAccountChargeBridgeTest` (3 PG methods).
- Vitest: 3 failed files / 740 passed (file names not extracted from the ANSI log yet). ESLint: red in both runs (detail not extracted yet).

Raw logs saved in the session scratchpad (`ci-rh.log`, `ci-base.log`, `ci-*-fails.txt`); regenerate with `gh run view <id> --log-failed`.

## What promotion needs (owner)

1. Triage the 6 new PHPUnit failures (are they caused by the 2026-09-05 merges — #208/#209/#211–#216, devreds-2 — or by the base?) — one small lane, then re-run the candidate on a fresh throwaway branch at the current local dev tip.
2. Extract and triage the 3 vitest files and the ESLint red (agent task, reads the saved logs).
3. The 56 pre-existing reds stay a separate ledger (origin/dev baseline); promotion cannot wait on them but the owner must accept that staging inherits them.
4. Then: `git fetch origin dev`, fast-forward check, batched push (auto-deploys staging incl. migrations — every migration since `4d5b8812e` must be self-guarding; list them before pushing), web deploy per manifest §3, `SYNC_PERMISSIONS_ON_BOOT` / seeder + `permission:cache-reset` for the new permissions (#210's `supplier-invoices.manage` among them).
