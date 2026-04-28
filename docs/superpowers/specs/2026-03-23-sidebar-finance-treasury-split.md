# Sidebar Reorganization: Split Finance & Reports

**Date**: 2026-03-23
**Status**: Approved

## Problem

The current "Finance & Reports" sidebar section has 13 items in a flat list, mixing operational money management (payments, expenses, repositories) with accounting (GL, journal entries) and reporting (P&L, balance sheet, analytics). This makes it hard to find things — the user couldn't locate payment repositories at all.

Industry research shows that SAP Business One, Zoho Books, and Square all separate banking/payments from accounting. "Treasury" is enterprise terminology; SMB ERPs use "Banking" or "Banking & Payments."

## Design

### Sidebar Changes

Replace the single `financeAndReports` group with two groups:

#### 1. Banking & Payments (`bankingAndPayments`)
Icon: `Wallet`
Module filter: `['treasury', 'withholding']`

| Nav Key | Label (EN) | Label (FR) | Route | Module |
|---------|-----------|-----------|-------|--------|
| `payments` | Payments | Paiements | `/treasury/payments` | `treasury` |
| `repositories` | Repositories | Dépôts | `/treasury/repositories` | `treasury` |
| `instruments` | Instruments | Effets de paiement | `/treasury/instruments` | `treasury` |
| `bankReconciliation` | Bank Reconciliation | Rapprochement bancaire | `/treasury/reconciliation` | `treasury` |
| `expenses` | Expenses | Dépenses | `/expenses` | `treasury` |
| `withholdingCertificates` | Withholding Certificates | Certificats de retenue | `/treasury/withholding-certificates` | `withholding` |

#### 2. Accounting & Reports (`accountingAndReports`)
Icon: `Calculator`
Module filter: `['accounts']`

| Nav Key | Label (EN) | Label (FR) | Route | Module |
|---------|-----------|-----------|-------|--------|
| `chartOfAccounts` | Chart of Accounts | Plan comptable | `/finance/chart-of-accounts` | `accounts` |
| `generalLedger` | General Ledger | Grand livre | `/finance/ledger` | `accounts` |
| `journalEntries` | Journal Entries | Écritures comptables | `/finance/journal-entries` | `accounts` |
| `trialBalance` | Trial Balance | Balance de vérification | `/finance/trial-balance` | `accounts` |
| `profitLoss` | Profit & Loss | Compte de résultat | `/finance/profit-loss` | `accounts` |
| `balanceSheet` | Balance Sheet | Bilan | `/finance/balance-sheet` | `accounts` |
| `agedReceivables` | Aged Receivables | Balance âgée clients | `/finance/aged-receivables` | `accounts` |
| `agedPayables` | Aged Payables | Balance âgée fournisseurs | `/finance/aged-payables` | `accounts` |

#### 3. POS Section — Add Analytics Back

Move `analytics` and `zReports` back into the existing `pointOfSale` group (they're POS-specific reports):

| Nav Key | Route | Module |
|---------|-------|--------|
| `analytics` | `/pos/analytics` | `pos` |
| `zReports` | `/pos/z-reports` | `pos` |

### Finance Hub Page

Update `FinanceHubPage` to reflect the new grouping:
- Section 1: "Banking & Payments" — Payments, Repositories, Instruments, Bank Reconciliation, Expenses, Withholding
- Section 2: "Accounting" — Chart of Accounts, General Ledger, Journal Entries
- Section 3: "Reports" — Trial Balance, P&L, Balance Sheet, Aged Receivables, Aged Payables
- Remove POS Analytics and Z-Reports cards (they belong in POS)

### Payment Methods

Payment Methods configuration stays in **Settings** (it's pure config, not an operational page). It's already accessible at `/settings` — no change needed there.

## Files to Modify

1. `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` — replace `financeAndReports` with two groups
2. `apps/web/src/locales/en/common.json` — add `bankingAndPayments`, `accountingAndReports`, `bankReconciliation` nav keys
3. `apps/web/src/locales/fr/common.json` — same translations in French
4. `apps/web/src/features/finance/pages/FinanceHubPage.tsx` — update sections, remove POS cards, add treasury cards

### Onboarding Path Fixes

5 of 6 onboarding `settingsPath()` values in `OnboardingStep.php` point to non-existent routes:

| Step | Current (broken) | Correct |
|------|-----------------|---------|
| Tax Config | `/settings/taxes` | `/settings/tax` |
| Payment Methods | `/settings/payment-methods` | `/treasury/payment-methods` |
| Payment Repositories | `/settings/payment-repositories` | `/treasury/repositories` |
| POS Terminal | `/settings/terminals` | `/pos/terminals` |
| First Product | `/catalog/products` | `/inventory/products` |

Fix: update `OnboardingStep::settingsPath()` in the backend.

## Files to Modify

1. `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` — replace `financeAndReports` with two groups
2. `apps/web/src/locales/en/common.json` — add `bankingAndPayments`, `accountingAndReports`, `bankReconciliation` nav keys
3. `apps/web/src/locales/fr/common.json` — same translations in French
4. `apps/web/src/features/finance/pages/FinanceHubPage.tsx` — update sections, remove POS cards, add treasury cards
5. `apps/api/app/Modules/Tenant/Domain/Enums/OnboardingStep.php` — fix settingsPath() for 5 broken steps

## Out of Scope

- Onboarding global visibility (showing on all pages, not just dashboard) — separate task
- Payment Methods page relocation — already in Settings
- Route changes — all existing routes remain the same
