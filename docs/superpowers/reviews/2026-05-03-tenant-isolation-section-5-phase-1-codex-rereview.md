# Verdict (one line)

REQUEST-CHANGES

# Round-2 status of round-1 findings

| Round-1 defect | Round-1 status | Round-2 status |
|---|---|---|
| #1 stable_key uniqueness (S3) | rejected | verified-fixed |
| #2 cluster fallback (S4) | rejected | verified-fixed |
| #3 PHPStan attribute.notFound | error | verified-fixed |
| #4 manual slug uniqueness (S5) | accept-with-edit | verified-fixed |
| #5 InventoryService atomicity test | partially-fixed | partially-fixed |
| cross-cutting mixed cleanup | cross-cutting | still-open |

# Top remaining risks (3 max, if any)

1. Required PHPStan verification is red at HEAD. The failure is no longer the old `CrossTenantRoute` attribute error; it is now five `argument.type` errors in `tests/Unit/Application/Sweep/InventoryServiceTest.php` at lines 96, 266, 316, 334, and 399.
2. The strict-typing cleanup is incomplete against the prompt's own grep/suppression probe: production Sweep code still contains a `mixed` text match in `InventoryDocument.php:22` and a `@phpstan-ignore-line` suppression in `InventoryService.php:113`.
3. The new InventoryService coverage appears behaviorally aimed at the right failure modes, but it cannot be accepted as complete while the required PHPStan slice fails on those tests.

# Per-commit findings

## c44f04f0 - fix scanner fixture CrossTenantRoute imports

Finding: verified-fixed

Both scanner fixtures now import `App\Shared\Architecture\CrossTenantRoute` and use the short attribute name:

- `apps/api/tests/Unit/Application/Sweep/Scanners/Fixtures/exists/Modules/Treasury/Presentation/Requests/CrossTenantRouteRequest.php:7`
- `apps/api/tests/Unit/Application/Sweep/Scanners/Fixtures/find/Modules/Document/Presentation/Controllers/CrossTenantController.php:8`

The old round-1 `attribute.notFound` defect is closed. PHPStan still fails, but on different InventoryServiceTest type errors introduced by the later typing cleanup.

## 01160d54 - scanner stable_key per-statement uniqueness

Finding: verified-fixed

`ExistsRuleVisitor` and `FindCallVisitor` now include `enclosing_method` and `start_file_pos` in each violation payload. Both visitors maintain function-like stacks covering `ClassMethod`, `Function_`, `Closure`, and `ArrowFunction`, with file-scope sentinels for defensive top-level cases.

The scanners consume those fields. `PhpPresentationExistsScanner` builds `symbol = <class>::<enclosing_method>` and hashes `pattern_type`, node form, and byte offset into `statement_fingerprint` at `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:160` and `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:169`. `PhpAstFindScanner` does the same with the called Eloquent method plus byte offset at `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php:153` and `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php:161`.

The added tests cover two same-method `exists:` rules, two `Document::findOrFail()` calls in different methods, and an enclosing-method rename changing the stable key. The missing converse smoke test for method-body-only edits is acceptable for Phase 1 because the scanner intentionally includes the byte offset in the per-statement fingerprint; it is not a blocker for the prior collision defect.

## 9a2ff77b - explicit api.unmapped fallback

Finding: verified-fixed

`ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID` is `api.unmapped` at `apps/api/app/Application/Sweep/Scanners/ClusterResolver.php:29`, and unmatched module paths return the configured fallback at `ClusterResolver.php:47`. The remaining `api.identity-company` strings under `app/Application/Sweep` are explicit mappings for Identity/Tenant/Company/Membership modules, not fallback behavior.

`SweepInventoryGenerateCommand` writes a visible stderr warning that includes the fallback cluster id and each unmapped path at `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php:441`. The live seed inventory declares `api.unmapped` with required fields, `surface: api`, `required_owner: claude`, `status: pending`, `blocked_by: ["api.treasury"]`, and a review gate. `progress.total_clusters` is 29 and `pending: 27` accounts for the new pending cluster alongside 2 blocked clusters.

The schema test's API cluster assertion includes `api.unmapped`.

## 50d3d27a - ManualScanner duplicate slug rejection

Finding: verified-fixed

`ManualScanner::scan()` checks the `cluster_id:slug` composite before building/emitting a `CallsiteRow`, and throws `ManualScannerDuplicateKeyException` with the duplicate key plus both offending indexes. The exception lives under the expected Sweep exceptions namespace and extends `RuntimeException`.

`ManualScannerTest` covers duplicate detection and the same-slug-different-cluster control case.

## 0d72369c - InventoryService multi-event and rename-failure coverage

Finding: partially-fixed

The intended behavior is now covered in tests: one mutation appends events to two callsites and asserts both new events share the same previous/new YAML hashes, matching the service protocol that one `mutate()` call advances the document hash once. The rename-failure test uses a subclass override of `renameTempfile()` and asserts original file bytes plus temp-file cleanup.

