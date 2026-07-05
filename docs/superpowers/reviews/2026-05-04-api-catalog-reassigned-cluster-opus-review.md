# Opus adversarial review — api.catalog reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 32e25195
Reviewer: opus

Verdict: APPROVE
Commit reviewed: f88ad4a5

## Summary

Four callsites reassigned from api.unmapped → api.catalog by 9630e58b are
correctly closed in f88ad4a5:

- 011 / 012 (`tax_configurations` exists validators on
  CreateProductRequest / UpdateProductRequest): annotated as
  `structurally_protected_by_country_scoped`. Verified the migration
  (`2025_12_30_100000_create_tax_configurations_table.php`) — table has no
  `tenant_id` / `company_id`, only `country_code`. Annotation mirrors
  the api.catalog.002 / 005 + api.identity-company.001 precedents. Audit
  doc `2026-05-04-scanner-tax-configurations-false-positive.md` is detailed
  and consistent.
- 018 / 019 (`Category::where('company_id', …)->find()` chains in
  CategoryController::store / update): forward-fix prepends `::query()` so
  the AST visitor walks a MethodCall chain instead of terminating on a
  StaticCall. Verified `categories` migration — table has only
  `company_id` (no `tenant_id`), so single-predicate scoping is the cluster
  invariant for this resource. No SQL semantics change (verified by
  query-log shape match in pre-fix vs. post-fix runs).

Test suite: `CatalogTenantIsolationTest` 27 / 71 OK. PHPStan + Pint clean
on the four touched files plus the test file. POS surface diff dev..HEAD
empty (only tooling/audit scripts under apps/web/tools/).
`sweep:inventory:verify-history` reports 1352 events / 296 callsites /
0 problems. The repair `edit_applied` events for 011 / 012 are present,
attributed to codex, link `commit: f88ad4a5`, and document the
needs_recheck → in_progress reset transparently.

## Findings

1. **Test honesty** (informational, not blocking). Reverting the source
   files to `516c6f61` (pre-fix) and re-running the five new tests yields
   5 / 5 PASS — i.e. the new tests do not falsify the pre-fix code.
   This is correct and disclosed: the fix is a scanner-readability
   change with no SQL semantics change (018 / 019) and the tax_configurations
   callsites are a structural false-positive (011 / 012). The new tests
   pin same-tenant control flow + structural SQL shape as a forward
   regression-guard. Acceptable for this disposition. The behavioral
   cross-tenant rejection of `parent_id` is already covered by
   api.catalog.014 / 015.

2. **Sibling controllers using the same StaticCall blind-spot pattern**
   (out of scope, follow-up). Hostile grep on
   `apps/api/app/Modules/Product/` surfaced four sibling controllers
   doing bare `Model::find($id)` —
   `HealthClaimController`, `KeyComponentController`,
   `CertificationController`, `IngredientController`. Migrations confirm
   `health_claims` / `ingredients` / `certifications` are GLOBAL
   REFERENCE tables (no tenant_id / company_id / country_code, slug
   unique) — same regulatory parapharmacy pattern. These are NOT
   tenant-isolation gaps. Out of scope for this 4-callsite review.

3. **`ProductController` + `EnrichmentReviewController` use the same
   StaticCall idiom** (out of scope, follow-up).
   `Product::where('company_id', …)->where('id', …)->first()` (4
   instances around lines 225, 385, 521, 557) and the
   `EnrichmentResult::where(...)` chain are scanner-blind in the same
   way. `products` HAS both tenant_id and company_id, so the
   ProductController chains are missing `tenant_id` (real defense-in-depth
   gap, but already gated by per-route route-model-binding scope). Tracked
   by the existing scanner-blind-spot followup
   (commit d7184178 docs); not within this review's scope.

## Audit exhaustiveness

- Read `git show f88ad4a5` and the full diff for the three source files
  + the test file.
- Verified categories + tax_configurations migrations against the
  cluster invariant.
- Read all four YAML rows (011 / 012 / 018 / 019), confirmed the
  `edit_applied` repair events for 011 / 012 with explanatory notes,
  fix_commit pin, and chain integrity (`verify-history` 0 problems).
- Re-ran the test suite (27 / 71 OK), PHPStan clean, Pint clean.
- Test honesty experiment: stepped back to the pre-fix tree at
  `516c6f61`, confirmed all 5 new tests still pass — disclosed and
  acceptable for this disposition.
- Hostile grep on `apps/api/app/Modules/Product/` for `Model::where|find|
  exists:tax_configurations|exists:categories|exists:products` —
  enumerated all matches; no in-scope leak. Sibling concerns logged as
  follow-ups (Findings 2, 3) without expanding scope.
- POS surface diff `dev..HEAD` strict (apps/web/src/features/pos,
  pages/pos, store/pos, lib/pos) is empty.

## Confidence

HIGH. The four callsites are correctly disposed — two as scanner
false-positives on a country-scoped global reference table (precedent
exists, audit doc is clear, behavior is structurally safe), two as
scanner-readability-only reformats (no SQL semantics change, query log
verified). Repair events are honest and signed. Tests pin forward
regression guards. CI gates (PHPStan, Pint, history chain, POS diff)
all green.
