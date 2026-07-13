# GATE 3 RC2 — Wave 3 (CSV `placement_path`)

## RC1 fixes — all four verified fixed

1. **#1 HIGH (`_placement_plan` leak → React crash) — FIXED.** `errors()` now strips `_`-prefixed keys (`ImportController.php:336`). All four row-data exposure surfaces strip internal data (`preview` :266-291, `errors` :335, `FailedRowsExportService` :104, `ResultWorkbookService` :100). Regression test `test_errors_endpoint_hides_the_internal_plan_for_other_invalid_fields` is present.
2. **#2 LOW (strict dry-run↔commit divergence) — FIXED.** `commitRow` gates recreation on `mode === 'auto_create'` (`ProductPlacementImportService.php:135-149`); covered by `test_strict_commit_does_not_recreate_a_node_deleted_after_preview`.
3. **#3 LOW (no async test) — FIXED.** Covered by `test_queued_processor_commits_the_persisted_placement_plan`.
4. **#4 cosmetic (inverted depth default) — FIXED.** The default is `['aisle','rack','shelf','bin','section','zone']` (`ImportWizardPage.tsx:42`).

## Independent hunt — clean

- **CSV bulk-write integrity:** per-row `DB::transaction` on both sync (`ImportService.php:347`) and async (`ProcessImportJob.php:136`) provides rollback parity, proven by the drift test; the dry-run is write-free and commit is plan-driven through the Phase-1 service (no raw inserts); auto-create conflict guard and within-CSV duplicate-node reuse are safe; tenant/company scoping is present; commit reads `plan['location_id']` without a queued-path `CompanyContext` dependency; missing plans fail closed.
- **Conformance:** §3.1/A1-vs-A10 is not applicable to this exact-code walk (no `LIKE`); code-first ambiguity errors are deterministic; en/fr/ar i18n parity is complete with no JSON duplicate keys; zero new hardcoded colors; the generated `LocationNodeType` is present and matches; no new query keys; no `parseFloat` or `any`.

## Findings

1. **LOW / informational** — CSV placement writes are gated by `imports.manage`, not `inventory.adjust` (`ImportServiceProvider.php:71`). This differs from spec §4's per-endpoint gating but is consistent with the import module's existing contract; the brief does not require changing it. Not a blocker.

**Caveat:** the reviewer could not execute the test suite in-session because its sandbox declined approval, as in RC1. The executor independently confirmed the green test and static-analysis gates before tagging.

VERDICT: APPROVE
