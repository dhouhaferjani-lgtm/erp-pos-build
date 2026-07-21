// Finance / Accounting frontend types.
//
// Source of truth for DTO shapes: apps/api/app/Modules/Accounting/Application/DTOs/**/*.php
// Generated TypeScript namespace: packages/shared/types/generated.ts
// Regenerate with: cd apps/api && php artisan typescript:transform
//
// -----------------------------------------------------------------------
// Task 2.3 status: DRIFT AUDIT ONLY - not yet re-exported from generated.
//
// The intent of Task 2.3 was to replace these hand-written interfaces with
// re-exports of the Laravel-generated `App.Modules.Accounting.*` namespace
// types. That replacement is currently BLOCKED by two upstream issues in
// the generation pipeline, both of which are out of scope for Task 2.3:
//
//   1. Ambient-resolution plumbing: packages/shared/types/generated.ts is
//      emitted as a .ts file using `declare namespace App.*` with no
//      top-level export. apps/web's tsconfig sets
//      `moduleDetection: "force"`, which makes every .ts file a module -
//      so the `App` namespace is module-local, not global. The triple-
//      slash `<reference types="@autoerp/shared/types/generated" />` in
//      `src/vite-env.d.ts` also fails to resolve (traceResolution shows
//      it's looked up as an @types package, which it isn't). Consequence:
//      no file in apps/web currently has `App.*` in scope.
//
//      Fix options (for a follow-up infra task, not 2.3):
//        a. Change the transformer to emit `generated.d.ts` with a
//           `declare global { namespace App { ... } }` wrapper.
//        b. Add a proper `package.json` with a `types` field under
//           `packages/shared/types/` so the type reference resolves.
//        c. Switch generated.ts to top-level `export namespace App { ... }`
//           and import it explicitly everywhere.
//
//   2. Shape drift between the hand-written types below and the DTOs now
//      tagged with #[TypeScript] (see commit bbe61d99). The drift report
//      is documented inline at each type that differs. Once plumbing (1)
//      is fixed, each drift should be resolved by either correcting the
//      DTO (preferred when the API actually returns the hand-written
//      shape) or adjusting the consumers (preferred when the DTO is
//      accurate and the frontend was wrong). Do NOT just re-export; the
//      drifts below are the forensic evidence that silent divergence
//      happened and needs reconciliation.
// -----------------------------------------------------------------------

// ===== Enums ============================================================

// Matches `App.Modules.Accounting.Domain.Enums.AccountType` exactly.
// Safe to re-export once plumbing #1 is fixed.
export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'

// DRIFT: generated `JournalEntryStatus` also includes 'reversed'. The
// frontend has no reversal UI yet, so the narrower union is retained.
// When reversal lands, switch to the generated enum (which lives at
// `App.Modules.Accounting.Domain.Enums.JournalEntryStatus`).
export type JournalEntryStatus = 'draft' | 'posted'

// ===== Accounts =========================================================

// DRIFT: generated `AccountData.type` is `string` (PHP enum transformer
// doesn't emit the union). Hand-rolled keeps the `AccountType` union for
// consumer narrowing. When plumbing (1) is fixed, either widen here or
// tighten the DTO property type to the enum and regenerate.
export interface Account {
  id: string
  tenant_id: string
  parent_id: string | null
  code: string
  name: string
  type: AccountType
  description: string | null
  is_active: boolean
  is_system: boolean
  balance: string
  created_at: string
  updated_at: string
}

// FRONTEND-ONLY: request payload for POST /accounts. No matching PHP DTO.
export interface CreateAccountData {
  code: string
  name: string
  type: AccountType
  description?: string | undefined
  parent_id?: string | undefined
  is_active?: boolean
}

// FRONTEND-ONLY: request payload for PATCH /accounts/{id}.
export interface UpdateAccountData {
  name?: string
  description?: string | null
  is_active?: boolean
}

