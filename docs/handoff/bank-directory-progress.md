# Bank Directory Progress

## Branch preparation

- Rebased `feat/bank-reference-verification` onto `origin/dev` at `3b4f11434` before implementation.
- Verified rebased design commit `1691c7f7d` has `3b4f11434` as its direct parent.
- No merge or push performed.

## Phase 1 — Foundation

### Delivered

- Added the rerunnable-safe tenant `banks` table with a partial unique clearing-code index.
- Reconciled the upstream Tunisia SWIFT directory and clearing-code map into 32 canonical `TN.json` rows. France remains deferred.
- Added the Treasury `Bank` model and generated `BankData` DTO contract.
- Added an idempotent `BanksSeeder` and wired it into tenant initialization before payment repositories.
- Added authenticated `GET /api/v1/banks?country=&q=` with tenant/country scoping, active filtering, fuzzy name/short-name search, stable ordering, and no permission/module gate.

### TDD evidence

- RED: `./vendor/bin/phpunit tests/Feature/Treasury/BankDirectoryTest.php` — 2 expected failures because `BanksSeeder` did not exist.
- GREEN: same command — 2 tests, 13 assertions, 0 failures.

### Verification

- `CACHE_STORE=array php artisan typescript:transform` — generated `BankData`; 430 PHP types transformed.
- `./vendor/bin/phpstan analyse --no-progress <Phase 1 PHP paths>` — no errors.
- `./vendor/bin/pint <Phase 1 PHP paths>` — clean after one automatic formatting pass.
- `./vendor/bin/phpunit tests/Feature/Treasury/BankDirectoryTest.php` — 2 tests, 13 assertions, 0 failures.
- `pnpm typecheck` — all four workspace targets completed successfully.
- `pnpm lint` — completed with 0 errors; existing warnings only. Tenant-query audit reported 0 new findings and the design-system audit reported 0 new/stale baseline findings.
- Targeted Vitest: not applicable in Phase 1; no frontend runtime code was added (only the generated DTO declaration).

### Deviations and decisions

- Followed the newer handoff phase split: validator and picker are Phase 2, despite the older design document grouping those primitives into its original Phase 1.
- Reused the Treasury module and existing `treasury`/future consuming namespaces; no new i18n namespace was introduced in this backend-only phase.

### Gate 1

- RC: `bank-gate-1-rc1` (`d97fa7eff`).
- Review: `docs/handoff/gate-reviews-bank/GATE-1-rc1.md`.
- Verdict: `APPROVE`.
- Findings: no blocking findings; one MEDIUM process observation that `origin/dev` advanced during the audit, plus LOW/INFO polish notes. The branch will be rebased onto the new `origin/dev` tip before Phase 2, followed by type regeneration and fresh verification.
- Fable escalation: not invoked; Gate 1 contains no validator math and Opus reported no validator-math uncertainty.
- Post-gate sync: cleanly rebased all three branch commits onto `origin/dev` at `1223dcc37`; type regeneration produced no diff. Re-ran the Phase 1 PHPUnit test (2 tests, 13 assertions), targeted PHPStan (0 errors), workspace typecheck, lint, query-key audit, and design-system audit successfully before starting Phase 2.
