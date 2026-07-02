# Pricing Context Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Phase 2 bulk line-entry pricing context endpoint and surface server-driven pricing intel in `DocumentLineEditor`.

**Architecture:** Keep pricing context under the existing line-entry controller. The frontend fetches one tenant-scoped bulk query for current document lines and shows under-price-field hints plus policy warnings inside the existing unit-price cell.

**Tech Stack:** Laravel 12, PHPUnit feature tests, React, TanStack Query, Vitest, Testing Library.

## Global Constraints

- Do not git commit; orchestrator commits.
- Backend endpoint is `POST /line-entry/pricing-context/bulk`.
- Use `products.last_purchase_cost` as the last purchase cost source.
- Use `products.cost_price` as current WAC.
- Last partner sale comes from `document_lines` joined to `documents`.
- Margin policy comes from `MarginService::canSellAtPrice` for the current user.
- All money crossing the API boundary is string data.
- Any new query keys use `tenantScopedKey`.
- Preserve `DocumentLineEditor` bonus quantity UI and tests.

---

### Task 1: Backend Bulk Endpoint

**Files:**
- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php`
- Modify: `apps/api/app/Modules/Product/routes.php`
- Test: `apps/api/tests/Feature/Product/LineEntryPricingContextTest.php`

**Interfaces:**
- Consumes: `POST /api/v1/line-entry/pricing-context/bulk` body `{ partner_id?: string|null, lines: [{ product_id: string, variant_id?: string|null, unit_price: string }] }`
- Produces: `{ data: { items: Record<string, PricingContextItem> } }`

- [ ] Write the failing feature test for shape, cost sources, last partner sale, policy, and tenant/company scoping.
- [ ] Run `php artisan test tests/Feature/Product/LineEntryPricingContextTest.php` from `apps/api` and capture the red output.
- [ ] Add route and controller method with scoped validation.
- [ ] Run the same path test and capture green output.

### Task 2: Frontend Pricing Intel

**Files:**
- Modify: `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- Modify: `apps/web/src/features/documents/DocumentForm.tsx`
- Modify: `apps/web/src/features/documents/CreateCreditNotePage.tsx`
- Modify: `apps/web/src/locales/en/sales.json`
- Modify: `apps/web/src/locales/fr/sales.json`
- Modify: `apps/web/src/locales/ar/sales.json`
- Test: `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx`

**Interfaces:**
- Consumes: optional `partnerId?: string | null` prop.
- Produces: unit-price cell hint text, popover details, "Use suggested" action, and server-driven margin policy warning/error display.

- [ ] Write failing Vitest coverage for one bulk request, tenant-scoped key use through normal query execution, hint rendering, policy warning, and "Use suggested".
- [ ] Run `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` and capture red output.
- [ ] Implement query, render hints/popover, and pass `partnerId` from parent forms.
- [ ] Run the touched Vitest path and capture green output.

### Task 3: Gates And Report

**Files:**
- Create/Modify: `docs/handoff/CODEX-REPORT-pricing-context.md`

- [ ] Run the full requested gates: touched Vitest plus DocumentLineEditor folder, `tsc`, audit script, phpunit by path, phpstan on changed app files with pipe-safe exit-code handling.
- [ ] Write the red/green command output summary and implementation notes to the handoff report.