// FRONTEND-ONLY: query-string filter shape for GET /accounts.
export interface AccountFilters {
  type?: AccountType
  active?: boolean
  search?: string
}

// ===== Journal entries ==================================================

// DRIFT (significant): generated `JournalLineData` uses camelCase
// (`journalEntryId`, `accountId`, `lineOrder`) and is missing
// `account_code` / `account_name` entirely. The API's HTTP response
// clearly serializes in snake_case and includes those fields, so this
// looks like a DTO bug: the `#[TypeScript]` attribute was applied to a
// DTO whose property names don't match what the resource returns.
// Follow-up: align the JournalLineData DTO with the actual JSON
// response before attempting to re-export.
export interface JournalLine {
  id: string
  journal_entry_id: string
  account_id: string
  account_code: string
  account_name: string
  debit: string
  credit: string
  description: string | null
  line_number: number
}

// DRIFT (significant): same story as `JournalLine` - generated
// `JournalEntryData` is camelCase (`entryNumber`, `entryDate`, `sourceType`,
// `sourceId`, `createdAt`, `updatedAt`) and types `lines: Array<any>`.
// Consumers in pages/JournalEntryListPage.tsx and
// pages/JournalEntryDetailPage.tsx rely on snake_case + typed lines.
export interface JournalEntry {
  id: string
  tenant_id: string
  entry_number: string
  entry_date: string
  description: string | null
  status: JournalEntryStatus
  source_type: string | null
  source_id: string | null
  lines: JournalLine[]
  created_at: string
  updated_at: string
}

// ===== General ledger ===================================================

// DRIFT (minor): generated `LedgerLineData` additionally exposes
// `partner_name`, which is not currently surfaced in the UI. Align when
// the partner column is wired up.
export interface LedgerLine {
  id: string
  date: string
  entry_number: string
  description: string
  account_code: string
  account_name: string
  debit: string
  credit: string
  balance: string
  source_type: string | null
  source_id: string | null
}

// FRONTEND-ONLY: query-string filter shape for GET /ledger.
export interface LedgerFilters {
  account_id?: string | undefined
  date_from?: string | undefined
  date_to?: string | undefined
  min_amount?: number | undefined
  max_amount?: number | undefined
}

// ===== Trial balance ====================================================

// DRIFT (minor): generated `TrialBalanceLineData.account_type` is `string`
// (same enum-narrowing gap as `Account.type`). Hand-rolled keeps the
// `AccountType` union.
export interface TrialBalanceLine {
  account_code: string
  account_name: string
  account_type: AccountType
  debit: string
  credit: string
  level: number
  is_parent: boolean
}

// DRIFT (minor): generated `TrialBalanceData.lines` is `any` (collection
// element type was lost by the transformer - a recurring issue across
// all report DTOs). Hand-rolled pins it to `TrialBalanceLine[]`.
export interface TrialBalanceData {
  lines: TrialBalanceLine[]
  total_debit: string
  total_credit: string
  is_balanced: boolean
  as_of_date: string
}

// FRONTEND-ONLY: query-string filter shape.
export interface TrialBalanceFilters {
  as_of_date?: string | undefined
}

// ===== Profit and loss ==================================================

// DRIFT (medium): generated `ProfitLossLineData` includes `account_type`,
// `level`, `is_parent` for hierarchical rendering. The current UI does
// not render a hierarchy, so these are omitted here. If/when P&L gains
// hierarchical rendering (as trial balance has), widen this type.
export interface ProfitLossLine {
  account_code: string
  account_name: string
  amount: string
}

// DRIFT (medium): generated `ProfitLossData` types revenue/expenses as
// `any` (lost element type) and also exposes `date_from` / `date_to`
// which the UI does not consume. Hand-rolled pins the arrays; extra
// fields are ignored.
export interface ProfitLossData {
  revenue: ProfitLossLine[]
  expenses: ProfitLossLine[]
  total_revenue: string
  total_expenses: string
  net_income: string
}

