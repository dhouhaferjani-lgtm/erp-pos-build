# HANDBACK — Delivery-Note Consolidation Billing Build

## Header

- Base SHA: `60df88a01b52828665caf33809486bdf0a699bbc` (owner-curated re-pin of 2026-08-19).
- Branch: `codex/dn-consolidation-2026-08-12`.
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation`.
- Pre-re-pin blocker record: `codex/dn-consolidation-2026-08-12-pre-repin`.
- No push, merge, or deployment was performed.

## Owner-amended gate

The 2026-08-19 ruling replaces whole-repository-green with both of these conditions for every
remaining milestone:

1. Pint, PHPStan, and tests are green for touched files.
2. The whole-repository failure set is byte-identical to or smaller than pinned base `60df88a01`.

Any new failure still blocks. The base and branch were therefore measured independently with the
same commands; inherited failures are recorded below and were not repaired by this lane.

## M0 — amended re-pin

**Status: DONE**

- Renamed the retired branch to `codex/dn-consolidation-2026-08-12-pre-repin` and recreated the
  dedicated worktree and active branch from the exact owner pin. No fetch-and-repin was performed.
- `git merge-base --is-ancestor 60df88a01b52828665caf33809486bdf0a699bbc HEAD` passed.
- Confirmed the accountant grant on the pin contains `deliveries.view`, `pos.view_receipts`, and
  `pos.view_reports`. The generated permission map contains the receipts-wave grants.
- This lane did not edit `RolesAndPermissionsSeeder.php` or `permissionsMap.generated.ts`.
- Read 3C merge `1e8c0fa03` before resolving the invoice-posting integration.

## M1 — the three P0s

**Status: IN PROGRESS — implementation and amended preflight complete; bridge pending.**

### Replay and integration

The reviewed M1 series was replayed from the prior curated branch as `Phase 2.1.1` through
`Phase 2.1.17`. `git range-diff` reports 15 of 17 commits patch-identical. The two intentional
integration differences preserve work already present on the new pin:

- M1.9 retains the 3C `InventoryGlPostingBuffer` constructor/root-flush seams while adding the DN
  billing-claim service. The claim call remains additive to the outer posting root.
- M1.13 retains the receipts-expanded CI test filter while adding the named DN concurrency test.

A clean Composer install then exposed a test fixture declaring the production billing service's
FQCN and taking precedence in the optimized autoloader. A RED-first autoload-integrity regression
was added; the colliding fixture was removed and its rule test now analyzes the real production
service. This is commit `1437c34d7` (`Phase 2.1.18`).

The differential sweep then found one branch-owned design-system violation in the M1 recovery
picker and one unrecorded route-manifest delta. Commit `be7deae8d` (`Phase 2.1.19`) replaces the raw
checkbox with the repository atom and records only this lane's `Sales` / `invoices.create` route
metadata. The remaining route-manifest drift patch is SHA-256-identical to the pin's drift patch.

### Implementation state

- P0-1: typed billing projections, shared uninvoiced scope, real server-side filtering, exhaustible
  offset pagination, aggregates, and the self-guarding partial index.
- P0-2: independent Sales-module and permission gates on the list/consolidation surfaces, with
  per-layer regressions. The reserved seeder and permission map remain untouched.
- P0-3: migration-only `legacy_unknown` backfill, one public atomic claim entry point, exact-N
  marker and projection finalisation, bounded retry, durable winner attribution, and both DN and SO
  converter integrations.
- Thin `GET /delivery-notes/uninvoiced`: static-route ordering, UUID constraint, authorization, and
  Sales-module gate.
- C5/C8 and OI-8 conditions 1–4: tenant/company/type-scoped lookups, batch-atomic loss, persistent
  attributed refusal, no-artifact copy, and explicit human-confirmed remainder flow.

### Fresh focused verification

- Standalone guided delivery + consolidation + invoice-confirmation: 38 tests, 294 assertions.
- 3C inventory GL composite-root regression under PostgreSQL config: 2 tests, 19 assertions.
- PostgreSQL concurrency suite: 11 tests, 141 assertions.
- Billing-write PHPStan rule: 6 tests, 8 assertions.
- All PHP files changed from the pin: Pint green and PHPStan level 8 green.
- Changed frontend TypeScript: typecheck and scoped ESLint green.
- Design-system audit: 734 acknowledged, 0 new, 0 stale; focused recovery UI: 9 tests green.
- React Doctor against explicit base `60df88a01`: 88/100, 10 changed files scanned, no issues.
- `git diff --check`: green.

### Amended whole-repository comparison

**Status: PASSED at `be7deae8d`.** Independent captures used the exact same commands against the pin
and branch. No branch failure is new or larger:

- Pint, web typecheck, ESLint, TanStack-key audit, quantity audit, POS ESLint-rule tests: green on
  both. The design-system audit is also green on both after the scoped checkbox fix.
- PHPStan: the same two `CopiesDocumentData.php:309-310`
  `precision.hardcodedBcmathScale` findings (C-3/NG-4) on both.
- Backend path sweep: the same two `InventoryGlCompositeRootTest` fixture failures on both under
  default SQLite (`tenants` table absent). The tests pass with `phpunit-pgsql.xml`. The owner-listed
  `CompleteSalesCycleWithReturnTest` red did not reproduce on either side and was not changed.
- Scoped Vitest: branch has only the two owner-listed finance failures. The base capture had three;
  a focused base rerun confirms the two finance failures and the candidate partner test is green.
  The branch set is therefore identical on the named inherited reds and smaller overall.
- Factory route manifest: after recording only M1's route gate and permission, normalized base and
  branch drift patches have the same SHA-256
  `92a1554a51c2090f0550bb41b08ee723d3d9a1981eba3fd837338f650dc78f70`.
- SaleReceipt chokepoint audit: identical inherited `InventoryCountingController.php:135` finding;
  validator passes with six entries on both.

The pinned base also reproduces a generated-types mismatch introduced by the merged 3C lane:
`typescript:transform` adds two `SystemAccountPurpose` cases and `MovementGlKind` to
`packages/shared/types/generated.d.ts`. The branch produces the same diff byte-for-byte; M1 did not
commit that unrelated regeneration.

## Standing findings and deploy obligations

- **F-1 resolved:** all three accountant grants and the merged frontend map are present at the pin.
- **F-3:** the existing DN list/detail route retains the owner-ruled `moduleKey="inventory"`
  residual. This lane did not change it.
- **OI-9:** `legacy_unknown` is a migration-only historical value. M2 owns its neutral en/fr badge.
- **OI-12:** staging legacy-survey counts remain parent-owned; promotion must capture the migration
  log and must not silently reconcile dirty fiscal-adjacent data.
- **OI-13:** the live-tenant Sales-module assignment remains a parent promotion gate.
- **D-6:** M1 migrations, merged grants, reseed, and permission-cache reset must precede dependent
  frontend promotion.
- Research 17 conditions 5–7 remain proposed and unratified. Only independently specified C9 is in
  scope; no durable losing-claim trace or automatic client retry may be added.
