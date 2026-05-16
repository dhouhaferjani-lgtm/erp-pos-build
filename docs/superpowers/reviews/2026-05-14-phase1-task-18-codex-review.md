# Task 18 — `FiscalEventProjectionRegistry` — Codex Review

**Commit reviewed:** `1151ca94e` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Files under review:**
- `apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php`
- `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (singleton-binding diff)
- `apps/api/tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`

**Reviewer:** Codex (GPT-5.4 via codex-rescue)
**Date:** 2026-05-16

> **Transcribed from inline Codex output** — the sandbox could not write to the fiscal-phase1 worktree, so the parent session transcribed verbatim per handoff §4.2 rule 4.

---

## Verdict

**BLOCK**

One BLOCKER, two P2s. The registry lets `ModuleActivationResolver::isActive()` exceptions escape uncaught, violating spec §7.2's requirement that the fiscal event row is always persisted and the device is never blocked. A DB outage during module-activation lookup would abort the entire projection setup instead of failing the Treasury bridge closed and letting POS-core proceed.

---

## Findings

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | F1 | `FiscalEventProjectionRegistry.php:70-71` | `activeProjectorsFor()` calls `ModuleActivationResolver::isActive()` with no exception guard. A resolver throw (DB outage, connection error) crashes the entire registry call. §7.2 requires the fiscal event row to always be persisted and the device to never be blocked; §7.5 defines projection as downstream/retryable. The resolver must fail-closed: exclude the gated projector and log the failure, never propagate. | Wrap resolver call in try/catch; on exception, exclude the projector (fail-closed) and log. Add a test: POS-core projector + throwing resolver → POS-core is returned, Treasury is excluded. |
| P2 | F2 | `FiscalEventProjectionRegistry.php:65-74` | Duplicate projector registrations yield duplicate entries in the returned list. §7.5 defines `UNIQUE (fiscal_event_id, projector_name)` on projection rows. Duplicate projectors from the registry would cause a DB constraint violation at Task 24's row-insert step, failing projection silently or loudly. | Assert/enforce unique `name()` values during constructor build, or de-duplicate by projector name. Add a targeted test. |
| P2 | F3 | `FiscalEventProjectionRegistry.php:69-71` | `requiresModule() === ''` is not rejected at the registry seam — it is delegated to the resolver. The default resolver routes `''` to `CompanyConfig::hasModule()` which strict-compares; `''` would not match any enabled module. But the registry does not enforce the §7.3 canonical-token contract itself. A future resolver that treats `''` as truthy would silently activate all projectors. | Guard: if `$module !== null && trim($module) === ''`, fail fast (constructor) or exclude with logged violation. |

---

## Probe Results

**PROBE 1 — BOUNDARY WIDENING:** Partial fail (→ F3). `requiresModule() === ''` is delegated to resolver rather than rejected. Silent ineffectiveness on all-false `handlesEventType()` matches §7.3's gate (no bug). Duplicate instances returned twice → F2.

**PROBE 2 — CROSS-TENANT POLLUTION:** PASS — resolver call uses `$event->tenant_id` / `$event->company_id` at lines 70-71; no request-scoped fallback.

**PROBE 3 — NEVER-THROWS CONTRACT:** FAIL → F1. Resolver exceptions propagate uncaught. §7.2 line 380 and §7.5 line 431 both require fail-closed.

**PROBE 4 — SINGLETON ITERATOR HAZARD:** PASS — constructor has array branch + `iterator_to_array($projectors, false)`; provider binds as singleton so tagged iterable materialized once.

**PROBE 5 — LSP / PHP COVARIANCE:** PASS — interface returns `?string`; fakes narrow to `string` (valid covariance). PHPUnit passes.

**PROBE 6 — IMPORT CLEANLINESS:** PASS — registry imports only `FiscalEvent`, `FiscalEventProjector`, `ModuleActivationResolver`. No Treasury/Accounting/Sales imports. §5.0 + §7.3 satisfied.

**PROBE 7 — TEST QUALITY:** PASS with F2 gap. `FakeLowercaseTreasury` exercises strict resolver path. Tenant/company capture test asserts correct arg order. Registration-order test would catch hash-sort reordering. Empty path exercises container. No test for resolver-throws or duplicate projector names.

**PROBE 8 — PROVIDER WIRING:** PASS — `static fn (Application $app)` binding, correct FQCN for tagged services, no conflicting bindings.

**PROBE 9 — FUTURE API SHAPE:** PASS with F2 caveat — `activeProjectorsFor()` returns `list<FiscalEventProjector>` with stable `name()`; supports §7.5 up-front row creation once duplicates are blocked.

**PROBE 10 — ENUM CAST:** PASS — `FiscalEvent.event_type` cast to `FiscalEventType` enum; `handlesEventType()` parameter type is `FiscalEventType`. No implicit cast.

---

## Verified By

- Spec v7 §7.2 lines 349-354, 380: T1 creates projection rows; fiscal row always persisted; device never blocked
- Spec v7 §7.3 lines 386-411: projector/resolver interface contract, canonical PascalCase tokens, no operational-module imports
- Spec v7 §7.5 lines 431-456: projection rows, `UNIQUE (fiscal_event_id, projector_name)`, retry/dead-letter/resume
- Spec v7 §5.0 lines 256-267: asymmetric bounded-modules seam; Fiscal depends only on fiscal contracts
- Plan v4 Task 18 lines 1326-1328: singleton binding, tagged projectors, empty-set test
- Session handoff §4.2/§4.3: prior BLOCKER patterns include defensive never-throws requirement
- SoT v3 §13.6/D16 lines 242-246, 269: permanent bounded-modules guardrail, registry resolved per `(tenant, company)`

---

## Review Notes

- 9 tests, 11 assertions — all green.
- PHPStan blocked in sandbox (TCP listener EPERM); run locally before merging.
- The review file could not be written by Codex (sandbox write restriction). Parent session should transcribe to this path.