// FRONTEND-ONLY: query-string filter shape.
export interface ProfitLossFilters {
  date_from?: string | undefined
  date_to?: string | undefined
}

// ===== Balance sheet ====================================================

// DRIFT (medium): generated `BalanceSheetLineData` adds `account_type`,
// `level`, `is_parent` (same hierarchical-rendering story as
// `ProfitLossLine`).
export interface BalanceSheetLine {
  account_code: string
  account_name: string
  amount: string
}

// DRIFT (medium): generated `BalanceSheetData` has `assets`, `liabilities`,
// `equity` typed as `any` (lost element type) and adds `retained_earnings`,
// `is_balanced`, `as_of_date`. The current page does not display those
// extras; hand-rolled pins the arrays.
export interface BalanceSheetData {
  assets: BalanceSheetLine[]
  liabilities: BalanceSheetLine[]
  equity: BalanceSheetLine[]
  total_assets: string
  total_liabilities: string
  total_equity: string
}

// FRONTEND-ONLY: query-string filter shape.
export interface BalanceSheetFilters {
  as_of_date?: string | undefined
}

// ===== Aged receivables =================================================

// Matches `App.Modules.Accounting.Application.DTOs.Reports.
// AgedReceivablesLineData` exactly. Safe to re-export verbatim once the
// plumbing described at the top of this file is fixed.
export interface AgedReceivablesLine {
  customer_id: string
  customer_name: string
  current: string
  days_30: string
  days_60: string
  days_90: string
  over_90: string
  total: string
}

// DRIFT (array element only): generated `AgedReceivablesData.lines` is
// `any | Array<any>` (lost element type). All scalar fields match the
// generated DTO exactly.
export interface AgedReceivablesData {
  lines: AgedReceivablesLine[]
  total_current: string
  total_days_30: string
  total_days_60: string
  total_days_90: string
  total_over_90: string
  grand_total: string
  as_of_date: string
  buckets_by_location?: LocationReportBucket[]
}

// FRONTEND-ONLY: query-string filter shape.
export interface AgedReceivablesFilters {
  as_of_date?: string | undefined
  location_ids?: string[]
}

// ===== Aged payables ====================================================

// Matches `App.Modules.Accounting.Application.DTOs.Reports.
// AgedPayablesLineData` exactly. Safe to re-export verbatim once
// plumbing is fixed.
export interface AgedPayablesLine {
  vendor_id: string
  vendor_name: string
  current: string
  days_30: string
  days_60: string
  days_90: string
  over_90: string
  total: string
}

// DRIFT (array element only): generated `AgedPayablesData.lines` is
// `any | Array<any>` (lost element type). Scalar fields match exactly.
export interface AgedPayablesData {
  lines: AgedPayablesLine[]
  total_current: string
  total_days_30: string
  total_days_60: string
  total_days_90: string
  total_over_90: string
  grand_total: string
  as_of_date: string
  buckets_by_location?: LocationReportBucket[]
}

export interface LocationReportBucket {
  location_id: string | null
  location_name: string
  total: string
}

export interface UpcomingLocationBucket {
  location_id: string | null
  location_name: string
  total_in: string
  total_out: string
  net: string
}

export type UpcomingPaymentsData =
  App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentsData & {
    buckets_by_location?: UpcomingLocationBucket[]
  }

// FRONTEND-ONLY: query-string filter shape.
export interface AgedPayablesFilters {
  as_of_date?: string | undefined
  location_ids?: string[]
}

// ===== Finance widget summary ==========================================

// The finance summary shape is now backed by a real PHP DTO
// (App\Modules\Accounting\Application\DTOs\Reports\FinanceSummaryData) exposed
// via the generated global namespace
// `App.Modules.Accounting.Application.DTOs.Reports.FinanceSummaryData`.
// Consume that generated type directly (see `getFinanceSummary` in ./api.ts)
// rather than a hand-written interface.
