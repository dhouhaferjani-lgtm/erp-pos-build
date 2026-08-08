# Impersonation merge record — 2026-08-08 (merged LOCAL dev, promotion pending batch)

**Merged:** `codex/tenant-impersonation` → local `dev` fast-forward at `c42a202ae`
(10 lane commits rebased onto dev `38e88b3d9`). **NOT yet promoted to origin/dev.**

## Preconditions satisfied (merger-verified)

1. **Owner ruling 2026-08-08:** "If implemented well enough without any security issues,
   merge it now" — post-launch lock lifted.
2. **Security:** final whole-branch verdict = ACCEPT (zero remaining findings); all 5
   former blockers probe-verified closed (B1/B2 privesc dead: `support-access.*`/role
   perms never intersectable + impersonation-context refusal on grant mutations; B3 POS/
   Fiscal/Accounting writes hard-blocked under elevation; B4 lifecycle events chained;
   B5 banner above all routes). 3 adversarial reviewers reconfirmed at exact CI head.
   Orchestrator read the verdicts directly (not relayed).
3. **Rebase:** 3 mechanical conflicts (bootstrap/app.php imports, withholdingApi import
   line, generated permissions map — regenerated with the real generator, byte-identical);
   no product judgment required; no behavioral interaction with the R2 wave (checked:
   dev's Throwable renderer vs write-guard middleware — no overlap).
4. **Gates post-rebase:** SupportAccess partition 86 tests/594 asserts green; PHPStan zero
   branch-attributable errors (2 pre-existing dev reds in `CopiesDocumentData.php:309-310`,
   byte-identical to dev); Pint pass on all 132 changed PHP files; web 34/34, pos 14/14.
5. **PG-only E2E post-rebase:** `SupportAccessPostgresEndToEndTest` PASS 132/132
   assertions on disposable PG DB (dropped, verified; real DBs untouched).
6. **Migrations (5 central + 2 tenant):** safe for unattended `tenants:migrate` — all
   transactional (PG DDL rollback ⇒ retryable), prerequisites all in-repo, partial-unique
   indexes collide with nothing (columns created same deploy ⇒ all NULL).

## Deploy choreography (when promoted to origin/dev — staging auto-deploys)

- Migrations run unattended (verified safe). Then REQUIRED: `RolesAndPermissionsSeeder`
  in every tenant context (adds `support-access.view`/`support-access.manage`) +
  `php artisan permission:cache-reset` (cache is tenant-blind) — else new routes 403.
  Staging `SYNC_PERMISSIONS_ON_BOOT=true` should cover the seeding leg; cache-reset still
  owed.
- Central operator roles: `super_admin` (requests/operates) and `support_approver`
  (four-eyes = business partner account) — the approver account must exist before first use.
- `2026_08_07_020000` `down()` deliberately throws once grant-audit evidence exists —
  NO rollback path through it; roll forward only.

## Standing restrictions (unchanged by the merge)

- **Never use on a real Tunisian tenant until E-4 (Law 2004-63 / INPDP) validation is
  recorded** by the partner-accountant.
- Super-admin MFA lane still owed pre-production.

## Residuals

- `origin/codex/tenant-impersonation` still points at pre-rebase `dc7cdd1f8`; local branch
  intentionally diverged. Update with `--force-with-lease` or leave frozen (feature branch).
- Dev-red test repairs rode in on this branch (commit "repair POS and inventory baseline
  regressions", 4 files, test-only, zero production diff) — attribution noted here for the
  clean trail.
- Local PG leftovers flagged by the E2E run (pre-existing, NOT created today, not dropped):
  `autoerp_impersonation_lane` + 4 orphan `tenant<uuid4>` DBs — housekeeping candidate.
