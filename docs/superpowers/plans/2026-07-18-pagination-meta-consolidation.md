# Offset Pagination Metadata Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every duplicated offset-pagination metadata shape in `apps/web/src` with one exported `OffsetPaginationMeta` contract without changing runtime behavior.

**Architecture:** Define the canonical six-field Laravel offset-pagination contract in `apps/web/src/types/pagination.ts`. Required response envelopes consume it directly; optional envelopes use `Partial<OffsetPaginationMeta>`; metadata with endpoint-specific fields extends or intersects it. A source-architecture Vitest guard prevents the four core fields from being redeclared together outside the canonical file.

**Tech Stack:** TypeScript strict, React 19 source tree, Vitest 3, TypeScript compiler API, ESLint.

**Approved spec:** `docs/handoff/CODEX-replenishment-followups-2026-07-12.md`, Wave B.

## Global Constraints

- Type-only migration: no runtime statements, requests, response transforms, query behavior, components, or rendering changes.
- Canonical fields are `current_page`, `last_page`, `per_page`, `total`, `from`, and `to`; `from` and `to` are `number | null`.
- Cursor pagination (`per_page`, `has_more`, `links.next`, `links.prev`) is a different contract and remains unchanged.
- TDD each task: failing focused test, minimal migration, green verification, commit immediately.
- Never run the full Vitest suite. Run touched feature paths and strict typecheck.
- Keep deprecated local aliases only when an existing feature barrel publicly exports that name.
- No `any`, placeholders, generated-type edits, runtime edits, query-key changes, or fiscal changes.
- Before the Wave B gate: touched-file ESLint, TanStack key audit at zero, React Doctor regression scan, scoped preflight, clean generated-artifact checks.

---

### Task 1: Canonical offset-pagination contract

**Files:**
- Create: `apps/web/src/types/pagination.ts`
- Create: `apps/web/tools/__tests__/offset-pagination-meta-consolidation.test.mjs`

**Interfaces:**
- Produces: `export interface OffsetPaginationMeta { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }`

- [ ] **Step 1: Write the failing canonical-contract test**

Create a Vitest test that resolves `src/types/pagination.ts`, asserts the file exists, parses it with the TypeScript compiler API, and asserts that `OffsetPaginationMeta` declares exactly the six approved properties with `from`/`to` accepting `null`.

- [ ] **Step 2: Verify RED**

Run: `cd apps/web && pnpm vitest run tools/__tests__/offset-pagination-meta-consolidation.test.mjs`

Expected: FAIL because `src/types/pagination.ts` does not exist.

- [ ] **Step 3: Add the minimal canonical interface**

```ts
export interface OffsetPaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}
```

- [ ] **Step 4: Verify GREEN and commit**

Run the focused Vitest file, `pnpm typecheck`, and ESLint on the two files. Commit immediately with a green-cycle message.

---

### Task 2: Migrate the handoff-named feature set

**Files:**
- Modify: `apps/web/tools/__tests__/offset-pagination-meta-consolidation.test.mjs`
- Modify: `apps/web/src/features/admin/api/index.ts`
- Modify: `apps/web/src/features/catalog/types/compositeItem.ts`
- Modify: `apps/web/src/features/channels/types.ts`
- Modify: `apps/web/src/features/compliance/api/complianceApi.ts`
- Modify: `apps/web/src/features/compliance/api/fraudApi.ts`
- Modify: `apps/web/src/features/crm/api/contactApi.ts`
- Modify: `apps/web/src/features/expenses/types/index.ts`
- Modify: `apps/web/src/features/income/types/index.ts`
- Modify: `apps/web/src/features/loyalty/types/loyalty.ts`
- Modify: `apps/web/src/features/replenishment/types/index.ts`
- Modify: `apps/web/src/features/scheduling/types.ts`
- Modify: `apps/web/src/features/stock-transfers/types/index.ts`

**Interfaces:**
- Consumes: `OffsetPaginationMeta` from `@/types/pagination` or the correct relative path.
- Preserves: `AggregateChannelOrdersMeta` and `ReplenishmentPaginationMeta` as deprecated type aliases because their feature barrels export them publicly.

