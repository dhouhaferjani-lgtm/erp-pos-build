# web.tanstack-keys Batch 82 — Opus Review

Commit reviewed: 1cae9146
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `1cae9146` — fix(tenant-isolation): wrap web.tanstack-keys batch 82 (settings company tax inventory)
Scope: 11 callsites across 3 production files.
- `CompanyPage.tsx` (.625 read company-settings, .626/.627/.628 invalidate company-settings on update/upload/delete)
- `TaxSettingsPage.tsx` (.639 read company.tax-settings, .640 invalidate company predicate, .641 invalidate companies predicate)
- `components/InventorySettings.tsx` (.648 read company-settings, .649 read reservation-settings, .650 invalidate company-settings on update, .651 invalidate reservation-settings on update)

Test: `SettingsPages.tenantScope.test.tsx` (new, 295 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 7 reads + 7 invalidate sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)` as appropriate. `TaxSettingsPage` uses predicates because the `company`/`companies` keys are reused across multiple consumers.
2. **State-value selectors**: All 3 consumers select `tenantId`/`companyId`.
3. **Enabled gates**: All 3 reads AND-combine `tenantId !== null && companyId !== null` with existing `!!currentCompany?.id`.
4. **Async invalidate cascade**: All 7 mutation onSuccess handlers converted to `async/await`. `TaxSettingsPage` uses `Promise.all` for parallel invalidates of company + companies.

## Non-blocking findings

1. `scopedNamespacePredicate` inlined again in TaxSettingsPage — N+1 copies of the helper. Tracked.
2. `CompanyPage` and `InventorySettings` both query `['company-settings']` — they share a cache entry, which is intentional (the InventorySettings save invalidates the same key the CompanyPage reads).

## Locks applied

11 callsites locked at fix commit `1cae9146`:
web.tanstack-keys.625, .626, .627, .628, .639, .640, .641, .648, .649, .650, .651.
