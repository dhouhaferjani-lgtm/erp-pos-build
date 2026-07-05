# Verdict (one line)

REQUEST-CHANGES

# Top remaining risks (3 max)

1. Stable keys are not unique per AST callsite: both PHP scanners use coarse constants instead of a real statement fingerprint, so duplicate same-file/same-symbol/same-resource violations can share one `stable_key`.
2. Required verification fails: PHPStan reports unresolved `#[\CrossTenantRoute]` attributes in two scanner fixtures, so Phase 1 is not PHPStan clean.
3. Unmapped modules silently fall into `api.identity-company`, which misclassifies many real modules and hides resolver gaps from triage.

# Subagent's self-flagged design choices — verdict per choice

| Choice | Status | Note |
|---|---|---|
| S1 hash chain canonicalization | accept | `InventoryService::canonicalForHashing()` zeroes `metadata.yaml_sha256` and only the per-event chain fields before dumping with one Symfony YAML configuration. This is symmetric for service-written files, though the design intentionally does not detect chain-field-only edits until Phase 2 verify-history. |
| S2 mutator stamping protocol | accept-with-edit | The service distinguishes newly appended events by comparing per-callsite history counts and does not synthesize events for no-op mutators. Missing test coverage: multiple events across multiple callsites in one mutation. |
| S3 symbol_fqn class-level | reject | `PhpAstFindScanner` sets `symbol` to `Class::find` / `Class::findOrFail`, not the enclosing method, and both scanners use a coarse `statement_fingerprint`. This breaks master-plan rename semantics and can collide multiple callsites. |
| S4 api.identity-company fallback | reject | The default fallback is invisible and wrong for modules such as `BatchExpiry`, `Expense`, `Product`, `Scheduling`, `Vehicle`, and others. Use an explicit `api.unmapped` fallback or emit warnings/errors for unmapped modules. |
| S5 manual stub format + slug uniqueness | accept-with-edit | The row shape is completed by `CallsiteRow::toInventoryRow()` and satisfies the callsite schema, but duplicate `cluster_id + slug` is not rejected in `ManualScanner::scan()` and has no test. |

# Per-commit findings

## e81dcb13 — InventoryService

Finding: partially-fixed
`InventoryService` uses `flock()` plus tempfile/rename and validates before write in `apps/api/app/Application/Sweep/InventoryService.php:72` and `apps/api/app/Application/Sweep/InventoryService.php:304`. The hash canonicalization is internally consistent at `apps/api/app/Application/Sweep/InventoryService.php:407`. Gaps remain: no test simulates write failure between tempfile write and `rename()`, no test covers multiple appended events across multiple callsites, and the new code contains many `array<string, mixed>` annotations despite CLAUDE rule #3 and the prompt's strict grep expectation.

## fe556613 — visitor namespace move + Scanner abstractions

Finding: still-open
The namespace extraction preserves shared visitor consumers by import, but the scanner key contract is structurally wrong. `PhpPresentationExistsScanner` hardcodes the symbol as `::rules` and uses only table/form/pattern for identity at `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:165`, so repeated same-table rules can collide. `PhpAstFindScanner` uses the called Eloquent method as the symbol suffix at `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php:153` and sets `statement_fingerprint` to only the pattern type at `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php:165`; method renames in the containing class are invisible and duplicate `Document::findOrFail()` calls in one class are indistinguishable.

## ed33b87c — ManualScanner

Finding: partially-fixed
`ManualScanner` validates required stub fields and emits schema-shaped `CallsiteRow`s in `apps/api/app/Application/Sweep/Scanners/ManualScanner.php:121` and `apps/api/app/Application/Sweep/Scanners/ManualScanner.php:149`. It does not enforce uniqueness for `manual:<cluster>:<slug>` keys at scan time, and `ManualScannerTest` has no duplicate-slug fixture. That should be fixed before manual rows become workflow-owned state.

