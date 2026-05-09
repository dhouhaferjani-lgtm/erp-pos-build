# api.inventory cluster — Codex round-1 review 2026-05-09

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED

Commit reviewed: 1585720e

YAML state anchor: 0f7f7c72 (claim/start/submit events + atomic-mutation transitions for needs_recheck callsites; metadata-only, not part of the code review).

## Findings

### BLOCKER

None.

The two deviation claims raised for adversarial scrutiny both resolve as safe on close reading:

**Deviation 1 — 017/018: nullable `$expectedCompanyId` with `resolveCompanyId()` fallback**

The private methods `getOrCreateStockLevel()` and `lockStockLevel()` both have a non-nullable `string $expectedCompanyId` signature (confirmed at `StockAdjustmentService.php` lines ~484 and ~508 post-patch). The nullable entry point is the six **public** methods (`receive`, `issue`, `transfer`, `reserve`, `releaseReservation`, `adjust`), each of which resolves the value inline before passing it down: `$expectedCompanyId ?? $this->resolveCompanyId($locationId)`. The fallback path (`resolveCompanyId`) performs an unscoped `Location::findOrFail($locationId)->company_id`. This is not a cross-tenant exfiltration path: `resolveCompanyId` does not select a *different* company — it reads the company from the same `$locationId` the caller already supplied. A cross-tenant attacker who can supply a foreign `$locationId` is stopped at the validator layer before reaching the service. The two known callers that omit `$expectedCompanyId` — `ApplyStockAdjustmentsOnCountingCompleted.php` (line ~74, passes the `locationId` from the counting item) and `BatchWriteOffService.php` (line ~61, passes a controller-resolved `$locationId`) — are both operating in contexts where the `locationId` was already validated to belong to the caller's company upstream. The fallback is therefore an acceptable legacy-compatibility shim, not an isolation gap. The triage said "strict-typed; no nullables unless necessary"; the subagent's judgment that these two listener/service callers constitute a "necessary" carve-out is correct.

**Deviation 2 — 033: `$expectedTenantId` accepted but unused in WHERE (stock_reservations has no tenant_id column)**

Confirmed: `stock_reservations` migration (2025_12_24_133728) defines `company_id` (line 16) but no `tenant_id` column. The `releaseBySource()` WHERE clause scopes by `company_id` only, which is the correct discriminator for this table. Accepting `$expectedTenantId` for API symmetry is a minor smell (see MINOR section) but not a security gap because `company_id` is already tenant-scoped (a company belongs to exactly one tenant). Test `it_scopes_release_by_source_to_caller_tenant_and_company` (line 1377) confirms cross-company release is blocked.

### MINOR

**M1 — 033: vestigial `$expectedTenantId` parameter in `releaseBySource()` and `releaseForWorkOrder()`**
Files: `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php` lines ~245 and ~508; `apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php` (updated in same commit).
`$expectedTenantId` is accepted, forwarded, but never used in a WHERE clause because `stock_reservations` has no `tenant_id` column. This is safe given `company_id` implies tenant, but it is a dead parameter that will mislead future reviewers into thinking tenant_id is enforced at the DB level. Recommendation: add a brief docblock comment in `releaseBySource()` explicitly stating "note: $expectedTenantId is accepted for API symmetry only; stock_reservations is scoped by company_id which is already tenant-unique" — or add a migration to add `tenant_id` to the table (preferred, closes the schema gap for future queries).

**M2 — 017/018 listener callers do not pass `$expectedCompanyId`**
Files: `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php` line ~74; `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php` line ~61.
Both callers omit `$expectedCompanyId`, triggering the `resolveCompanyId()` fallback. This is safe (see BLOCKER section), but it is a follow-through gap: the intent of the sweep is to have every callsite pass the company explicitly. Thread `$counting->company_id` into the listener's `adjust()` call and the batch's `$location->company_id` into `BatchWriteOffService::issue()`. This would also close the `resolveCompanyId()` unscoped `Location::findOrFail` path entirely, which would make the private helper deletable.

**M3 — test coverage: no listener-path or service-layer cross-tenant rejection test**
File: `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`
All 7 new tests exercise the HTTP controller path or verify SQL shape against same-company data. None dispatch a `StockAdjusted`/`InventoryCountingCompleted` event with cross-tenant IDs to verify that the listener + service chain rejects them. Given the listener callers use the `resolveCompanyId()` fallback, a test that supplies a foreign `locationId` in the event payload would confirm the fallback does not silently succeed cross-company. Not a blocking gap today (the HTTP validator stops cross-tenant IDs before events are dispatched), but a valuable regression guard to add.

**M4 — InventoryOpeningService:~220 — known deferral, confirmed present**
File: `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php` line ~220.
Confirmed present and unaddressed. This is the only additional unscoped pattern found in the hostile grep of `app/Modules/Inventory/`. It is the documented deferral from the triage. No new sibling patterns were found. Acceptable as a tracked gap.

## Recommendation

Verdict is APPROVE-WITH-MINOR-EDITS-APPLIED. The 7 callsites are all closed against cross-tenant exfiltration. The two deviation claims are both sound. The main action items before next sweep stage: (a) thread `$expectedCompanyId` into the two listener/service callers that currently use the fallback (M2) — this eliminates the only unscoped Location lookup path and makes `resolveCompanyId()` deletable; (b) add a docblock or migration note for the vestigial `$expectedTenantId` parameter in `StockReservationService` (M1). Neither is a blocker; the implementation can be promoted to `edit_applied` in the YAML inventory once the executor applies M2.
