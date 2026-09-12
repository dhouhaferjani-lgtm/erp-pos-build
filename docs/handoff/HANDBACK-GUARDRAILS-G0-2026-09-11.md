# Guardrails G0 handback — 2026-09-11

status: review

## Review identity

- Review owner: root (Astra)
- Worktree: `.worktrees/guardrails-g0`
- Branch: `codex/guardrails-g0`
- Verified application base: `45445eb50b26ec88da36ab62b96aa9377edf0a54`
- Implementation tip: `5a7ce376eab9fcfb8f6d47193dc04c0c60cab4a1`
- Review range: `45445eb50b26ec88da36ab62b96aa9377edf0a54..5a7ce376eab9fcfb8f6d47193dc04c0c60cab4a1`
- Implementation commits:
  - `97adecf7e6a7582ebcdb530c90350ea019acaf6e` — `Phase 0.1.1: Make pilot guardrails fail closed`
  - `5a7ce376eab9fcfb8f6d47193dc04c0c60cab4a1` — `Phase 0.1.3: Prove quarantine target types`

This packet is handed over for review. The bounded G0 implementation is complete, but the required scoped preflight command is not a full green: it reached and passed the G0 checks, then exited 1 on two unchanged web test files outside this packet. The broad web baseline was not rerun, so this handback does not classify those web failures as proven baseline failures.

## Delivered scope

The implementation changes five files:

1. `.github/workflows/ci.yml`
   - makes `chokepoint-gate` and `t6-phase0b-pgsql` dependencies of `all-checks-pass` and includes both in `EXPECTED_JOBS`;
   - runs the new preflight status harness in `backend-architecture`;
   - runs `pnpm typecheck` in the existing `pos-test` job without changing its event condition.
2. `apps/api/tools/feature-lane-manifest-check.php`
   - independently requires the two reviewed aggregate obligations in both aggregate lists;
   - requires their reviewed event coverage;
   - deliberately avoids enrolling every present or future advisory/parked job.
3. `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php`
   - executes the real aggregate shell with failed and cancelled mandatory jobs;
   - removes each mandatory job from both aggregate lists, parses the mutated YAML to prove the mutation took effect, and proves the checker fails;
   - proves event-condition drift fails;
   - proves the POS typecheck is unconditional inside the existing POS job and retains the exact existing job trigger.
4. `scripts/preflight.sh`
   - exits 2 before running dependencies for unknown scope and empty or whitespace-only path selections;
   - propagates child failures under `set -e`;
   - adds local POS typecheck and the status-liveness harness.
5. `scripts/tests/preflight-status-test.sh`
   - invokes a copied instance of the repository's actual preflight script with every subordinate command stubbed;
   - covers empty, whitespace-only and invalid selections, scoped success, exact child exit propagation, and full-mode dispatch without booting Laravel or running a PHP suite;
   - clears inherited `PREFLIGHT_*` and stub-control variables before applying each case.

There are no domain, schema, lockfile, feature-flag, ceiling, quarantine-content or quarantine-behavior, feature-lane ownership, or DTO generation changes.

## Red-green evidence

| Obligation | Red evidence | Final evidence |
|---|---|---|
| Empty path selection is incomplete | Stub harness against baseline preflight expected exit 2 and received exit 0 after the script printed its skipped/incomplete summary. | `bash scripts/tests/preflight-status-test.sh` passes; empty and whitespace-only selections exit 2 before a stub command runs. |
| Aggregate mandatory-job completeness | New targeted PHP tests against baseline had 3 failures: failed/cancelled mandatory jobs were absent from aggregate context, and deleting a job from both lists left the checker green. | Targeted PHP file passes `81 tests, 463 assertions`; checker rejects each mandatory job removed from both lists. |
| POS typecheck detector liveness | Temporary untracked `apps/pos/src/__guardrails_g0_typecheck_probe.ts` assigned a string to a number; actual `pnpm --dir apps/pos typecheck` exited 2 with TS2322. The probe was removed. | Clean `pnpm --dir apps/pos typecheck` exits 0. CI and preflight wiring are structurally guarded. |
| Harness isolation under real preflight ambience | Review-found regression: `PREFLIGHT_TEST_PATHS=tests/Architecture/FeatureLaneManifestCheckerTest.php bash scripts/tests/preflight-status-test.sh` made the nominal empty-path case inherit the selection and return 0 instead of 2. | The harness unsets every tested `PREFLIGHT_*` input before case overrides. It passes with ambient `PREFLIGHT_SCOPE`, `PREFLIGHT_TEST_PATHS`, `PREFLIGHT_PINT_PATHS`, and stub failure controls populated. |
| Checker target type proof | The identical both-file level-8 PHPStan command at immutable base `45445eb…` reported two `string|null` arguments from the quarantine target split. | A limit-two split keeps the required class component a string and makes only the method optional; the same PHPStan command passes with no errors. |

The aggregate tests use the shell body extracted from `.github/workflows/ci.yml`. The preflight harness copies and invokes `scripts/preflight.sh`; it does not reproduce the script's decision logic.

## Targeted validation

All commands below ran from the isolated worktree. PHP tests were serial and restricted to one test file per invocation.

