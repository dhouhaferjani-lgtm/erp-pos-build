# api.catalog Cluster — Claude Review

Cluster: `api.catalog`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Product, composite item, recipe, modifier, modifier-group, unit-of-measure controllers + their validators. Pre-sweep, several composite-item / modifier chains were company-only (missing tenant predicate), and the `NoCircularCompositeItemReference` rule did an unscoped find.

Inventory callsite total: **37 / 37 fixed**.

## Implementation summary

Top fix commits: `516c6f61` (17 callsites), `900b0131` (9), `48ff4bb9` (7).

Top files: `UpdateCompositeItemRequest`, `StoreModifierRequest`, `StoreCompositeItemRequest`, `ModifierGroupController`, `ModifierController`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-catalog-cluster-codex-review.md`
- `2026-05-04-api-catalog-cluster-codex-round2-review.md`
- `2026-05-04-api-catalog-cluster-opus-review.md`
- `2026-05-04-api-catalog-cluster-opus-round2-review.md`
- `2026-05-06-api-catalog-reassigned-cluster-codex-round2-review.md`

## Gates evaluated

1. **Composite-item chains** carry tenant + company predicates (controllers, recipe lookups, variant access).
2. **Modifier / modifier-group** lookups scoped via `ScopedExists::tenantAndCompany`.
3. **Tax-configuration country coherence**: `StoreCompositeItemRequest` validator scoped (separate from tax-configurations false positive handled in the closure plan).
4. **Circular-reference rule**: `NoCircularCompositeItemReference` find now scoped (api.catalog.026).

## Non-blocking follow-ups

1. **tax_configurations false positives** on `api.catalog.023` (and the unmapped sibling `api.unmapped.011`/`.012` on `CreateProductRequest`/`UpdateProductRequest`) — closure plan Task 3 Step 1 removes the table from guarded list.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php`
- Closure plan: Task 3 Step 1 (tax_configurations scanner fix)