- [ ] **Step 1: Extend the architecture test and verify RED**

Use the TypeScript compiler API to scan the files above for any interface or type literal directly declaring all four core keys (`current_page`, `last_page`, `per_page`, `total`). Exclude only `src/types/pagination.ts`. Assert the result is empty.

Run the focused Vitest file. Expected: FAIL with the duplicate declaration locations.

- [ ] **Step 2: Migrate required metadata**

Use `meta: OffsetPaginationMeta` for ordinary envelopes. For flattened paginators use:

```ts
interface PaginatedResponse<T> extends OffsetPaginationMeta {
  data: T[]
}
```

Preserve the two public compatibility names as:

```ts
/** @deprecated Use OffsetPaginationMeta. */
export type AggregateChannelOrdersMeta = OffsetPaginationMeta
```

and the equivalent `ReplenishmentPaginationMeta` alias.

- [ ] **Step 3: Verify GREEN and commit**

Run the architecture test, strict typecheck, touched-file ESLint, and Vitest paths for admin, catalog, channels, compliance, CRM, expenses, income, loyalty, replenishment, scheduling, and stock transfers. Commit immediately.

---

### Task 3: Migrate all remaining offset-pagination response types

**Files:**
- Modify: `apps/web/tools/__tests__/offset-pagination-meta-consolidation.test.mjs`
- Modify the remaining declarations reported by the guard in:
  - `features/categories`, `coupons`, `customer-history-audit`, `document-ingestions`, `documents`, `enrichment`, `finance`, `import`, `inventory`, `inventory-counting`, `menu`, `notifications`, `opening-balances`, `parapharmacy`, `partners`, `placement`, `pos`, `pricing`, `promotions`, `purchases`, `services`, `settings`, `treasury`, `users`, `vehicles`, `vouchers`, `workshop-bundles`, and `workshop-work-orders`.

**Interfaces:**
- Required metadata: `OffsetPaginationMeta`.
- Optional legacy metadata: `Partial<OffsetPaginationMeta>`.
- Endpoint-specific metadata:

```ts
export interface CustomerHistorySearchMeta extends OffsetPaginationMeta {
  rejected_total: number
}

export interface CashMovementsMeta extends OffsetPaginationMeta {
  totals: Record<string, { in: string; out: string; net: string }>
}
```

- Envelopes with `timestamp`, `request_id`, `job_error_message`, or other endpoint fields use an intersection or a named interface extending the canonical contract; those endpoint fields remain unchanged.

- [ ] **Step 1: Widen the architecture guard and verify RED**

Scan every non-test `.ts`/`.tsx` file beneath `apps/web/src`, including fixtures, while excluding only `src/types/pagination.ts`. Expected: FAIL and report every remaining direct four-key declaration.

- [ ] **Step 2: Replace every remaining duplicate**

Apply direct, partial, extended, or intersection usage according to each existing envelope. Do not alter values, defaults, API calls, destructuring, rendering, or cursor-pagination contracts.

- [ ] **Step 3: Verify GREEN and commit**

Run the architecture test, `pnpm typecheck`, touched-file ESLint, and each touched feature's Vitest path. Confirm the scanner reports zero duplicate declarations, then commit immediately.

---

### Task 4: Wave B gate verification and review handoff

**Files:**
- Modify only files required by failures attributable to Wave B.

- [ ] **Step 1: Run focused verification**

Run the architecture test, all touched feature paths, `pnpm typecheck`, touched-file ESLint, `pnpm audit:keys`, route/generated-artifact checks through scoped preflight, and `npx react-doctor@latest --verbose --diff`.

- [ ] **Step 2: Review scope and compatibility**

Confirm `git diff` contains no runtime expression changes, cursor pagination changes, API payload changes, backend files, generated types, fiscal code, or query-key changes. Confirm public local aliases exist only for names exported through barrels.

- [ ] **Step 3: Squash and hard-stop**

Squash the green-cycle commits into one repository-conventional Wave B commit while preserving the verified tree hash. Output the six-section Wave B gate report with base SHA and stop for external review. Do not merge or push.