| Command | Result |
|---|---|
| `php tools/feature-lane-manifest-check.php` | pass — 1,519 Feature classes, 74 groups, 1,930 classes across all suites; parked `70/1254`, debt `1/1` unchanged |
| `./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | pass — 81 tests, 463 assertions |
| `./vendor/bin/pint --test tools/feature-lane-manifest-check.php tests/Architecture/FeatureLaneManifestCheckerTest.php` | pass |
| `bash scripts/tests/preflight-status-test.sh` | pass |
| Ambient-variable invocation of the same harness | pass |
| `bash -n scripts/preflight.sh scripts/tests/preflight-status-test.sh` | pass |
| `pnpm --dir apps/pos typecheck` | pass |
| `actionlint -oneline .github/workflows/ci.yml` | pass with actionlint 1.7.12; no diagnostics |
| `./vendor/bin/phpstan analyse tools/feature-lane-manifest-check.php tests/Architecture/FeatureLaneManifestCheckerTest.php --level=8 --memory-limit=2G` | pass — no errors |
| `git diff --check` | pass |

The combined level-8 PHPStan command originally exited 1 because `array_pad(explode(...), 2, null)` inferred the already-regex-validated class component as `string|null`, which then reached `lcfirst()` and `class_exists()`. Baseline provenance was established by running the identical command in a worktree whose `HEAD` was the immutable base `45445eb50b26ec88da36ab62b96aa9377edf0a54`; it produced the same two findings at base lines 1243 and 1261. The review-authorized refinement now splits with `explode('::', $target, 2)`, takes the required first component directly, and defaults only the optional method component to `null`. Existing class and method validation remains unchanged. The same both-file PHPStan command is now green.

## Required preflight result

The required laptop-safe command was:

```bash
PREFLIGHT_SCOPE=paths \
PREFLIGHT_TEST_PATHS='tests/Architecture/FeatureLaneManifestCheckerTest.php' \
PREFLIGHT_PINT_PATHS='tools/feature-lane-manifest-check.php tests/Architecture/FeatureLaneManifestCheckerTest.php' \
PREFLIGHT_PHPSTAN_PATHS='tests/Architecture/FeatureLaneManifestCheckerTest.php' \
./scripts/preflight.sh
```

It exited 1. Before the terminal failure it passed targeted Pint, test-file PHPStan, the 81-test PHP invocation, type generation and generated-type drift, permissions-map drift, web typecheck, the new POS typecheck, web ESLint (6,410 warnings and 0 errors), key/design/quantity audits, feature-lane manifest and architecture checks, the local harness suite (10 tests, 52 assertions), the new preflight status harness, i18n, web detector tests, POS ESLint rule tests, and route-manifest checks.

The subsequent default broad web Vitest stage reported:

- `src/components/__tests__/SharedSingletons.tenantScope.test.tsx`: create button not found, with unhandled `countries.map is not a function`;
- `src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx`: expected partner payload keys differed from received `country_code`, `street_address`, and `vat_number` keys.

Terminal totals were 2 failed and 747 passed test files; 2 failed, 5,049 passed, and 3 todo tests; one unhandled error. Both failing files and their source areas are unchanged by G0. The broad baseline was not rerun.

## Lane overlap and merge obligation

The held lanes at implementation start were:

| Lane | Tip | Relevant overlap |
|---|---|---|
| A1a | `1e19a0f37f3f86efb45020a310def644a1ac77fb` | committed `.github/workflows/ci.yml` backend PostgreSQL allowlist additions |
| T2/S1 | `2084493505f1fb6b99f2c58948fb1ef61ac7b1d4` | committed `.github/workflows/ci.yml` backend PostgreSQL allowlist additions |
| IMP1 | `4300d9ff362f279664d8f2839dfb1b5c15b9e384` | committed `.github/workflows/ci.yml` backend PostgreSQL allowlist additions |

G0 edits disjoint workflow regions: backend architecture near line 200, POS tests near line 2,580, and the final aggregate near line 2,776. Integration must preserve the held lanes' backend PostgreSQL additions while also preserving all three G0 workflow changes. Their files, registers, branches, and holds were not changed or cleared.

RBAC W0a-S1 tip `05455fa41d32531f908899884fc411e4684c2637` is already an ancestor of the verified application base through merge `6415062b9`; it is not a held overlapping lane. The previously recorded generic older RBAC worktree tip `ed88aa2ed23db17b006850e0a0f2466a163f5e2f` was incorrect and has been removed from the table.

## Limits and review state

- Dependencies were installed only inside the isolated G0 worktree: pnpm from the frozen offline lockfile and Composer into the isolated API vendor directory. No other worktree's dependency installation or database was repointed or mutated.
- No database boot, full PHP suite, native Tauri/device test, CI run, push, merge, deployment, branch-protection change, shared-dev movement, or VPS work was performed.
- Local PHP was 8.4.15; CI declares PHP 8.3.
- Default `PREFLIGHT_SCOPE=paths` callers must now provide a nonblank `PREFLIGHT_TEST_PATHS`; deliberate full mode remains restricted to VPS/CI.
- Acceptance remains qualified because the required preflight command ended nonzero after G0 checks. Root owns final aggregate-coverage policy review and disposition of the unchanged web failures.
