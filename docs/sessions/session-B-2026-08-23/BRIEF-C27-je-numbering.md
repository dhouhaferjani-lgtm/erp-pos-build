# BRIEF — Lane B2-1 / C-27: journal-entry + income numbering, tenant-scoped + tenant-locked

Follow `LANE-PROTOCOL.md` verbatim. Worktree ALREADY CREATED for you (vendor copied, `.env` copied,
resolution verified):

- worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c27-je-numbering`
- branch: `fix/sb2-c27-je-numbering-tenant-scope` (base = dev `834c8c017`)
- PG for tests: host 127.0.0.1 port 5433, user `autoerp` / `autoerp_secret`; create your OWN throwaway
  DB named `autoerp_c27_test` (`createdb`/`dropdb` via `PGPASSWORD=autoerp_secret psql -h 127.0.0.1 -p 5433 -U autoerp`).
  Run PG tests with `DB_CONNECTION=pgsql DB_PORT=5433 DB_DATABASE=autoerp_c27_test …` (look at how
  `tests/Feature/Expense/ExpensePostTest.php::test_expense_number_allocation_takes_the_tenant_advisory_lock`
  skips on non-PG and mirror its env expectations).
- Machine rules: tool calls < 90 s, ONE test file per run, tests BY PATH only, never the full suite,
  no `git stash`, never push. Other sessions run tests on this laptop concurrently — do not be surprised
  by load.

## The defect (LEDGER C-27, found by the Q-11 treasury gate, reproduced on PG)

`journal_entries` has ONE unique index: `(tenant_id, entry_number)`
(`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:41`). But
`GeneralLedgerService::generateEntryNumber(string $companyId)`
(`app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5597-5623`) advisory-locks on the raw
`$companyId` and scans `->where('company_id', $companyId)`. In a tenant with two companies, company B's
first JE is `JE-YYYY-000001` = company A's → `SQLSTATE 23505` → 500 on EVERY JE-minting flow
(43 call sites in that file: `grep -n 'generateEntryNumber(' …`). Company B is GL-dead for the year.

`IncomeService::generateIncomeNumber(string $companyId)`
(`app/Modules/Income/Application/Services/IncomeService.php:206-224`) has the IDENTICAL bug against
`documents_tenant_id_type_document_number_unique` (`(tenant_id, type, document_number)`), and additionally
has NO lock at all.

`JournalEntryController::generateEntryNumber(string $tenantId)`
(`app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:187-204`, manual JE) already
scans tenant-wide but takes NO lock — it races the service minter on the same prefix.

## The fix shape — copy Q-11's `ExpenseService::generateExpenseNumber` (commit `1f76745e8`) EXACTLY

```php
private function generateExpenseNumber(string $tenantId): string
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["expense_number:{$tenantId}"]);
    }
    $year = date('Y');
    $lastNumber = Document::query()->where('tenant_id', $tenantId)->where('type', DocumentType::Expense)
        ->where('document_number', 'like', "EXP-{$year}-%")->orderByDesc('document_number')->value('document_number');
    $nextNumber = is_string($lastNumber) ? ((int) substr($lastNumber, -6)) + 1 : 1;
    return sprintf('EXP-%s-%06d', $year, $nextNumber);
}
```

### 1. `GeneralLedgerService::generateEntryNumber`
- New signature: `generateEntryNumber(string $tenantId, string $companyId): string`.
- Lock key `"journal_entry_number:{$tenantId}"` (string-namespaced, like Q-11 — NOT the bare uuid, which
  would alias the company chain lock namespace).
- **Lock ordering is load-bearing (fiscal):** `sealAndPersistEntry` takes a per-COMPANY advisory lock at
  `:3787` (hash chain + `chain_sequence` are per company — that lock MUST stay per-company). Fix the
  global order **tenant key FIRST, then company key**. Inside `generateEntryNumber`: take the tenant lock,
  THEN the existing per-company lock (keep it — it is the same key as `:3787`, re-entrant within the txn,
  and taking it here guarantees the order tenant→company for every path regardless of when
  `sealAndPersistEntry` runs). Write the ordering rule in the comment at both sites (`:3787` comment gets
  one sentence: "the tenant-keyed numbering lock, when taken, is always taken BEFORE this one — see
  generateEntryNumber"). Do NOT reorder or otherwise touch `sealAndPersistEntry`.
- Scan `->where('tenant_id', $tenantId)`; prefix/format unchanged (`JE-%s-%06d`).
- **All 43 call sites** pass the tenant id. Every site already has it in scope from the entity it is
  posting (`$invoice->tenant_id`, `$company->tenant_id`, `$user->tenant_id`, `$locked->tenant_id`,
  `$command->…`) — read each site's `JournalEntry::create([... 'tenant_id' => X ...])` a few lines below
  and pass THAT same expression. Never resolve the tenant via `CompanyContext`/`tenant()` helper: many
  paths run on Horizon workers with NO context bound (see the comments at `:1797`, `:2885`, `:4001`).
  If a site truly has only a `$companyId`, resolve `Company::query()->whereKey($companyId)->value('tenant_id')`
  once at that site (and say so in the report) — expected to be rare or zero.
- PHPStan level 8 must stay clean on the file; Pint clean.

### 2. `IncomeService::generateIncomeNumber`
- Same shape: `generateIncomeNumber(string $tenantId)`, lock key `"income_number:{$tenantId}"`,
  `->where('tenant_id', $tenantId)`, format `INC-%s-%06d` unchanged. Caller passes the tenant id it
  already writes into the `Document::create` (`:76-…`). Confirm both call sites are inside `DB::transaction`
  (they are: `:76`, `:117`, `:153`) — the xact lock is a no-op outside one.

### 3. `JournalEntryController::generateEntryNumber`
- Add the SAME `journal_entry_number:{$tenantId}` pgsql-guarded lock at the top (3 lines). Confirm the
  caller runs inside a transaction; if it does not, wrap the create in `DB::transaction` (report it).
  Keep the key string IDENTICAL to the service's — it is one sequence.

### OUT OF SCOPE (report as residuals, do not touch)
`AccountingOpeningService.php` (`OB-`), `Inventory/…/OpeningBalancePostingService.php:282`,
`ResetOpeningBalanceService.php:257`, `ArApOpeningService.php` (`HIST-`) — Session W owns openings.
No migration. No index change (rejected: widening the unique is an owner ruling — FEC-quoted identifier).
No FE.

## Tests — RED FIRST, by path, paste red then green output per file

Put them in `tests/Feature/Accounting/JournalEntryNumberingTenantScopeTest.php` (new) and
`tests/Feature/Income/IncomeNumberingTenantScopeTest.php` (new; create the dir if absent — check where
income tests live first, `grep -rl IncomeService tests/`).

Required pins (mirror `ExpensePostTest.php:172-230` — read it first):
- **T1 (the reproduction):** one tenant, two companies (each with a seeded chart — see how
  `ExpensePostTest` / `CreateJournalEntryTest` build a company that can post), company A mints a JE through a
  REAL `GeneralLedgerService` path (pick the cheapest public method, e.g. the one `ExpensePostTest` ends
  up exercising, or a manual/adjustment entry method), then company B mints one. Today: 23505 on PG,
  UNIQUE violation on sqlite. After: B gets `JE-YYYY-000002`, both rows persisted, `chain_sequence` per
  company is 1 and 1 (chain stays per company — assert it).
- **T2 (lock statement pin, PG-only, skip otherwise):** `DB::listen`/query log shows
  `SELECT pg_advisory_xact_lock(hashtextextended(?, 0))` with binding `journal_entry_number:<tenant>`
  and that it is issued BEFORE the statement with the bare company-id binding in the same post.
- **T3 (ordering pin, PG-only):** two connections / two transactions — the cheapest honest form: open
  txn 1 on a second PDO connection, take the tenant lock, assert txn 2's post blocks (use
  `pg_try_advisory_xact_lock` on the same key from the test to prove the key is what the service holds).
  If a true two-connection race is not achievable in < 90 s tool calls, pin with the query-log order (T2)
  and say so explicitly.
- **T4 (income):** two companies, one tenant, two incomes → `INC-YYYY-000001`/`000002`; plus the lock pin.
- **T5 (controller):** manual JE create takes the same lock key (query-log pin).
- Regression by path after the change: `tests/Feature/Accounting/CreateJournalEntryTest.php`,
  `tests/Feature/Accounting/ChainSequenceUniqueIndexTest.php`, `tests/Feature/Accounting/PostEntryNowAtomicityTest.php`,
  `tests/Feature/Expense/ExpensePostTest.php` — one file per run, sqlite first, then PG for the ones that
  need it. Count of pre-existing reds (if any) must be established on the UNTOUCHED base first
  (`git stash` is forbidden — use `git diff > /dev/null; git checkout dev -- <file>`? NO. Simply run them
  BEFORE you edit anything, record the numbers, then edit).

## Feature-lane manifest
If you add test files, run `php tools/feature-lane-manifest-check.php` from `apps/api` and update
`tests/feature-lane-manifest.json` as the checker instructs (Accounting + Income lanes). Report the
numbers you resolved (the parent re-resolves against live dev at merge).

## Deliverable — exactly LANE-PROTOCOL §Deliverable, plus:
- the list of the 43 call sites with the tenant-id expression you used at each (a compact table is fine);
- census results: run the Q-11 gate's census SQL #1–#3 (`docs/superpowers/reviews/2026-08-25-sb-q11-supplier-invoice-gate-r1.md` §C)
  against the local tenant DBs via `php artisan tenants:run` or direct psql on 5433 (`\l autoerp*`/`tenant_*`),
  and the same shape for `documents`/`INC-`; paste counts;
- commit on the branch with a message that starts `fix(sb2-c27):` and states the numbering-contract change
  (JE numbers now interleave across companies of a tenant — owner ack line, same as Q-11's condition 2).
Do NOT merge.
