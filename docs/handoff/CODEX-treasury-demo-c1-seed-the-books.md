# C1 — Seed the Books (Tunisia parapharmacy demo tenant)

> Chunk C1 of the treasury demo plan (`docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md`). Backend seeders only. TDD mandatory: write the failing test FIRST for every task. Never run the full PHPUnit suite — run test files BY PATH only.

## Problem

The demo tenant (`owner@pharmabio.tn`, TND scale 3) has a complete finance backend but nearly-empty books, so P&L / trial balance / expenses / bank reconciliation all look dead:

1. `database/seeders/PaymentRepositorySeeder.php` sets only `gl_account_id` (~line 74), leaving `account_id` NULL on all 6 repositories → `TreasuryAccountPaymentBridge.php:216` and `TreasuryDepositBridge.php:195` throw `payment_repository_missing_account_id`.
2. Zero expense documents are seeded → Expenses list empty, no class-6 cost side on P&L.
3. `DemoPharmacySeeder::seedTunisiaSalesInvoices` (lines ~1105-1344) creates 10 invoices + payments via **raw `Document::create` / `Payment::create`** — no observers exist, so **no journal entries and no treasury balance movement**. The GL contains only the 3 `DEMO-BAL-*` entries from `seedTunisiaBalances` (lines ~560-677) + PO GR-IR entries.
4. No `bank_reconciliations` rows seeded → reconciliation list always empty.
5. AP side is thin: one manual payable partner only.

## Tasks (each = failing test first → implement → green → commit)

### T1 — Fix `account_id` on seeded repositories
In `PaymentRepositorySeeder::seedRepositoriesForCompany`, also set `account_id` to the SAME resolved Account id used for `gl_account_id` (the seeder already resolves cash/bank accounts ~lines 57-58). Test: seeder feature test asserting every seeded repository has non-null `account_id` == `gl_account_id`.

### T2 — `seedTunisiaExpenses()`
New method in `DemoPharmacySeeder`, called inside the `$tenant->run()` closure (after `seedTunisiaSalesInvoices`, ~line 414). Seed ~10 expenses spread over the last 30 days using the REAL service path:
- `$this->container->make(\App\Modules\Expense\...\ExpenseService::class)` — `create([...])` then `post($doc, $owner)`.
- Bind `CompanyContext` first (pattern at `DemoPharmacySeeder.php:820-821`) — currency scale resolution throws without it.
- Use the seeded TN categories (Loyer/Entretien/Assurances/Transport/Télécom/Fournitures — `ExpenseCategorySeeder.php:37-83`), realistic TND amounts as STRINGS (bcmath scale 3), `is_paid=true`, a cash/till repository as `payment_repository_id`.
- `ExpenseService::post` writes the class-6 JE and decrements the repo balance via `RepositoryOutflowService` — do NOT duplicate that logic.
- Idempotency guard: skip if `DEMO-EXP`-pattern expenses already exist (match the existing `seedTunisia*` guard style).
Test: after seeding, expenses exist with status Posted, each has a balanced journal entry, and the till balance decreased by the exact bc-sum.

### T3 — Seeded sales invoices post GL
Make the 10 `seedTunisiaSalesInvoices` invoices produce journal entries so the P&L revenue side is real. Preferred: call `GeneralLedgerService::createFromInvoice` (+ post) for each seeded invoice, and the customer-payment GL path for their payments (this needs T1's `account_id`/`gl_account_id` — verify which column that path reads). Investigate the real B2B posting path and reuse it; only fall back to hand-built balanced JEs (the `seedTunisiaBalances` `createEntry` pattern) if the service path can't be invoked from a seeder cleanly. Keep amounts consistent with the invoice totals (TVA 19% + 1.000 stamp already computed in the seeder). Idempotent (guard on existing JEs for these source ids).
Test: every `DEMO-INV`-pattern invoice has a posted, balanced JE; paid invoices' payments have GL entries; trial balance still balances (debits == credits over all posted entries).

### T4 — Supplier invoices with staggered due dates
Seed 2–3 supplier invoices (AP) against `SUPP-PAYABLE-01` (and optionally a second supplier partner) with due dates staggered over the next 7/15/30 days and `balance_due > 0`, via the real AP path if available (Procurement/Document services) or the documents+JE pattern. Purpose: aged-payables and the upcoming-OUT list show multiple rows.
Test: aged-payables endpoint returns ≥2 suppliers / ≥3 open payables with future due dates.

### T5 — `seedTunisiaBankReconciliation()`
Seed 1 COMPLETED + 1 DRAFT reconciliation on the bank repo `BANK-01` via `BankReconciliationService` (`startReconciliation` at service:38 auto-attaches unreconciled payments for that repo — the seeded AR payments already land on BANK-01 because the payment seeder picks the first repo by code).
- Completed session: match a subset of payments, set `statement_balance` so the difference nets to ZERO, then `completeReconciliation` (marks payments reconciled + stamps the repo).
- Draft session: started later, leave some payments unmatched — the "work to do" story.
- Order matters: run AFTER T3 (payments must exist). Idempotent.
Test: reconciliation list returns 1 completed + 1 draft; completed one has `difference == 0` and its payments `is_reconciled=true`.

## Constraints

- Money: strings + bcmath at scale 3 (TND). NEVER float literals or `(float)` casts on money (rule 19).
- Resolve ALL FKs from the DB (accounts by code/system_purpose, repos by code, partners by code) — never fabricate UUIDs.
- Every new step idempotent — the demo seeder re-runs on the same tenant DB (`run()` re-run branch ~line 361).
- Do not touch production services/controllers — seeders + tests only (T3 may add nothing outside the seeder; if the invoice-posting service needs a tiny seam, STOP and flag it instead).
- Commit per task: `seed(demo): <task>` style, on the current branch.

## Verify

- `cd apps/api && ./vendor/bin/phpstan analyse <changed files>` clean; `./vendor/bin/pint <changed files>`.
- Run ONLY your new/updated test files by path.
- Final smoke (if a local tenant DB is available): re-run `php artisan db:seed --class=DemoPharmacySeeder` twice — second run must be a no-op.
