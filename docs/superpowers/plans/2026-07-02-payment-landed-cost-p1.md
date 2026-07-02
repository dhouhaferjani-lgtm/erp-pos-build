# Payment Landed Cost Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:test-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Phase 1 purchase-side, post-receipt, expense-paid linked landed costs with reversal.

**Architecture:** Extend the Expense document spine with `expense_kind`, create exactly one `DocumentAdditionalCost` link to the resolved purchase order, and apply post-receipt costs at expense post through a linked-cost applicator. The applicator uses shared proportional allocation, splits sold vs on-hand portions, updates WAC with numeric strings, and posts idempotent GL capitalization/reversal entries.

**Tech Stack:** Laravel 12, PostgreSQL tenant migrations, bcmath numeric-string money, React/Vite/Vitest, TanStack Query using `tenantScopedKey`.

## Global Constraints

- Phase 1 only: purchase-side, post-receipt, expense-paid, single-currency, PO-grain linked costs.
- Write failing tests first and run only scoped PHPUnit/Vitest paths.
- Money is strings-only at scale 3 for currency boundaries; no floats.
- Use enums for new classifications and constructor injection for services.
- `OPERATION_PARTIALLY_RECEIVED` and `OPERATION_NOT_RECEIVED` are 422 API errors.
- Reversal is in Phase 1 and must mirror GL, WAC contra, cash inflow, and `DocumentAdditionalCost` reversal row.
- New frontend query keys must use `tenantScopedKey`.

---

### Task 1: Regression Tests

**Files:**
- Create: `apps/api/tests/Unit/Shared/ProportionalMoneyAllocatorTest.php`
- Create: `apps/api/tests/Feature/Expense/LinkedCostExpenseTest.php`
- Create: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx`

- [x] Write tests before production code.
- [ ] Run scoped red tests and capture output.

### Task 2: Backend Implementation

**Files:**
- Create/modify tenant migrations, enums, `DocumentAdditionalCost`, `ExpenseMetadata`, resolver/applicator services, GL service, treasury inflow port, expense routes/controller/request/resource.

- [ ] Add additive schema and partial unique indexes.
- [ ] Add enum casts and validation.
- [ ] Implement purchase resolver and linked-cost applicator.
- [ ] Branch expense post and add reverse endpoint.
- [ ] Filter landed-cost sums to `landed_cost` rows only.

### Task 3: Frontend Implementation

**Files:**
- Modify expense API/hooks/types/form fields and `en/fr/ar` expense locale files.

- [ ] Add resolver API and tenant-scoped query.
- [ ] Add linked/generic toggle, invoice picker, operation chip/downgrade dialog, cost type, split method.
- [ ] Preserve string money payloads.

### Task 4: Verification and Report

**Files:**
- Create: `docs/handoff/CODEX-REPORT-landed-cost-p1.md`

- [ ] Run scoped green tests.
- [ ] Paste red and green outputs, spec-section map, and deviations.
