# Phase 0 Audit — Unwired, Precision, Gating, And Status Issues

HEAD: `5cf94a1f04731258704792b7aa07f714e9c718c3` on `fix/balance-ar-event-hardening`.

## Findings

### HIGH — Treasury Allocation Precision Mismatch

`payment_allocations.amount` was created as scale 2, omitted from the later treasury scale-3 widening list, while the model casts it as scale 4.

Evidence:
- Original column: `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:171`.
- Widening list omits `payment_allocations.amount`: `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:106`.
- Model cast: `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php:45`.

### HIGH — Partner Money Fields Are Scale 4 End-To-End

Partner balances and credit limit are scale 4 in schema/casts/validation, while the precision contract expects currency storage at scale 3 unless deliberately exempted.

Evidence:
- Balance fields: `apps/api/database/migrations/tenant/2025_12_06_100001_add_balance_fields_to_partners.php:18`.
- B2B credit limit fields: `apps/api/database/migrations/tenant/2026_03_11_600000_add_b2b_fields_to_partners.php:18`.
- Casts: `apps/api/app/Modules/Partner/Domain/Partner.php:150`.
- Request regex: `apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:79`.

### MEDIUM — Module Gating Is Half-Applied

Service UI routes are guarded by Workshop module gating, but backend service routes lack equivalent module/per-route guards; some direct frontend Workshop routes lack `ModuleGuard` despite backend gating.

Evidence:
- Service routes: `apps/api/app/Modules/Service/Presentation/routes.php:20`.
- Web services route guard: `apps/web/src/routes/index.tsx:1225`.
- WorkOrder backend routes: `apps/api/app/Modules/Workshop/WorkOrder/Presentation/routes.php:26`.
- Direct web route: `apps/web/src/routes/index.tsx:1285`.

### MEDIUM — Magic-String Statuses Remain

Enum-backed paths still contain literal status comparisons.

Evidence:
- `DocumentStatus` enum exists: `apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:7`.
- Literal status in aged receivables: `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:48`.
- Literal payment status: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:418`.
- `OpeningImportRowStatus` enum exists: `apps/api/app/Modules/Accounting/Domain/Enums/OpeningImportRowStatus.php:7`.
- Literal status in opening-balance model: `apps/api/app/Modules/Accounting/Domain/OpeningBalanceBatch.php:217`.
- `PeriodStatus` enum exists: `apps/api/app/Modules/Company/Domain/Enums/PeriodStatus.php:10`.
- Literal fiscal period status: `apps/api/app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php:67`.

### MEDIUM — Aged Receivables Uses Float Math

`AgedReceivablesService` recalculates money through float casts after formatting strings, violating the precision contract.

Evidence: `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:288`.

### LOW — Orphaned Or Weakly Wired Types

`InvoiceConsolidationService` has no references. A small set of DTOs have only self-reference. No never-emitted accounting/partner/document/treasury events were found in this scan.

Evidence:
- `apps/api/app/Modules/Partner/Application/Services/InvoiceConsolidationService.php:11`.
- Examples: `apps/api/app/Modules/Expense/Application/DTOs/ExpenseData.php:28`, `apps/api/app/Modules/Identity/Application/DTOs/LoginData.php:10`, `apps/api/app/Modules/Workshop/WorkOrder/Application/DTOs/PartNeedData.php:15`.

## Acceptance Criteria

- Money columns in partner and treasury allocation paths are consistently scale 3 at schema, casts, validation, DTO/output, and tests, or explicitly documented as an exception.
- Disabled module tenants cannot access gated backend endpoints, and direct frontend routes fail closed via `ModuleGuard`.
- Status comparisons use enum constants/values consistently.
- No float casts are used for money/quantity calculations.
- Unused DTOs/services/events are wired or removed with generated types refreshed when needed.

## Test Plan

- Add PostgreSQL schema tests asserting decimal scales for partner and treasury allocation money columns.
- Add FormRequest tests for 3-decimal money validation.
- Add module-gating backend/frontend tests.
- Add precision tests for aged receivables using 3-decimal TND values.
- Add/static architecture test forbidding literal status comparisons in enum-backed models.

