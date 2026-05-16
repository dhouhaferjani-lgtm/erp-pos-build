# Task 17 — `ModuleActivationResolver` — Codex Review

**Commit reviewed:** `3cff98285` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Files under review:**
- `apps/api/app/Shared/Contracts/Fiscal/ModuleActivationResolver.php`
- `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (binding diff)
- `apps/api/tests/Feature/Fiscal/ModuleActivationResolverTest.php`

**Reviewer:** Codex (GPT-5.4 via codex-rescue)
**Date:** 2026-05-16

> **Transcribed from inline Codex output** — the sandbox could not write to the fiscal-phase1 worktree, so the parent session transcribed verbatim per handoff §4.2 rule 4.

---

## Verdict

**APPROVE**

---

## Executive Summary

Task 17 delivers a narrow, well-scoped service: a contract interface and a single implementation that delegates to the existing `CompanyConfigService` + `CompanyConfig::hasModule()` surface. The PHP signature matches the §7.3 contract exactly. Token pass-through is unmodified (no trim/lower/upper). Missing tenants return `false` rather than throwing. The provider binding is verified by container resolution in the test. All six tests pass. PHPStan reports zero errors on the reviewed files.

No BLOCKERs, no P1s, no P2s were found. Three minor P3 observations are noted for awareness; none block merge.

---

## Findings

| ID | Severity | Location | Description | Fix |
|----|----------|----------|-------------|-----|
| T17-01 | P3 | `DefaultModuleActivationResolver.php` | The `$companyId` parameter is accepted in the signature but immediately `unset()`. PHPDoc explains it but PHPStan level 8 may warn on "unused parameter" in a stricter future config. | Add `/** @param string $companyId Accepted for contract compatibility; tenant-level cache makes per-company lookup a no-op in Phase 1. See §18. */` or suppress with `@phpstan-ignore-next-line` if it surfaces as a warning. |
| T17-02 | P3 | `ModuleActivationResolverTest.php` | The test does not assert the "any companyId for same tenant" invariant (i.e., calling with `companyId='other-co'` for the same tenant returns the same result). §18 carry-forward says "underlying surface is tenant-level-cached" — a future Task 22 implementor relying on this invariant has no pinned test. (**Reconciliation note:** Codex missed `test_company_id_parameter_is_accepted_in_phase_1_tenant_level_surface` at lines 108-122 of the test file, which already pins exactly this invariant. No action.) | Add a test case: same tenant, different `$companyId`, same expected result. |
| T17-03 | P3 | `CompanyConfigService.php` cache TTL | The 24h TTL on `tenant_config:{$tenant->id}` means a live module-toggle takes up to 24h to propagate. This is pre-existing and documented in `CompanyConfigService`, but `DefaultModuleActivationResolver` does not document the caveat in its PHPDoc, so Task 19 (`OutboxIngestor`) authors won't see it at the call site. | Add a `@see` or inline comment: `// Cache TTL up to 24h — module toggles are not instant. See CompanyConfigService.` |
| T17-04 | CLEAN | All files | Five-BLOCKER PHP cast pattern — no `(type) $array['key']` casts present | — |
| T17-05 | CLEAN | `ModuleActivationResolver.php` | §7.3 signature: `isActive(string $module, string $tenantId, string $companyId): bool` — matches exactly | — |
| T17-06 | CLEAN | `DefaultModuleActivationResolver.php` | Token pass-through: `$module` is passed as-is to `hasModule()` — no trim/lower/upper | — |
| T17-07 | CLEAN | `DefaultModuleActivationResolver.php` | Missing tenant: `Tenant::find()` returns `null` → method returns `false` — no throw | — |
| T17-08 | CLEAN | `CompanyConfigService.php` | Cache key is `tenant_config:{$tenant->id}` — per-tenant, not global; no cross-tenant pollution possible | — |
| T17-09 | CLEAN | `Tenant.php` | `HasUuids` trait is present — `Tenant::find(string $uuid)` works correctly | — |
| T17-10 | CLEAN | `FiscalServiceProvider.php` | Binding pattern matches `HashChainIntegrityProvider` precedent — `$this->app->bind(ModuleActivationResolver::class, DefaultModuleActivationResolver::class)` | — |
| T17-11 | CLEAN | `ModuleActivationResolverTest.php` | Test resolves via `$this->app->make(ModuleActivationResolver::class)` — covers the provider binding | — |
| T17-12 | CLEAN | `CompanyConfig::hasModule()` | Uses strict `in_array($module, $this->modules, true)` — lowercase `'treasury'` is correctly rejected when canonical token is `'Treasury'` | — |
| T17-13 | CLEAN | SoT v3 §13.6/D16 | The bounded-modules asymmetric seam is respected: resolver reads from tenant config, does not write or project | — |

---

## Verified by grep / read

| File | What was verified |
|------|-------------------|
| `apps/api/app/Shared/Contracts/Fiscal/ModuleActivationResolver.php` | Signature matches §7.3; strict `bool` return; no default values |
| `apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php` | Pass-through confirmed; `unset($companyId)` documented; missing-tenant → false path confirmed |
| `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` | Binding present and matches `HashChainIntegrityProvider` pattern |
| `apps/api/tests/Feature/Fiscal/ModuleActivationResolverTest.php` | 6 tests, 8 assertions, all pass; resolves via container |
| `apps/api/app/Services/CompanyConfigService.php` | Cache key `tenant_config:{id}` is per-tenant; 24h TTL confirmed |
| `apps/api/app/DTOs/CompanyConfig.php` | `hasModule()` uses strict `in_array(..., true)` |
| `apps/api/app/Enums/Vertical.php` | PascalCase `defaultModules` confirmed |
| `apps/api/app/Http/Middleware/RequireModule.php` | Reference impl pattern — resolver follows same delegation shape |
| `apps/api/app/Modules/Tenant/Domain/Tenant.php` | `HasUuids` trait present; `Tenant::find(string)` safe |
| `apps/api/bootstrap/providers.php` | `FiscalServiceProvider` registered |
| PHPStan output | Zero errors on reviewed files |
| PHPUnit output | `LOG_CHANNEL=stderr ./vendor/bin/phpunit tests/Feature/Fiscal/ModuleActivationResolverTest.php` — 6 tests, 8 assertions, OK |
