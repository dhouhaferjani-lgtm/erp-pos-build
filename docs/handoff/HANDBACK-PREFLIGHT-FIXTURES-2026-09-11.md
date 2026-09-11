# Preflight fixture repair handback — 2026-09-11

status: review

## Candidate identity

- Review owner: root (Astra)
- Worktree: `.worktrees/preflight-fixture-repair`
- Branch: `codex/preflight-fixture-repair`
- Application base beneath G0: `45445eb50b26ec88da36ab62b96aa9377edf0a54`
- Completed G0 base: `4734590a39ae1a7cc6ed85129a3f0b3912d56ead`
- Candidate range: `4734590a39ae1a7cc6ed85129a3f0b3912d56ead..HEAD`

This slice repairs only the two stale web test fixtures exposed by the G0 preflight. It makes no production React, API, contract, generated-type, lockfile, database, or configuration change.

## Changes

### Shared singleton tenant-scope fixture

`SharedSingletons.tenantScope.test.tsx` previously made `mockApiGetHelper` return a company configuration object for every URL. `AddPartnerModal` now obtains active countries through the same `apiGet` helper, so `/countries?is_active=1` received that object and crashed at `countries.map` before the tenant-scope invalidation assertion could run.

The mock is now URL-aware: `/countries...` returns an empty country array, while all other helper requests retain the existing company-configuration response. Tenant and company query-key assertions remain unchanged.

### Document-ingestion partner fixture

`ReviewIngestionPage.test.tsx` still modeled and expected the legacy partner keys `address`, `country`, and `tax_id`. The existing `PartnerFormData`, `CreatePartnerRequest`, and `PartnerService` contract uses `street_address`, `country_code`, and `vat_number`. Both mock create responses and the exact payload-key assertion now use those canonical fields. The assertion still proves that extraction-only fields do not leak into `/partners`.

## Ownership check

Before editing, every active worktree was checked for working-tree changes and branch-owned commits affecting the two allowed files. Several older or side worktrees appeared different under a direct comparison with `45445eb…`, but each had an empty:

```bash
git log $(git merge-base HEAD 45445eb…)..HEAD -- <the two files>
```

Their apparent differences came from missing later dev ancestry. No active lane had an uncommitted or branch-owned change to either fixture, so there is no concrete file overlap to preserve.

## Red-green evidence

The unchanged G0 candidate reproduced the reported failures with:

```bash
pnpm --dir apps/web exec vitest run \
  src/components/__tests__/SharedSingletons.tenantScope.test.tsx \
  src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx
```

Red result: exit 1; 2 failed files; 2 failed and 15 passed tests; one unhandled `TypeError: countries.map is not a function`. The second failure showed the expected legacy payload-key set versus received canonical keys.

After the fixture edits, the identical command exited 0 with 2 passed files and 17 passed tests, with no unhandled error. Existing asynchronous React `act(...)` warnings remain in `SharedSingletons.tenantScope.test.tsx`; they do not fail the run and were not broadened into this fixture repair.

## React Doctor regression check

- Tool: `react-doctor` 0.9.13, resolved by `npx react-doctor@latest`.
- Required command: `npx react-doctor@latest --verbose --diff`.
- The tool reported that `--diff` is deprecated. Without an explicit base it compared this stacked branch to `origin/main`, scanned 2,630 accumulated files across POS and web, scored 49/100, and exited 1 with 520 existing repository-wide issues. That scope does not isolate this two-file change and no reported issue was repaired here.
- Meaningful pinned regression command: `npx react-doctor@latest --verbose --diff --base 4734590a39ae1a7cc6ed85129a3f0b3912d56ead`.
- Pinned result: exit 0; 2 changed web files scanned, no changed POS source files, score 100/100, no issues.

## Combined G0 and fixture validation

The required laptop-safe preflight command was:

```bash
PREFLIGHT_SCOPE=paths \
PREFLIGHT_TEST_PATHS='tests/Architecture/FeatureLaneManifestCheckerTest.php' \
PREFLIGHT_PINT_PATHS='tools/feature-lane-manifest-check.php tests/Architecture/FeatureLaneManifestCheckerTest.php' \
PREFLIGHT_PHPSTAN_PATHS='tools/feature-lane-manifest-check.php tests/Architecture/FeatureLaneManifestCheckerTest.php' \
PREFLIGHT_VITEST_PATHS='src/components/__tests__/SharedSingletons.tenantScope.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx' \
./scripts/preflight.sh
```

Result: exit 0, complete preflight green.

The run included:

- both changed G0 PHP files green under Pint and level-8 PHPStan;
- the explicit architecture PHP test file green at 81 tests and 463 assertions, serial;
- generated TypeScript and permission-map drift checks green;
- web and POS typechecks green;
- web ESLint green with 0 errors and 6,410 acknowledged warnings;
- query-key, design-system, quantity, feature-lane, preflight-status, i18n, web-detector, POS ESLint-rule, route-manifest, fiscal-parity, and chokepoint checks green;
- the two selected web fixture files green at 17 tests;
- POS fiscal parity green at 29 tests.

The preflight's built-in G0 detector checks also execute `FeatureLaneManifestCheckerTest.php` and `FeatureLaneLocalHarnessTest.php` as separate one-file, serial invocations; these passed at 81 tests/463 assertions and 10 tests/52 assertions respectively. No full PHP suite or database boot ran.

The immutable G0 handback at `4734590a…` truthfully records its earlier full web run as 747 passed files and 2 failed files. That remains historical evidence. This slice did not edit the old handback or rerun the broad web suite; the focused red reproduction and the fully green path-scoped combined preflight supersede those two fixture failures for this candidate.

## Limits

- Dependencies were installed only inside this worktree with `pnpm install --frozen-lockfile --offline` and an isolated API `composer install`. No dependency path or test database was shared or repointed.
- No production source, user-visible UI behavior, generated output, lockfile, API contract, database, browser campaign, broad React cleanup, full web suite, full PHP suite, VPS task, push, merge, deployment, branch-protection, or shared-dev change occurred.
- Local PHP was 8.4.15; CI declares PHP 8.3.
- The candidate remains `status: review`; root owns final review and integration.
