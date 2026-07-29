# Task 6 — Drift-guard hardens: committed permissions map freshness

## Requirement
`ExportFrontendPermissionsMapCommandTest` proved determinism only; a STALE COMMITTED
`apps/web/src/hooks/permissionsMap.generated.ts` would pass CI. Add a PHP-side assertion
that regenerates the map to a temp path and diffs (normalized) against the committed
artifact, failing with a message telling the developer to run
`php artisan permissions:export-frontend-map`. RED first, without committing a stale map.

## What changed
Single file: `apps/api/tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php`
(test-only; no production code needed — the exporter already renders deterministically).

Added two test methods plus three private helpers:

1. **`test_the_committed_frontend_map_is_fresh_against_the_seeder`** — the guard.
   Regenerates via the command to a throwaway temp path (`--path`, no DB), reads the
   committed artifact at `base_path('../web/src/hooks/permissionsMap.generated.ts')`, and
   asserts line-ending-normalized equality. On failure it surfaces `REGENERATE_HINT`
   verbatim: *"The committed frontend permission map (...) is stale relative to
   RolesAndPermissionsSeeder. Run `php artisan permissions:export-frontend-map` and commit
   the regenerated file."*

2. **`test_a_stale_committed_map_is_detected_as_drift`** — the RED-safe regression proof.
   Perturbs an in-memory copy of the fresh export (drops a `treasury.manage` role grant)
   and asserts the SAME `mapsMatch()` predicate the guard uses returns `false`. This proves
   the guard catches drift without ever committing a stale artifact.

Helpers: `freshExport()` (command → temp file → contents), `mapsMatch()` (the guard
predicate), `normalize()` (CRLF→LF + single trailing newline). The original determinism
test is preserved unchanged.

## RED-first proof
Temporarily staled the REAL committed map (dropped `accountant` from `treasury.manage`),
ran the guard → it FAILED with the exact `REGENERATE_HINT` message
(`ExportFrontendPermissionsMapCommandTest.php:87`, "Failed asserting that false is true"),
then restored the file (`git diff --quiet` confirmed clean). No stale map was committed.

## Relationship to the JS drift test
`apps/web/tools/__tests__/permission-map-drift-guard.test.mjs` asserts the export+`git diff`
wiring exists in `preflight.sh` and `ci.yml`. This PHP-side guard is complementary: it
checks freshness against the seeder from within PHPUnit. No overlap, no contradiction.

## Gates
- **Focused test:** `php artisan test tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php`
  → **3 passed (19 assertions)**.
- **Pint:** `./vendor/bin/pint --test tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php`
  → `{"result":"pass"}`.
- **PHPStan:** the configured run analyses `app/` only (tests are excluded from `paths`),
  so the touched test file is never analysed in CI. Run directly on the file it reports 3
  `method.nonObject` findings on `$this->artisan(...)->assertSuccessful()`
  (`PendingCommand|int`) — 2 pre-existed on the original file, my `freshExport()` helper is
  the 3rd identical instance. This idiom appears 6× across the test suite with **zero**
  phpstan-ignore annotations; it is the uniform, tolerated convention. I followed it rather
  than introduce a divergent lone ignore. (The full `app/` phpstan run additionally OOMs at
  512M in this worktree — an env limitation unrelated to this change.)

## Codex round-1 fixes (APPROVE-WITH-FIXES → applied)
Record: `docs/superpowers/reviews/2026-07-28-burndown-task6-codex.md`.

1. **[Important] `freshExport()` temp-file lifecycle** — wrapped the command + read in
   `try { ... } finally { if (is_file($path)) unlink($path); }` so the throwaway file is
   removed on every failure path, not only the happy path.
2. **[Minor] missing-committed-map message** — the `assertIsString($committed, ...)` guard
   now uses `self::REGENERATE_HINT` (was a generic "map is missing" string), so a MISSING
   committed map names `php artisan permissions:export-frontend-map` exactly like the drift
   path.
3. **[Minor] auditable RED evidence** — captured below.

### Auditable RED protocol (re-run 2026-07-29)
Staled the real committed map (`treasury.manage`: dropped `accountant`), ran the guard:

```
   FAIL  Tests\Feature\Console\ExportFrontendPermissionsMapCommandTest
  ⨯ the committed frontend map is fresh against the seeder              35.51s
  FAILED  ... > the committed frontend map is fresh against the seeder
  The committed frontend permission map (apps/web/src/hooks/permissionsMap.generated.ts) is stale relative to RolesAndPermissionsSeeder. Run `php artisan permissions:export-frontend-map` and commit the regenerated file.
Failed asserting that false is true.

  at tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php:87
```

Restore proof:
```
git diff --quiet -- apps/web/src/hooks/permissionsMap.generated.ts; echo $?
0
```

Post-fix GREEN: `php artisan test tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php`
→ **3 passed (19 assertions)**. Pint → `{"result":"pass"}`.

## Concerns
- The guard reads the committed artifact by relative path from `base_path()`; correct for
  the monorepo layout (`apps/api` ↔ `apps/web`). If the web app is ever relocated the path
  const must move with it.
- PHPStan does not gate test files in this project; the new helper's `PendingCommand|int`
  finding is invisible to CI and consistent with existing test code.