The blocker is that these tests are not PHPStan-clean. The required command reports five `argument.type` errors in the same test file, all caused by closures returning callsites whose `history` is inferred as `non-empty-list<array<string, mixed>>` instead of the `HistoryEvent` shape. This means the coverage cannot be accepted under the project's required verification gate.

The production class being non-final is not itself a new security blocker in this context because the writable hook is limited to `renameTempfile()` and all validation/locking remains in `mutate()`. That said, production should avoid subclassing it.

## 3d8b7fe1 - mixed cleanup

Finding: still-open

The typed shape aliases in `InventoryDocument` are a real improvement, but the cleanup is not complete against the prompt's required probes.

Observed strict-typing probe output:

```text
apps/api/app/Application/Sweep/Domain/InventoryDocument.php:22: * Codex Phase 1 review (cross-cutting #3): the `array<string, mixed>` shapes
apps/api/app/Application/Sweep/InventoryService.php:113:            if (! $afterDoc instanceof InventoryDocument) { /* @phpstan-ignore-line */
apps/api/app/Application/Sweep/InventoryService.php:371:        /** @var DocumentData $parsed */
```

The exact `mixed` grep expected zero production matches; it still matches `InventoryDocument.php:22`. More importantly, the suppression grep finds `@phpstan-ignore-line` in production code, which conflicts with the prompt's instruction to verify no silent suppressions were introduced. PHPStan also fails in the Sweep test slice, so the type narrowing is not end-to-end clean.

# Cross-cutting probes

Branch/history: matched the prompt. Tip is `3d8b7fe1`; `git log --oneline 2f8fb77b..feat/tenant-isolation-sweep-execution` returned the six expected remediation commits.

TDD discipline: each substantive remediation commit includes tests touching the defect area. The fixture-import commit is fixture-only, which is reasonable for the previous PHPStan attribute failure. The multi-event/write-failure commit added the expected tests but left the required PHPStan slice failing.

POS surface: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` was empty.

Live inventory integrity: no mutation command was run before the verification failure. The dry-run integrity check was not reached.

# Verification

Verification stopped at command 1 as instructed by the prompt.

Command run from `apps/api`:

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
  app/Application/Sweep \
  app/Console/Commands/SweepInventoryGenerateCommand.php \
  tests/Unit/Application/Sweep \
  tests/Feature/Console/SweepInventoryGenerateCommandTest.php
```

Result: failed with 5 errors.

Error summary:

- `tests/Unit/Application/Sweep/InventoryServiceTest.php:96` - `withCallsiteUpdate()` mutator closure returns history inferred as `non-empty-list<array<string, mixed>>`.
- `tests/Unit/Application/Sweep/InventoryServiceTest.php:266` - same error shape.
- `tests/Unit/Application/Sweep/InventoryServiceTest.php:316` - same error shape.
- `tests/Unit/Application/Sweep/InventoryServiceTest.php:334` - same error shape.
- `tests/Unit/Application/Sweep/InventoryServiceTest.php:399` - same error shape.

Commands after PHPStan were not run because the prompt explicitly says to stop if any verification step fails.

# Diff to the work (if APPROVE-WITH-MINOR-EDITS-APPLIED)

N/A - verdict is REQUEST-CHANGES.

# Required edits before Phase 2 begins

1. Make the required PHPStan slice pass without suppressions. The InventoryServiceTest mutator closures need typed `HistoryEvent` construction or equivalent shape-safe helpers so `history` is not inferred as `array<string, mixed>`.
2. Remove the production `@phpstan-ignore-line` in `InventoryService.php:113` by making the runtime guard/type contract explicit without suppression.
3. Make the exact production `mixed` grep return zero matches, including comments matched by the prompt's grep command, or change the prompt/check before claiming compliance.

# Confidence gradient

| Component | Confidence | Question that would change rating |
|---|---:|---|
| StableKey uniqueness remediation | 4/5 | Do architecture gate counts remain exactly 94/125 once PHPStan is fixed and the stopped verification sequence reaches that command? |
| api.unmapped fallback | 4/5 | Does the real dry-run generate command enumerate all current unmapped production files once verification reaches it? |
| ManualScanner uniqueness | 5/5 | None for the prior duplicate-slug defect. |
| InventoryService coverage | 3/5 | Do the new tests pass both PHPStan and PHPUnit after their history event shapes are tightened? |
| Strict typing cleanup | 2/5 | Does the required grep plus PHPStan run become clean without suppressions or broad casts? |

# Phase 2 readiness

Concrete blockers before workflow commands begin:

1. Fix the PHPStan failures in `tests/Unit/Application/Sweep/InventoryServiceTest.php`.
2. Remove the production PHPStan suppression and residual strict-typing grep matches.
3. Re-run the full verification sequence from the start, including Pint, unit suites, sweep-progress architecture gates, dry-run inventory generation, Web Gate C, and the POS-surface diff.
