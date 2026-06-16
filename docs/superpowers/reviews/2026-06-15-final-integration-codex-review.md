# Final Integration — Codex Adversarial Review + Adjudication

**Date:** 2026-06-15
**Scope:** whole branch `feat/web-variant-authoring`, range `merge-base(c8d35b5fd)..HEAD` (51 files). Companion: an independent spec-coverage + quality-gate pass (✅ full coverage; 42 backend PG tests + 18 frontend tests green; PHPStan/Pint clean on feature code).
**Codex verdict:** REVISE — 2 HIGH (0 BLOCKER). Both fixed in `b271cd84e`.

> Codex's findings were checked against the actual multi-tenancy architecture before action (db-per-tenant in prod; `TENANCY_DB_PER_TENANT=false` shared-DB in the test/compat harness).

## Findings & resolutions

| ID | Sev | Finding | Verified? | Resolution |
|----|-----|---------|-----------|------------|
| H-1 | HIGH | `OnboardingChecklistService::checkProductOptions()` queried `ProductAttribute->where('is_variant_axis',true)->exists()` with no tenant scoping — Codex flagged a cross-tenant leak. | Nuanced: **safe in prod** (db-per-tenant: the connection is the tenant DB), but **not isolated under the shared-DB test/compat mode**, and inconsistent with the service's other checks (which scope by `company_id`). | **FIXED** — `checkProductOptions(?Company $company)` now filters `->where('tenant_id', $company->tenant_id)`. Added an isolation test: a variant-axis attribute under a *different* tenant_id leaves the step incomplete. |
| H-2 | HIGH | PG-only tests (`VariantBarcodeRaceTest`, `ProductVariantMatrixGenerationTest`) rely on partial unique indexes / SQLSTATE 23505 mapping that SQLite doesn't reproduce, but had no driver guard ⇒ confusing failures / silent non-coverage off-PG. | TRUE | **FIXED** — both `setUp()`s `markTestSkipped` unless the driver is `pgsql` (clean skip off-PG; still pass on PG). `VariantBarcodeValidationTest`/`VariantDeletePolicyTest`/`VariantStockReaderTest` left ungated (verified they pass on SQLite — no PG-only dependency). |

## Clean areas (Codex confirmed)
- Cross-layer contract coherence (frontend `{axes}` ↔ `GenerateMatrixRequest`/`normalizeAxes`; `{data, meta}` ↔ `api.post`; `attribute_values` field names ↔ hydration/orphan; barcode/delete 422 envelopes ↔ frontend handlers).
- D1/D2 delete guard: contract isolation (Catalog imports only `VariantStockReader`), `bccomp` logic, QuantityScale string sum.
- Transaction/exception seams (barcode 23505 inside the matrix outer tx; restore ValidationException → 422).
- Generated types (`attribute_values` present in `generated.d.ts`, committed, no drift).

## Net
2 HIGH fixed; branch is correct and merge-ready pending the standard pre-merge step (merge current `dev` into the branch and re-run gates — `dev` advanced to `732168b9c` from parallel work since the fork). Note on H-1: Codex's "shared catalog table" framing was wrong for production (db-per-tenant), but the fix is still the right call for compat-mode isolation and convention consistency — verifying the architecture turned a mis-framed finding into a correct, scoped hardening.
