# Branch (establishment) Tax-ID — Research Doc 06: Current Accounting / Ledger Architecture + Where a Branch Dimension Would Attach

**Date:** 2026-06-04
**Series:** Doc 06 in the branch-tax-id research series (see Docs 01–05 in `docs/superpowers/research/2026-06-04-branch-tax-id-*`).
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id` (`feat/branch-tax-id-spec`).
**Scope:** RESEARCH ONLY. This maps the current accounting/ledger module, every place a sale/invoice/payment posts to the ledger, how reports are scoped, and where a per-branch (establishment) dimension would have to attach. It does **NOT** propose a design and changes no code.

> Context (from Doc 01): the legal entity is `Company`; the physical place is `Location`. France SIRET = SIREN (entity) + NIC (establishment); a per-branch tax ID is an establishment-level number. The accounting question for that work is: **can the books be reported per establishment, and where would a branch sub-account / branch dimension live?**

> **TL;DR headline finding:** The books are **consolidated at the `company_id` level today**, with no working branch dimension in the GL. A `location_id` column **already exists on `journal_entries`** (added 2025-12-27) "for location-based financial reporting" — but **no production code ever writes it**, and **no GL report ever reads it**. Every GL posting method drops the source document's `location_id` at the posting boundary even though it is available (`Document.location_id`, `Receipt.location_id`). Branch-level reporting that exists today is **not** GL-based — it runs off `pos_receipts.location_id` / `stock_levels.location_id` (the "Owner Reporting" surface), and multi-branch consolidation is modelled as **separate `Company` rows linked by `parent_company_id`**, not as one company with many branches.

---

## 1. The Accounting / Ledger module

Module root: `apps/api/app/Modules/Accounting/`. There is no separate `Ledger` or `GL` module — everything lives under `Accounting`. (Voucher GL is in `Accounting\Domain\Services\GeneralLedgerService`, voucher ledger rows in `app/Modules/Voucher/`.)

### 1.1 Core domain tables & models

| Concept | Model | Table | Migration |
|---|---|---|---|
| Chart of accounts | `Accounting\Domain\Account` (`Account.php:48`) | `accounts` | `2025_11_30_090000_create_accounts_table.php` |
| Journal entry (header) | `Accounting\Domain\JournalEntry` (`JournalEntry.php:43`) | `journal_entries` | `2025_11_30_100000_create_journal_entries_table.php` |
| Journal line (double-entry leg) | `Accounting\Domain\JournalLine` (`JournalLine.php:32`) | `journal_lines` | same migration (`:47-59`) |
| Opening balances | `OpeningBalanceBatch`, `OpeningBalanceImportRow` | `opening_balance_batches`, `..._rows` | — |
| Fiscal period | `Company\Domain\FiscalPeriod` (used by `FiscalPeriodResolverService.php:7,59`) | `fiscal_periods` / `fiscal_years` (in Company module) | `2025_12_23_000001_add_fiscal_year_validation_to_companies.php` + `2025_12_11_054620_create_fiscal_metadata_tables.php` |

### 1.2 Account identity & structure

- **Account code:** `accounts.code` `string(20)` (`create_accounts_table.php:17`). Free-form per-company string; e.g. France PCG `'411'` = Clients (`FranceChartOfAccountsSeeder.php:139`), `'4457'` = TVA collectée (`:158`), `'607'` = Achats de marchandises (`:198`).
- **Hierarchy / sub-accounts:** YES — `accounts.parent_id` self-FK (`create_accounts_table.php:16,37-42`; `Account::parent()`/`children()` `Account.php:124-135`). The COA is a true tree (e.g. `411` → parent `41` → parent `4`, `FranceChartOfAccountsSeeder.php:138-141`). Reports build subtotals over this tree via `AccountHierarchyService` (`TrialBalanceService.php:279`, `ProfitLossService.php:296`). **So "sub-account" already means parent/child accounts in one company's COA — this is the natural axis a branch sub-account would extend (e.g. `411-BR1`), NOT a separate dimension column.**
- **Account type:** `AccountType` enum {Asset, Liability, Equity, Revenue, Expense} (`Enums/AccountType.php:9-13`), with normal-balance logic (`:20-26`).
- **System purpose:** `accounts.system_purpose` → `SystemAccountPurpose` enum (`Enums/SystemAccountPurpose.php`), the country-agnostic indirection used by all GL posting instead of hardcoded codes. ~30 purposes (`SystemAccountPurpose.php:17-67`). Looked up by `Account::findByPurposeOrFail($companyId, $purpose)` (`Account.php:252`).
- **Cost centers / analytic / dimensions / segments / tags:** **NONE.** No cost-center, analytic-account, dimension, segment, or tag column anywhere on `accounts`, `journal_entries`, or `journal_lines`. The only "extra dimension" carried on a journal line is `partner_id` (the AR/AP subledger key, §1.4). Confirmed by full grep of the module + migrations: the only hits for `cost_center`/`dimension`/`segment`/`analytic` are zero; the only `location_id` hits in Accounting are in **report** services and the **unused** JE column (§1.5).

### 1.3 Scoping of accounts (today)

`accounts` columns (`create_accounts_table.php` + `2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php`):

| Column | Source | Note |
|---|---|---|
| `tenant_id` | original migration `:15` | tenant DB still carries it redundantly (DB-per-tenant) |
| `company_id` | added `2025_12_06_002513...:16-17` | **the real scope key** — one COA per company |
| `parent_id` | `:16` | hierarchy |
| `code`, `name`, `type`, `system_purpose`, `is_active`, `is_system`, `balance` | — | — |
| **`location_id`** | — | **DOES NOT EXIST on `accounts`.** Accounts are company-wide; there is no per-branch account. |

Uniqueness: originally `(tenant_id, code)`, **changed to `(company_id, code)`** (`2025_12_30_195200_fix_accounts_unique_constraint.php:18-22`) "to allow multiple companies in the same tenant to have accounts with the same code." `system_purpose` is unique per `(company_id, system_purpose)` (`2025_12_06_002513...:55-58`). **There is exactly one account per purpose per company — a branch cannot today have its own AR/revenue account distinguished by purpose lookup.**

### 1.4 Scoping of journal entries & lines (today)

`journal_entries` columns:

| Column | Source | Scope role |
|---|---|---|
| `tenant_id` | `create_journal_entries_table.php:15` | tenant |
| `company_id` | added `2025_11_30_130000_add_company_id_to_existing_tables.php`, made required `2025_11_30_134000_make_company_id_required.php` | **the books scope key** |
| **`location_id`** | added `2025_12_27_150002_add_location_id_to_journal_entries_table.php:25` (nullable, FK → `locations`, index `journal_entries_company_location_index`) | **EXISTS but is dead — see §1.5** |
| `entry_number`, `entry_date`, `description`, `status` | — | — |
| `source_type`, `source_id` | `:22-23` | polymorphic link back to Document/Receipt/etc. |
| `fiscal_hash`, `previous_hash`, `chain_sequence` | hash-chain migration `2025_12_26_111230...` | **per-company GL hash chain** (`JournalEntry::getNextChainSequence($companyId)` `JournalEntry.php:136`; chain index `(company_id, chain_sequence)` `2025_12_26_111230...:43`) |
| `posted_at/by`, `reversed_at/by`, `reversal_entry_id` | — | audit |

`JournalEntry` model fillable/casts: `JournalEntry.php:49-82`. Note `location_id` is **NOT in `$fillable`** (`:49-67`) — so even a `JournalEntry::create([... 'location_id' => x])` would be silently dropped by mass-assignment guarding. The model has no `location()` relation and no `forLocation()` scope (only `forCompany()` `:114`).

`journal_lines` columns (`create_journal_entries_table.php:47-59` + `2025_12_06_100000_add_partner_id_to_journal_lines.php`):

| Column | Note |
|---|---|
| `journal_entry_id` (FK), `account_id` (FK) | — |
| **`partner_id`** | nullable FK → `partners` (`add_partner_id...:16`); the AR/AP subledger dimension; indexed `(partner_id, account_id)` & `(account_id, partner_id)` (`:25-28`) |
| `debit`, `credit` (`decimal:3` cast `JournalLine.php:53-54`), `description`, `line_order` | — |
| **`location_id`** | **DOES NOT EXIST on `journal_lines`.** No per-line branch dimension. |

**So the only journal-line dimension today is `partner_id`** (customer/supplier). A branch dimension at the line level (the standard ERP "analytic dimension on each posting line") does not exist.

### 1.5 The dead `location_id` on `journal_entries` (critical finding)

`2025_12_27_150002_add_location_id_to_journal_entries_table.php` adds the column with an explicit docblock promising "Location-specific P&L statements / Location-based balance sheets / Inter-location transfer accounting / Multi-location consolidation reporting" (`:14-19`). **None of that is wired:**

- **No writer.** Grep of all `JournalEntry::create([...])` / `JournalLine::create([...])` call sites (full file list: `AccountingService.php`, `GeneralLedgerService.php`, `AccountingOpeningService.php`, the Treasury bridges, Voucher, Compliance, etc.) shows **zero** assignments of `'location_id'` on a journal entry. `location_id` is also absent from `JournalEntry::$fillable` (`JournalEntry.php:49-67`), so it could not be set via `create()` even if a caller tried.
- **No reader.** `TrialBalanceService`, `ProfitLossService`, `BalanceSheetService`, `GeneralLedgerReportService` never reference `je.location_id`. Every report joins/filters on `company_id` only (§3).
- The only `location_id` usages inside the Accounting module are in the **Owner Reporting** services, which read it from `pos_receipts` / `stock_levels` / `pos_terminals`, **not** from `journal_entries` (`SalesReportService.php:33-35`, `StockAlertReportService.php:29-31`, `CashRegisterReportService.php:32-35`).

The column is therefore a **latent, unused hook**. A branch-accounting effort could adopt it, but must (a) add it to `$fillable`, (b) write it at every posting site, (c) add it to `journal_lines` too if line-level analytics are wanted, and (d) teach the reports to group by it. The GL hash does **not** include it (§5.3), so backfilling/adding it is non-breaking to the fiscal chain.

---

## 2. How transactions post to the ledger

There are **two** invoice-posting code paths in the module — a duplication worth flagging:

- `AccountingService::createInvoiceGLEntries(Document)` (`AccountingService.php:104`) — the **wired** B2B path (called by the event listener), creates a **Posted** entry and writes the per-company hash chain inline (`:108-123, 184-191`).
- `GeneralLedgerService::createFromInvoice(Document, User)` (`GeneralLedgerService.php:57`) — a parallel implementation that creates a **Draft** entry (no inline hash), posted later via `postEntry()` (`:1094`). Used by tests (`tests/Feature/Accounting/GLIntegrationTest.php`) and is the home of all the POS/payment/COGS/expense/voucher methods. **Both write `company_id` only; neither writes `location_id`.**

### 2.1 B2B invoice → ledger (the main sales flow)

Trigger chain:
1. Document posting dispatches `Document\Domain\Events\InvoicePosted` (payload: `invoiceId, tenantId, companyId, documentNumber, documentType, partnerId, total, currency, fiscalHash, chainSequence, postedAt` — `InvoicePosted.php:19-30`). **No `locationId` in the event.**
2. `Accounting\Listeners\InvoicePostedListener::handle()` (`InvoicePostedListener.php:18-34`) reloads the Document and calls `createCreditNoteGLEntries()` for credit notes, else `createInvoiceGLEntries()`.
3. `createInvoiceGLEntries(Document $invoice)` (`AccountingService.php:104-208`) posts:
   - Dr **CustomerReceivable** (full `$invoice->total`) — `:144-150`
   - Cr **ProductRevenue / ServiceRevenue** per line (`getRevenueAccountForLine` `:351`) — `:161-168`
   - Cr **VatCollected** grouped by rate — `:171-182`
   - All accounts resolved by `findAccountByPurpose($invoice->company_id, …)` (`:331`) — **company-scoped**.
   - Entry written with `company_id` (`:114`) and `chain_sequence`/`previous_hash` (`:121-122`). **`location_id` is NOT set**, even though `$invoice->location_id` exists (`Document.php:115`).

Credit notes: `createCreditNoteGLEntries()` (`:220-324`) — mirror reversal, same company-only scoping.

### 2.2 POS sale (B2C) → ledger

POS sales post **direct-to-revenue (no AR, no partner)**. Path:
1. The fiscal-event bridge `Treasury\Application\Projections\TreasuryReceiptBridge` (`:418`) calls `GeneralLedgerService::createPOSPaymentEntry(Payment, Receipt, PaymentRepository)` then `postEntry()` (`:431`) immediately (POS is direct-to-revenue, posted on the spot, not draft).
   - (Legacy path: `POS\Application\Services\ReceiptPaymentService.php:303` calls the same method.)
2. `createPOSPaymentEntry()` (`GeneralLedgerService.php:1210-1269`):
   - Dr **payment repository's `gl_account_id`** (cash/bank) — `:1244-1252`. Requires `repository->gl_account_id` (throws if null `:1215`).
   - Cr **ProductRevenue** (`getAccountByPurpose($companyId, ProductRevenue)` `:1227`) — `:1255-1263`.
   - Entry `company_id` only (`:1234`). **`Receipt.location_id` (`Receipt.php:39,137`) is available but dropped.** No VAT line on the POS payment entry (VAT handling for POS is in the fiscal-event/receipt projection, not this GL leg).
3. **POS account charge (B2B-on-account at POS):** `Treasury\Application\Projections\TreasuryAccountChargeBridge.php:80` builds a `CreatePOSChargeJournalEntryCommand` and calls `createPOSChargeEntry()` (`GeneralLedgerService.php:1277-1369`): Dr CustomerReceivable (with `partner_id`), optional Dr SalesDiscount, Cr ProductRevenue, optional Cr VatCollected. The command DTO (`CreatePOSChargeJournalEntryCommand.php:17-33`) carries `tenantId, companyId, partnerId, fiscalEventId, …` — **no `locationId` field** — so even the DTO would need extending.
4. **Z-report:** `fiscal_events` table (`2026_05_14_100001_create_fiscal_events_table.php`) has **no `location_id` column**. POS posting is effectively per-terminal/per-receipt at source but **per-company in the GL**.

### 2.3 Payments / refunds → ledger

In `GeneralLedgerService` (all `company_id`-only, all carry `partner_id` on the AR/AP leg, none carry location):
- `createPaymentReceivedJournalEntry()` `:554` — Dr Cash/Bank, Cr AR(partner).
- `createSupplierPaymentJournalEntry()` `:487` — Dr AP(partner), Cr Cash/Bank.
- `createCustomerAdvanceJournalEntry()` `:267`, `clearCustomerAdvanceToReceivable()` `:727`, `reverseSupplierAdvanceJournalEntry()` `:334`.
- `createPaymentToleranceJournalEntry()` `:627` and POS variant `createPOSPaymentToleranceEntry()` `:1148`.
- Refunds: `Document\Domain\Services\RefundService.php` is in the JE-creating set; vouchers via `createVoucherLedgerEntry()` `:921` (Voucher liability legs).

### 2.4 Inventory / COGS (WAC) → ledger

- Trigger: `Inventory\Listeners\PostCOGSOnInvoice::handle()` (`PostCOGSOnInvoice.php:73`) calls `GeneralLedgerService::createCOGSEntry(companyId, invoiceId, documentNumber, lineItems, date)` (`GeneralLedgerService.php:799`).
- Posts Dr **CostOfGoodsSold**, Cr **Inventory** for `Σ qty×WAC` (rounded once at boundary `:828-829`). Entry `company_id` only (`:849-857`). **The COGS call signature has no location parameter**, and the inbound event/Document `location_id` is not threaded through.
- Inventory write-off: `createInventoryWriteOffEntry()` `:1459` — Dr COGS, Cr Inventory, company-only.
- **Note on inventory itself:** stock IS per-location (`stock_levels.location_id`) and WAC is company-wide-per-product (see MEMORY `project_inventory_costing.md`), but the **GL Inventory account is a single company-wide `37`-type account** — there is no per-branch inventory sub-account, so the asset side of stock cannot be split by branch in the books today.

### 2.5 Posting-site scope summary

| Posting method | File:line | Source has location? | Writes `company_id` | Writes `location_id` | Subledger dim |
|---|---|---|---|---|---|
| `createInvoiceGLEntries` | `AccountingService.php:104` | `Document.location_id` ✓ | ✓ | ✗ (dropped) | partner on AR |
| `createCreditNoteGLEntries` | `AccountingService.php:220` | `Document.location_id` ✓ | ✓ | ✗ | partner on AR |
| `createFromInvoice` (alt) | `GeneralLedgerService.php:57` | `Document.location_id` ✓ | ✓ | ✗ | partner on AR |
| `createPOSPaymentEntry` | `GeneralLedgerService.php:1210` | `Receipt.location_id` ✓ | ✓ | ✗ | none |
| `createPOSChargeEntry` | `GeneralLedgerService.php:1277` | command DTO has none | ✓ | ✗ | partner on AR |
| `createCOGSEntry` | `GeneralLedgerService.php:799` | event/doc location not threaded | ✓ | ✗ | none |
| `createFromExpense` | `GeneralLedgerService.php:1378` | `Document.location_id` ✓ | ✓ | ✗ | none |
| payments / advances / tolerance / voucher / write-off | `GeneralLedgerService.php:267–1517` | varies | ✓ | ✗ | partner on AR/AP legs |

**Every row: `location_id` is dropped at the posting boundary.** The seam is uniformly "source → `JournalEntry::create()` keeps only `company_id`."

---

## 3. Reporting / consolidation

### 3.1 GL financial reports — all company-scoped, no location filter

| Report | Service | Scope | Location filter? |
|---|---|---|---|
| Trial balance | `TrialBalanceService::generate(companyId, asOfDate, …)` (`TrialBalanceService.php:107`) | `a.company_id` (`:191`) + posted JEs | **NO** |
| Profit & Loss | `ProfitLossService::generate(companyId, from, to, …)` (`ProfitLossService.php:135`) | `a.company_id` (`:232`) | **NO** |
| Balance sheet | `BalanceSheetService` (`:276` `a.company_id = $companyId`) | company | **NO** |
| General ledger | `GeneralLedgerReportService::generate(companyId, accountId?, from?, to?, partnerId?, …)` (`GeneralLedgerReportService.php:112`) | `je.company_id` (`:207,379`) + optional `account_id` + optional `partner_id` | **NO** (partner yes, location no) |
| Aged receivables/payables | `AgedReceivablesService` / `AgedPayablesService` | company + partner subledger | **NO** |
| Partner statement / balance | `PartnerBalanceService` | company + partner | **NO** |

The report SQL aggregates `journal_lines` joined to `journal_entries` filtered by `je.company_id` and `je.status = 'posted'` (e.g. `TrialBalanceService.php:182-205`, `GeneralLedgerReportService.php:204-208`). Because the lines carry no `location_id` and the report never reads `je.location_id`, **there is no way to produce a per-branch trial balance / P&L / balance sheet from the GL today.** The only line-level breakdown axis available is `partner_id` (subledger), used for AR/AP statements.

Routes confirm the surface: `apps/api/app/Modules/Accounting/Presentation/routes.php:162-180` exposes `reports/trial-balance`, `profit-loss`, `balance-sheet`, `aged-*` (perm `reports.view`) — none take a `location` parameter (`GetTrialBalanceRequest`, `GetProfitLossRequest`, `GetBalanceSheetRequest` have no location field).

### 3.2 The "Owner Reporting" reports DO use location — but they bypass the GL

`routes.php:182-205` exposes a separate `reports/sales/by-location`, `top-skus`, `revenue-by-category`, `payment-method-breakdown`, `stock/alerts`, `cash-register/reconciliation` (perm `dashboard.owner`). These run off operational tables, not the ledger:
- `SalesReportService` groups by `pos_receipts.location_id` (`SalesReportService.php:33-35, 56`).
- `StockAlertReportService` joins `stock_levels.location_id` (`:29-31`).
- `CashRegisterReportService` joins `pos_terminals.location_id` (`:32-35`).
- Authorization is location-aware via `OwnerReportScope` (`OwnerReportScope.php`), which derives allowed locations from `UserCompanyMembership.allowed_location_ids` (`:62-99,81`).

**Conclusion:** today the system answers "sales by branch" from POS receipts, not from accounting. There is **no financial-statement-grade per-branch view** (a branch P&L that ties to the GL).

### 3.3 Consolidation model: branches as separate Companies

`OwnerReportScope::companyIds()` (`OwnerReportScope.php:34-40`) builds the allowed set as the current company **plus every `Company` whose `parent_company_id` = that company**. `Company` carries `parent_company_id` (`Company.php:92,249,334-344`; migration `create_companies_table.php:93,114`) and `parent()`/`children()` relations. **So the current "multi-branch consolidation" idiom is: one parent `Company` with child `Company` rows (each its own COA, its own GL hash chain, its own books), aggregated at the reporting layer.** This is the alternative to a single-company-with-branch-dimension model and is a live tension the spec must resolve (§6).

Within a single company, the books are **consolidated** — one COA, one hash chain, one set of statements per `company_id`. **Confirmed.**

---

## 4. Chart of accounts seeding / templates

- Entry point: `ChartOfAccountsService::seedForCompany(Company)` (`ChartOfAccountsService.php:32-40`), called for a new company. Selects a seeder by `country_code` (`getSeederForCountry()` `:141-148`):
  - `'TN'` → `TunisiaChartOfAccountsSeeder`
  - `'FR'` → `FranceChartOfAccountsSeeder`
  - default → `GenericChartOfAccountsSeeder`
  - Supported (dedicated) countries: `['TN','FR']` (`getSupportedCountries()` `:158-161`).
- Wired into company creation: `ChartOfAccountsService` is invoked from company/registration flow (`CompanyController`, `Identity\...\AuthController`, `CompanyCreated` event — per grep in §4 search). Seeders accept `(companyId, tenantId)` and `INSERT` directly into `accounts` (`FranceChartOfAccountsSeeder::run` `:28-74`).
- **Account codes come from the seeders** as hardcoded country plans:
  - France = **PCG (Plan Comptable Général)**, PCG 2014 / ANC (`FranceChartOfAccountsSeeder.php:12-19`). Classes 1–7, e.g. `411` Clients (`:139`), `4457` TVA collectée (`:158`), `512` Banques (`:185`), `607` Achats de marchandises (`:198`). Two-pass insert: create all, then link `parent_id` (`:36-65`).
  - Tunisia = `TunisiaChartOfAccountsSeeder` (Tunisian plan; also `TunisianParapharmacySeeder` for demo).
  - Generic = `GenericChartOfAccountsSeeder` (international fallback).
- `system_purpose` values are stamped on the key accounts in the seeder rows (e.g. `:97,123,131,140,156,159,186,188,199`), so country-agnostic GL lookups resolve.
- **Relevance to branch work:** per-branch sub-accounts would extend these seeded codes (e.g. add `411-<branch>` children under `411`, or branch-suffixed revenue accounts). Because the COA is a per-company tree keyed `(company_id, code)`, branch sub-accounts would be **new `accounts` rows with `parent_id` pointing at the seeded parent** — but there is no current generator for them and no per-branch seeding step.

---

## 5. The B2B sales flow surface (modules → accounting) + every branch seam

### 5.1 Module chain for a B2B invoice

`Document` (invoice, `app/Modules/Document/`) → `InvoicePosted` event → `Accounting\Listeners\InvoicePostedListener` → `AccountingService::createInvoiceGLEntries()` → `journal_entries`/`journal_lines`. Side flows: `Inventory\Listeners\PostCOGSOnInvoice` (COGS), `Treasury` (payments/allocations → `GeneralLedgerService::createPaymentReceivedJournalEntry`), `Taxation` (tax rates feed `tax_rate` on `DocumentLine`, read by `groupTaxByRate` `AccountingService.php:379`), `Partner` (the `partner_id` subledger key).

### 5.2 Where the branch (`location_id`) would have to flow

The source already knows the branch: `Document.location_id` (`Document.php:115`, `location()` relation `:202`) and `Receipt.location_id` (`Receipt.php:39,137`). The chain of seams where it is currently lost:

| Seam | File:line | Today | Branch-work implication |
|---|---|---|---|
| Document → event | `InvoicePosted.php:19-30` | event has `companyId`, **no `locationId`** | event payload would need `locationId` (events are immutable → `InvoicePostedV2`, per CLAUDE rule #8) |
| Listener → service | `InvoicePostedListener.php:21,30-32` | reloads Document (so `location_id` is reachable even without changing the event) | service could read `$document->location_id` directly |
| Service → JE row | `AccountingService.php:112-123` | writes `company_id`, not `location_id`; `location_id` not in `$fillable` | add to `$fillable` + write it |
| JE row → lines | journal line creators | no line-level `location_id` column | decide entry-level vs line-level dimension |
| COGS | `PostCOGSOnInvoice.php:73` + `createCOGSEntry` sig `GeneralLedgerService.php:799` | no location param | thread location through event + signature |
| POS payment | `TreasuryReceiptBridge.php:418` + `createPOSPaymentEntry` `:1210` | `Receipt.location_id` dropped | pass receipt location |
| POS charge | `TreasuryAccountChargeBridge.php:80` + DTO `CreatePOSChargeJournalEntryCommand.php` | DTO lacks `locationId` | extend DTO |
| Reports | `TrialBalanceService.php:191`, `ProfitLossService.php:232`, `GeneralLedgerReportService.php:207` | filter `company_id` only | add `location_id` group/filter (and a join, since lines have no location) |
| Account resolution | `Account::findByPurposeOrFail($companyId, $purpose)` (`Account.php:252`) | one account per purpose per company | if branch sub-accounts: a per-branch purpose-resolution variant |

### 5.3 Hash-chain safety note (important for any schema change)

The GL fiscal hash input is `entry_number | entry_date | company_id | total_debit | total_credit` (`GeneralLedgerHashService.php:54,71-73`) — and the alternate inline hash in `GeneralLedgerService::calculateHash()` uses `entry_number, entry_date, description, lines[account_id,debit,credit]` (`:1559-1571`). **Neither includes `location_id`.** Therefore adding a `location_id` value to `journal_entries` (or backfilling the existing column) does **not** alter any existing hash and does **not** break `verifyChain()` (`GeneralLedgerHashService.php:85`). This is a meaningful de-risking fact: the branch dimension can be added to the GL as a **reporting attribute** without touching the immutable chain — *provided* the spec deliberately keeps it out of the hashed payload (contrast with the POS canonical `seller.tax_number`, which IS hashed — see Doc 02).

---

## 6. Open questions for the spec (where/how a branch/establishment dimension attaches to accounting)

1. **Sub-account vs dimension — the fork.** Two incompatible models exist in embryo:
   - (a) **Branch sub-accounts** — extend the COA tree with branch-suffixed children under each relevant account (e.g. `411-BR1`, `707-BR1`), keyed by `accounts.parent_id`. Pros: works with existing hierarchy reports unchanged; cons: COA explosion (N accounts × M branches), and purpose-resolution (`findByPurposeOrFail`, one account per `(company_id, purpose)`) breaks because a purpose would map to many accounts.
   - (b) **Branch dimension column** — populate the **already-existing-but-dead `journal_entries.location_id`** (and likely a new `journal_lines.location_id`), keep one COA, and group reports by it. Pros: clean, matches the dead-column's stated intent; cons: requires writing at every posting site + new report grouping + a decision on entry-level vs line-level.
   Which model? (Doc 01/05 lean toward `locations` as the establishment; (b) aligns with that.)

2. **Single company + branch dimension, OR keep modelling branches as child `Company` rows?** Today consolidation = `parent_company_id` tree of separate companies, each with its own COA + GL hash chain + statements (`OwnerReportScope.php:34-40`, `Company.php:334-344`). A branch tax-ID feature could either (i) stay in that model (each establishment = a child Company with its own books and its own SIRET on `companies.tax_id`) or (ii) move to one Company with a branch dimension. These imply very different accounting work. **This is the load-bearing decision.** (Note Doc 01: the per-branch tax-ID is naturally a `locations.tax_id` override under one Company — which pushes toward (ii) and conflicts with the current child-Company idiom.)

3. **Entry-level or line-level branch?** `journal_entries.location_id` exists (entry-level). Standard analytic accounting puts the dimension on each **line** (so one entry can split across branches, e.g. an inter-branch transfer). Does branch reporting need single-branch entries (entry-level is enough) or split entries (line-level required → new `journal_lines.location_id`)?

4. **Per-branch GL hash chain, or keep one chain per company?** The chain is per-`company_id` (`JournalEntry::getNextChainSequence($companyId)` `:136`, chain index `(company_id, chain_sequence)`). If a branch needs an independently sealable/auditable ledger (some fiscal regimes want per-establishment immutability), the chain seed/sequence model would need a branch axis. If branch is only a reporting attribute, the single per-company chain stands (and §5.3 keeps it non-breaking).

5. **Inventory asset by branch?** Stock quantities are per-location but the GL `Inventory` account is one company-wide account, and WAC is company-wide-per-product. A branch balance sheet would need a per-branch inventory asset figure that the current single Inventory account + company WAC cannot produce. Is a branch-split inventory asset in scope?

6. **Purpose resolution under branch sub-accounts.** `findByPurposeOrFail($companyId, $purpose)` assumes one account per purpose per company (`Account.php:252`; unique `(company_id, system_purpose)`). If model (a) is chosen, this contract must become `(companyId, locationId, purpose)` — a wide ripple across `GeneralLedgerService` (every `getAccountByPurpose` call).

7. **POS canonical payload coupling.** POS posting derives from `fiscal_events` (no location column there) and the device-authoritative canonical `seller` block (hashed — Doc 02). If branch accounting must tie to the legally-signed receipt's establishment, the branch must be present in the canonical payload AND in the GL — two sources that must agree. Does the GL branch come from `Receipt.location_id` (server-side) or must it match the hashed `seller`?

8. **Should the dead `journal_entries.location_id` be adopted or dropped?** It was added 2025-12-27 with consolidation intent but never wired. The spec should explicitly decide: adopt it (and add it to `$fillable` + writers + readers + a `journal_lines` counterpart), or remove it to avoid a misleading half-feature.

9. **Reporting tie-out.** A branch P&L/balance-sheet must still consolidate exactly to the company statements (debits=credits per branch AND in aggregate). The current reports validate `total_debit == total_credit` company-wide (`TrialBalanceService.php:428`); a branch view needs per-branch balancing — but inter-branch transfers and shared/company-level entries (e.g. depreciation with no branch) make per-branch balance non-trivial. How are company-level (no-branch) entries presented in a branch report?

---

## Appendix: files cited

- Domain: `Account.php`, `JournalEntry.php`, `JournalLine.php`, `JournalEntryBuilder.php`, `Enums/AccountType.php`, `Enums/SystemAccountPurpose.php`, `Domain/DTOs/CreatePOSChargeJournalEntryCommand.php`.
- Services: `Application/Services/AccountingService.php`, `Application/Services/ChartOfAccountsService.php`, `Application/Services/GeneralLedgerHashService.php`, `Application/Services/FiscalPeriodResolverService.php`, `Domain/Services/GeneralLedgerService.php`, `Application/Services/Reports/{TrialBalanceService,ProfitLossService,BalanceSheetService,GeneralLedgerReportService,OwnerReportScope,SalesReportService,StockAlertReportService,CashRegisterReportService}.php`.
- Listeners/bridges: `Accounting/Listeners/InvoicePostedListener.php`, `Inventory/Listeners/PostCOGSOnInvoice.php`, `Treasury/Application/Projections/{TreasuryReceiptBridge,TreasuryAccountChargeBridge}.php`.
- Events/models elsewhere: `Document/Domain/Document.php`, `Document/Domain/Events/InvoicePosted.php`, `POS/Domain/Receipt.php`, `Company/Domain/Company.php`.
- Migrations (tenant): `2025_11_30_090000_create_accounts_table.php`, `2025_11_30_100000_create_journal_entries_table.php`, `2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php`, `2025_12_06_100000_add_partner_id_to_journal_lines.php`, `2025_12_26_111230_update_journal_entries_hash_chain_for_compliance.php`, `2025_12_27_150002_add_location_id_to_journal_entries_table.php`, `2025_12_30_195200_fix_accounts_unique_constraint.php`, `2026_05_14_100001_create_fiscal_events_table.php`.
- Seeders: `FranceChartOfAccountsSeeder.php`, `TunisiaChartOfAccountsSeeder.php`, `GenericChartOfAccountsSeeder.php`.
- Routes: `Accounting/Presentation/routes.php`.