## 2f8fb77b — SweepInventoryGenerateCommand

Finding: still-open
The merge command implements the broad cases, but it relies on the broken scanner `stable_key`s. It also silently configures `ClusterResolver` with `api.identity-company` as fallback at `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php:75`, then suppresses scanner failures into warnings at `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php:121`. PHPStan fails on the committed fixtures: `tests/Unit/Application/Sweep/Scanners/Fixtures/exists/Modules/Treasury/Presentation/Requests/CrossTenantRouteRequest.php:21` and `tests/Unit/Application/Sweep/Scanners/Fixtures/find/Modules/Document/Presentation/Controllers/CrossTenantController.php:15` reference an attribute class that does not exist.

# Cross-cutting findings

TDD discipline is not verifiable from the commits: each commit contains tests and production together, and no red/green evidence is present in the history.

Module boundaries look clean for production sweep code: grep found no `App\Modules` imports or `app()` service-location calls under `app/Application/Sweep` or the generate command.

Strict typing is not clean against the prompt's grep. Production files contain many `mixed` PHPDoc annotations, and test fixtures include `: mixed` return types.

Atomicity is partially verified. Mutator exceptions and schema-invalid output leave the temp inventory unchanged, but there is no failure-injection test for tempfile write/fsync/rename failure.

POS surface is untouched: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` was empty.

Live inventory integrity was not fully verified because the required verification sequence stopped at the PHPStan failure. A post-failure diff check showed `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` unchanged.

Verification stopped at command 1 as instructed. Output summary: PHPStan found 2 errors, both `attribute.notFound` for unresolved `CrossTenantRoute` in the two scanner fixtures named above.

# Diff to the work (if APPROVE-WITH-MINOR-EDITS-APPLIED)

N/A — verdict is REQUEST-CHANGES.

Required edits before Phase 2 begins (numbered, file:line):
1. Fix scanner stable-key inputs to include the enclosing symbol and a real normalized AST statement fingerprint, and add duplicate-key tests for same-file same-model/table violations.
2. Replace the `api.identity-company` fallback with an explicit unmapped path (`api.unmapped`) or fail/warn with a visible unresolved-cluster report.
3. Fix the PHPStan fixture attribute references by importing or defining the test attribute shape instead of relying on an unresolved short-name match.
4. Add `ManualScanner` duplicate `cluster_id + slug` rejection with a clear error and fixture-backed test.
5. Add InventoryService tests for multiple events across multiple callsites and write-failure atomicity.

# Confidence gradient

| Component | Confidence | Question that would change rating |
|---|---:|---|
| InventoryService atomicity | 3/5 | Does a failure-injection test prove original bytes survive tempfile write/rename failure? |
| Hash-chain protocol | 4/5 | Will Phase 2 verify-history reject chain-field-only tampering excluded from `yaml_sha256`? |
| Stamping protocol | 3/5 | Do workflow commands append exactly one event per affected callsite and avoid mutating pre-existing history? |
| Scanner extraction (gate count preservation) | 3/5 | Do the architecture gates still report 94 / 125 after PHPStan is fixed and the full gate command runs? |
| StableKey rename semantics | 1/5 | Can scanners prove unique keys for same-file duplicates and correct stale-orphan behavior on enclosing method/symbol renames? |
| ManualScanner contract | 3/5 | Does scan-time duplicate slug rejection land before manual rows are added? |
| GenerateCommand merge logic | 2/5 | Does merge behavior remain correct once scanner keys are made genuinely per-callsite? |

# Phase 2 readiness

Concrete blockers (if any) before workflow commands begin:
1. Fix PHPStan failure in the scanner fixtures and rerun the required verification sequence from the start.
2. Redesign scanner stable-key inputs so each AST violation has a unique, rename-aware identity matching master-plan Section 4.
3. Make unmapped cluster resolution visible instead of silently assigning rows to `api.identity-company`.
