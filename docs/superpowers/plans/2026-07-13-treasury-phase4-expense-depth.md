# Treasury Phase ④ — Expense Depth — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recoverable-VAT split wired through GL and the VAT declaration, supplier link, recurring expense templates with notifications + forecast feed, category×period analytics with streamed CSV export, and outbound-instrument direction guards.

**Architecture:** Four sequential waves on one branch `feat/treasury-phase4-expense-depth` (worktree off dev tip ≥ `f2dc43f55`). W1 changes the expense money path (`ExpenseService` + `GeneralLedgerService::createFromExpense`), W2 adds a recurrence engine (template table + daily command + `TreasuryAlertRecipients` notifications + forecast feeds), W3 adds a read layer (analytics endpoint, streamed CSV), W4 hardens instruments. Spec (normative): `docs/superpowers/specs/2026-07-13-treasury-phase4-expense-depth-design.md` (Rev 2).

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PostgreSQL (db-per-tenant), bcmath via `CurrencyScale`, PHPUnit (RefreshDatabase + real models), React 19 + TanStack Query 5 + RHF, Vitest.

## Global Constraints (apply to every task)

- **Precision rule 19:** money = strings end-to-end; `CurrencyScale::bcround($v, $scale)` (half-up) ONLY at the GL-posting boundary; `bcformatStrict` elsewhere; scale ALWAYS via `$this->scaleResolver->getScale($currency)` with explicit currency — **the in-class `GeneralLedgerService::scale()` no-arg helper is BANNED in code this plan touches** (throws in console). Percent columns are NOT currency-scaled: regex `/^\d+(\.\d{1,2})?$/`.
- **Rule 20 (console):** no CompanyContext in commands — pass `company_id`/currency explicitly from the entity.
- No floats on money/qty anywhere (PHPStan `ForbidFloatCastOnDecimalProperty` will fail CI).
- Cross-field validation on EFFECTIVE MERGED values lives in `ExpenseService`, not only FormRequests (update() merges payload over stored state).
- Every new route joins the existing group in `apps/api/app/Modules/Expense/routes.php:21` (stack `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`).
- Every new read/aggregate/export query filters `->where('company_id', $this->companyContext->requireCompanyId())` (or explicit company in console).
- FE: all strings via `t()`; design tokens; `tenantScopedKey([...])` on every tenant query key; money via `<MoneyInput>`/`formatCurrency`; percent via `<QuantityInput decimalPlaces={2}>`; no `parseFloat`/`Number()` on money.
- Tests: PHPUnit **by path only** (`./vendor/bin/phpunit apps/api/tests/... --filter=...` style; NEVER the full suite); valid UUIDs for all FK fixtures; `RolesAndPermissionsSeeder` where permissions matter.
- Commit after every task (conventional commits); run `./scripts/preflight.sh` before each wave-gate.
- **Backward compatibility:** VAT-less expenses keep today's exact shape (`subtotal == total`, `tax_amount` null, 2-line JE). Assert byte-identical behavior in tests.

---

## WAVE 1 — Money path: VAT split + supplier link (GATE 1 after Task 6: Fable-tier treasury review)

### Task 1: Migrations — VAT columns on expense_metadata + widen document_tax_details scale

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_14_100000_add_vat_fields_to_expense_metadata.php`
- Create: `apps/api/database/migrations/tenant/2026_07_14_100100_widen_document_tax_details_amount_scale.php`
- Test: `apps/api/tests/Feature/Expense/ExpenseVatSchemaTest.php`

**Interfaces:**
- Produces: `expense_metadata.vat_rate` `decimal(5,2)` nullable; `expense_metadata.vat_deductible_percent` `decimal(5,2)` nullable (**NO DB default** — spec §5.1); `document_tax_details.tax_base`/`tax_amount` widened `decimal(15,2) → decimal(15,3)` (model casts already say `decimal:3` — this fixes a live cast/column mismatch).

- [ ] **Step 1: Write the failing schema test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExpenseVatSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_metadata_has_vat_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('expense_metadata', 'vat_rate'));
        $this->assertTrue(Schema::hasColumn('expense_metadata', 'vat_deductible_percent'));
    }

    public function test_document_tax_details_amounts_carry_three_decimals(): void
    {
        \DB::table('documents')->insert([/* minimal expense document row with valid uuids */]);
        \DB::table('document_tax_details')->insert([
            'id' => \Str::uuid()->toString(), 'document_id' => $docId,
            'tax_type' => 'PERCENTAGE', 'tax_name' => 'TVA 19%',
            'tax_base' => '10.001', 'tax_amount' => '1.901', 'created_at' => now(),
        ]);
        $row = \DB::table('document_tax_details')->where('document_id', $docId)->first();
        $this->assertSame('1.901', (string) $row->tax_amount); // fails while column is (15,2)
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`hasColumn` false; `1.901` truncated to `1.90`).
- [ ] **Step 3: Write both migrations** — `$table->decimal('vat_rate', 5, 2)->nullable(); $table->decimal('vat_deductible_percent', 5, 2)->nullable();` after `vendor_name`; and `$table->decimal('tax_base', 15, 3)->nullable()->change(); $table->decimal('tax_amount', 15, 3)->change();` (widening only — reversible `down()` narrows back).
- [ ] **Step 4: `php artisan migrate` in test env; run test — PASS.**
- [ ] **Step 5: Commit** `feat(expense): VAT columns on expense_metadata + 3dp tax-detail scale fix`

### Task 2: ExpenseRequest — partner_id + VAT trio format rules

**Files:**
- Modify: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:49-94`
- Test: `apps/api/tests/Feature/Expense/ExpenseRequestVatValidationTest.php`

**Interfaces:**
- Produces request fields consumed by Task 3: `partner_id` (nullable uuid, scoped), `vat_amount` (nullable, money 3dp regex), `vat_rate` (nullable, percent 2dp regex, max 100), `vat_deductible_percent` (nullable, percent 2dp regex, 0–100).

- [ ] **Step 1: Failing tests** — POST `/api/v1/expenses` as a permissioned user: (a) `partner_id` from ANOTHER company → 422; (b) `vat_rate: "19.555"` → 422 (2dp ceiling); (c) `vat_amount: "1.2345"` → 422 (3dp ceiling); (d) `vat_deductible_percent: "101"` → 422; (e) happy path with `partner_id` + `vat_amount: "1.900"` → 201.
- [ ] **Step 2: Run — FAIL** (unknown fields pass through / no rules).
- [ ] **Step 3: Add rules** (inside `rules()`, after `vendor_name`):

```php
'partner_id' => ['nullable', 'uuid', ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)],
'vat_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
'vat_deductible_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
```

- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** `feat(expense): partner + VAT request validation`

### Task 3: ExpenseService — subtotal derivation, merged-value guards, partner + document_date passthrough

**Files:**
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:59-159` (`create()`, `update()`)
- Test: `apps/api/tests/Feature/Expense/ExpenseServiceVatTest.php`

**Interfaces:**
- Consumes: Task 1 columns, Task 2 fields.
- Produces (Task 4 posting relies on these invariants): `documents.partner_id` set from `$data['partner_id']`; `documents.tax_amount = vat_amount`; `documents.subtotal = bcsub(total, vat_amount, scale)`; `documents.document_date = $data['document_date'] ?? $data['payment_date'] ?? now()`; metadata `vat_rate`, `vat_deductible_percent` (defaulted to `'100.00'` when `vat_amount` present and percent absent); private `assertVatInvariants(array $effective, ExpenseKind $kind): void` throwing `\DomainException`.

- [ ] **Step 1: Failing tests** (all through the service with real models):
  - create with `total: "119.000"`, `vat_amount: "19.000"` → `subtotal === "100.000"`, `tax_amount === "19.000"`, `vat_deductible_percent === "100.00"` (defaulted).
  - create VAT-less → `subtotal === total`, `tax_amount` null, percent null (backward compat).
  - create with `document_date: "2026-08-01"`, no payment_date → document_date honored (today it falls to now()).
  - create `expense_kind: linked_cost` + `vat_amount` → `\DomainException`.
  - **update merged-value guard:** create draft `total: "100.000"`, `vat_amount: "15.000"`; update payload `['total' => '10.000']` only → `\DomainException` (`vat_amount < total` violated on MERGED values).
  - create with `partner_id` → `documents.partner_id` persisted.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** In `create()` (and mirrored in `update()` with merged effective values):

```php
$scale = $this->scaleResolver->getScale((string) $companyCurrency);
$total = (string) ($data['total'] ?? '0.00');
$vatAmount = isset($data['vat_amount']) ? CurrencyScale::bcformatStrict((string) $data['vat_amount'], $scale) : null;
$vatDeductiblePercent = $data['vat_deductible_percent'] ?? ($vatAmount !== null ? '100.00' : null);

$this->assertVatInvariants(
    ['total' => $total, 'vat_amount' => $vatAmount],
    $linked === null ? ExpenseKind::Generic : ExpenseKind::LinkedCost,
);

$subtotal = $vatAmount !== null ? bcsub($total, $vatAmount, $scale) : $total;
```

Document::create gains `'partner_id' => $data['partner_id'] ?? null`, `'subtotal' => $subtotal`, `'tax_amount' => $vatAmount`, `'document_date' => $data['document_date'] ?? $data['payment_date'] ?? now()->toDateString()`. Metadata gains `'vat_rate' => $data['vat_rate'] ?? null`, `'vat_deductible_percent' => $vatDeductiblePercent`.

```php
private function assertVatInvariants(array $effective, ExpenseKind $kind): void
{
    $vat = $effective['vat_amount'];
    if ($vat === null) {
        return;
    }
    if ($kind === ExpenseKind::LinkedCost) {
        throw new \DomainException('VAT fields are not supported on linked-cost expenses; landed-cost capitalization consumes the full amount. Record VAT-bearing costs as generic expenses.');
    }
    if (bccomp($vat, $effective['total'], 4) >= 0) {
        throw new \DomainException('VAT amount must be less than the expense total.');
    }
}
```

`update()`: compute effective `$total = $data['total'] ?? (string) $expense->total`, `$vatAmount = array_key_exists('vat_amount', $data) ? ... : (string|null) stored`, call `assertVatInvariants` with the STORED `expense_kind`, then persist derived subtotal/tax_amount alongside the existing merge; `document_date` honors `$data['document_date'] ?? $data['payment_date'] ?? stored`.

- [ ] **Step 4: Run — PASS.** Also run the existing expense cutoff tests by path (`tests/Feature/Expense/`) — must stay green.
- [ ] **Step 5: Commit** `feat(expense): VAT-aware totals, partner link, document_date passthrough, merged-value guards`

### Task 4: Posting — 3-line VAT split in createFromExpense + document_tax_details write

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3401-3423` (line-creation block)
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php` (`post()` — add tax-detail write in the same transaction, immediately after `createFromExpense` returns)
- Test: `apps/api/tests/Feature/Accounting/ExpenseVatPostingTest.php`

**Interfaces:**
- Consumes: Task 3 invariants (`subtotal` = net, `tax_amount` = VAT, metadata percent).
- Produces: 3-line JE when `tax_amount > 0` (Dr expense `net + non_deductible`, Dr VatDeductible `deductible`, Cr AP-or-Cash `total`); `document_tax_details` row (`tax_amount` = deductible VAT, `tax_base` = subtotal, `tax_rate` = metadata vat_rate, `tax_type` = `'PERCENTAGE'`, `tax_name` = `'TVA'` + rate when present).

- [ ] **Step 1: Failing tests** (fixture: seeded chart via existing test helpers; `repository.gl_account_id` set to the purpose-resolved Cash account so reconcile's AUTHORITATIVE branch is exercised — Fable-lane note):
  - unpaid, `total 119.000 / vat 19.000 / deductible 100%`, TND (3dp): lines exactly `[Dr expense 100.000, Dr 4456 19.000, Cr AP 119.000]`, AP line `partner_id` = expense partner.
  - paid, same amounts: `Cr Cash 119.000`.
  - deductible 80%: `Dr 4456 15.200`, `Dr expense 103.800` (remainder method — lines sum to total exactly).
  - **half-up 1-millime case:** `vat 0.001`, deductible 50% → `bcround('0.0005', 3) = '0.001'` → Dr 4456 `0.001`, non-deductible `0.000`.
  - deductible 0%: NO 4456 line (2-line entry, expense debit = total).
  - VAT-less expense: byte-identical 2-line entry to today (regression).
  - `document_tax_details` row written at post with `tax_amount = "15.200"` (deductible, not gross) for the 80% case; NO row for VAT-less.
  - EUR (2dp) variant of the split math.
  - **supplier balance:** post unpaid w/ partner → `PartnerBalanceService`-refreshed `payable_balance` includes `119.000`; settle → returns to prior value (MP-M3).
  - reconcile checks #1–#4 green over the paid-VAT fixture (`treasury:reconcile` by path).
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement.** Replace the single expense-debit block (`GeneralLedgerService.php:3404-3412`) with:

```php
$scale = $this->scaleResolver->getScale((string) $expense->currency); // NEVER $this->scale() here (no-arg → throws in console)
$total = (string) ($expense->total ?? '0');
$vatAmount = $expense->tax_amount !== null ? (string) $expense->tax_amount : null;

if ($vatAmount !== null && bccomp($vatAmount, '0', $scale) === 1) {
    $deductiblePercent = (string) ($metadata->vat_deductible_percent ?? '100.00');
    // Intermediate at scale+2; single half-up round at the posting boundary (NC 01 §62).
    $deductibleVat = CurrencyScale::bcround(
        bcdiv(bcmul($vatAmount, $deductiblePercent, $scale + 2), '100', $scale + 2),
        $scale,
    );
    $nonDeductibleVat = bcsub($vatAmount, $deductibleVat, $scale); // remainder method: lines always sum to total
    $expenseDebit = bcadd((string) ($expense->subtotal ?? '0'), $nonDeductibleVat, $scale);

    JournalLine::create([...expense account..., 'debit' => $expenseDebit, ...]);
    if (bccomp($deductibleVat, '0', $scale) === 1) {
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->getAccountByPurpose($companyId, SystemAccountPurpose::VatDeductible)->id,
            'partner_id' => null,
            'debit' => $deductibleVat,
            'credit' => '0',
            'description' => 'TVA déductible',
            'line_order' => $lineOrder++,
        ]);
    }
} else {
    JournalLine::create([...existing single expense debit, unchanged...]);
}
// credit line unchanged: Cr AP-or-Cash = $total
```

In `ExpenseService::post()`, inside the existing transaction after the JE call: when `tax_amount > 0`, create the `DocumentTaxDetail` (idempotent: `firstOrCreate` keyed on `document_id` + `tax_type` since post() is idempotency-guarded upstream); `tax_amount` = the SAME `$deductibleVat` recomputed with the identical formula (extract a small shared pure helper `ExpenseVatSplit::deductible(string $vatAmount, string $percent, int $scale): string` in `apps/api/app/Modules/Expense/Domain/Services/ExpenseVatSplit.php` and use it in BOTH places so GL and declaration can never diverge).

- [ ] **Step 4: Run — PASS** (all cases incl. reconcile + regression).
- [ ] **Step 5: Commit** `feat(accounting): expense VAT split posting (bcround half-up) + input-VAT declaration wiring`

### Task 5: FE — PartnerPicker + VAT block on the expense form

**Files:**
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx` (vendor block `:125-132`, defaults `:58-99`), `apps/web/src/features/expenses/types/index.ts` (`CreateExpenseDTO` + `Expense` metadata types), `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx` (breakdown block)
- Test: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.test.tsx`

**Interfaces:**
- Consumes: Task 2 request fields; `PartnerPicker` (`@/components/molecules/pickers`, props `{value, onChange: (PartnerPickerValue|null) => void, partnerType: 'supplier'}`); `QuantityInput decimalPlaces={2}`.
- Produces: `CreateExpenseDTO` gains `partner_id?: string; vat_amount?: string; vat_rate?: string; vat_deductible_percent?: string` (all strings).

- [ ] **Step 1: Failing Vitest** — renders PartnerPicker; selecting a supplier fills `vendor_name` with `value.name` (editable after); entering `vat_rate` 19 with total `119.000` prefills `vat_amount` via bcmath-safe string helper (`computeVatFromInclusive(total, rate)` using the existing FE `bccomp`-family utils — NO `parseFloat`); `vat_deductible_percent` defaults to `'100'`; payload contains strings only; linked_cost kind hides the VAT block.
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement** — VAT block below total: rate `<Select>` fed by the company-country tax configurations (existing taxation hook if present, else static options with manual entry), `<MoneyInput id="vat_amount" currency={currency}>`, `<QuantityInput id="vat_deductible_percent" decimalPlaces={2}>`; helper hint `t('expenses:form.vatDeductibleHint')`. Inline-RHF `validate` callbacks (spec FE-L2): `vat_amount < total` via `bccomp`. Detail page: net / VAT (rate, deductible-%) / total rows + partner link. i18n keys in `locales/{en,fr,ar}/expenses.json`.
- [ ] **Step 4: Run Vitest by feature — PASS. Run `pnpm typecheck`.**
- [ ] **Step 5: Commit** `feat(web/expenses): supplier picker + VAT entry block`

### Task 6: W1 closeout — typescript:transform + preflight + GATE 1

- [ ] Run `php artisan typescript:transform` (with `CACHE_STORE=array` if in worktree), commit generated types.
- [ ] Run `./scripts/preflight.sh`; fix anything it surfaces; commit.
- [ ] **GATE 1 (autonomous):** Fable-tier treasury review of the W1 diff (money path). BLOCKER/HIGH → fix and re-gate before starting W2.

---

## WAVE 2 — Recurring templates (GATE 2 after Task 12: Opus review + tenancy-authz lane)

### Task 7: Migration + enums + model

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_14_110000_create_expense_recurrence_templates.php`, `..._110100_add_recurrence_template_id_to_expense_metadata.php`
- Create: `apps/api/app/Modules/Expense/Domain/Enums/RecurrenceFrequency.php`, `RecurrenceStatus.php`, `apps/api/app/Modules/Expense/Domain/ExpenseRecurrenceTemplate.php`
- Test: `apps/api/tests/Feature/Expense/ExpenseRecurrenceTemplateModelTest.php`

**Interfaces:**
- Produces: table per spec §6.1 (columns verbatim from the spec table: tenant_id, company_id, name, expense_category_id, partner_id, payment_method_id, payment_repository_id — all nullable FKs set-null; vendor_name; `amount` decimal(15,3); `vat_rate`/`vat_deductible_percent` decimal(5,2) nullable; `vat_amount` decimal(15,3) nullable; notes; `frequency` string enum-cast `RecurrenceFrequency {Monthly='monthly', Quarterly='quarterly', Yearly='yearly'}`; `start_date`/`end_date` date; `lead_days` smallint default 3; `status` enum-cast `RecurrenceStatus {Active='active', Paused='paused', Ended='ended'}`; `next_due_date` date; `created_by` FK users); `expense_metadata.recurrence_template_id` uuid nullable FK set-null.
- [ ] TDD cycle: schema+casts test → migrations+model (casts: enums, dates, `amount`/`vat_amount` `decimal:3`, percent `decimal:2`) → PASS → commit `feat(expense): recurrence template schema`.

### Task 8: Cursor math — `RecurrenceCursor` pure domain service

**Files:**
- Create: `apps/api/app/Modules/Expense/Domain/Services/RecurrenceCursor.php`
- Test: `apps/api/tests/Unit/Expense/RecurrenceCursorTest.php`

**Interfaces:**
- Produces (Tasks 9/10/11 consume): `RecurrenceCursor::next(CarbonImmutable $startDate, RecurrenceFrequency $f, CarbonImmutable $current): CarbonImmutable` (origin-anchored: k = occurrences elapsed + 1, `$startDate->addMonthsNoOverflow(k * step)` — never `$current->addMonth()`); `RecurrenceCursor::firstOnOrAfter(CarbonImmutable $startDate, RecurrenceFrequency $f, CarbonImmutable $today): CarbonImmutable` (resume/edit recompute); `RecurrenceCursor::periodKey(CarbonImmutable $due, RecurrenceFrequency $f): string` (`'2026-07'` / `'2026-Q3'` / `'2026'`).

- [ ] **Step 1: Failing unit tests** — day-31 anchor: start `2026-01-31` monthly → `2026-02-28`, `2026-03-31` (origin-anchored no-overflow, NOT `2026-03-28` drift); quarterly from `2026-02-15` → `2026-05-15`; yearly leap `2024-02-29` → `2025-02-28`; `firstOnOrAfter(start 2026-01-05, monthly, today 2026-04-20)` → `2026-05-05`; period keys for each frequency.
- [ ] **Steps 2–4: red → implement (pure static, no now()/Date deps — everything injected) → green.**
- [ ] **Step 5: Commit** `feat(expense): origin-anchored recurrence cursor math`

### Task 9: Recurrence CRUD — FormRequest + controller + routes + permissions

**Files:**
- Create: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRecurrenceRequest.php`, `.../Controllers/ExpenseRecurrenceController.php`
- Modify: `apps/api/app/Modules/Expense/routes.php` (inside the `:21` group), `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (catalog `:189` + manager `:470` + accountant `:690` + view-roles blocks), `apps/web/src/hooks/usePermissions.ts`
- Test: `apps/api/tests/Feature/Expense/ExpenseRecurrenceCrudTest.php`

**Interfaces:**
- Produces routes: `GET/POST /expense-recurrences` (`can:expense-recurrences.view` / `.create`), `GET/PUT/DELETE /expense-recurrences/{id}` (`.view`/`.update`/`.delete`), `POST /expense-recurrences/{id}/pause`, `POST /{id}/resume` (`.update`). Permissions + grants per spec §8.5 (normative): full CRUD → manager + accountant (+admin via all); `.view` → cashier/operator/viewer; `expenses.export` is Task 14's, seeded here too to keep one seeder edit.
- FormRequest: all four FKs `ScopedExists::tenantAndCompany(...)` (mirror `ExpenseRequest.php:53-68`); `frequency`/`status` `Rule::enum`; amount money-3dp regex; percent 2dp regexes; `lead_days` integer 0–60; dates. Controller: every query `->where('tenant_id',...)->where('company_id', $this->companyContext->requireCompanyId())`; create computes `next_due_date = RecurrenceCursor::firstOnOrAfter(start_date, frequency, today)`; **update recomputes `next_due_date` when `frequency` or `start_date` changes; resume rolls `firstOnOrAfter(..., today)` — no backfill (spec §6.1)**.

- [ ] **Step 1: Failing tests** — CRUD happy paths; **deny-path: cashier can `GET` (200) but `POST` → 403; operator `PUT` → 403** (spec §8.5); cross-company `payment_repository_id` → 422; update `start_date` → `next_due_date` recomputed; pause→resume with stale past cursor → rolled forward, no intermediate periods.
- [ ] **Steps 2–4: red → implement → green** (run seeder in test setup).
- [ ] Also register the new permissions in `usePermissions.ts` with grants matching §8.5 exactly (TA-H3; if the replenishment permission-map generator landed on dev meanwhile, regenerate instead — check `git log origin/dev -- apps/web/src/hooks/usePermissions.ts` first).
- [ ] **Step 5: Commit** `feat(expense): recurrence CRUD + permissions (BE map + FE map)`

### Task 10: Generation command `expenses:generate-recurring`

**Files:**
- Create: `apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php` (extends the same `TenantScopedCommand` base as `InstrumentMaturityAlertsCommand` — copy its `executeCommand()`/`forEachTenant` + per-company error-isolation shape verbatim, `InstrumentMaturityAlertsCommand.php:41-90`)
- Modify: `apps/api/routes/console.php` (after the maturity-alerts block `:66-69`)
- Test: `apps/api/tests/Feature/Expense/GenerateRecurringExpensesCommandTest.php`

**Interfaces:**
- Consumes: `ExpenseService::create` (Task 3 shape — pass `company_id`, `document_date`, NO `payment_date`, `is_paid => false`, `status` Draft default, `idempotency_key`), `RecurrenceCursor`, `TreasuryAlertRecipients::forCompany($tenantId, $companyId, 'expenses.post')`, `TreasuryAlertNotification`.
- Produces: notification type string `'expense.recurring.generated'` with `data: {template_name, amount, currency, due_date, deep_link: "/expenses/{id}"}`; metadata rows carry `recurrence_template_id` + `idempotency_key = "recurring:{template_id}:{period_key}"`.

Core per-company loop (the shape to implement — atomicity + `wasRecentlyCreated` gate are spec-normative RA-H1):

```php
$today = CarbonImmutable::today($company->timezone);            // RA-M4
$templates = ExpenseRecurrenceTemplate::query()
    ->where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)                          // TA-M2: company-partitioned scan
    ->where('status', RecurrenceStatus::Active)
    ->whereDate('next_due_date', '<=', $today->addDays(/* max lead */ 60))
    ->orderBy('id')->get()
    ->filter(fn ($t) => $t->next_due_date->subDays($t->lead_days)->lte($today));

foreach ($templates as $template) {
    [$expense, $fresh] = DB::transaction(function () use ($template, $user): array {   // atomic create+advance
        $due = CarbonImmutable::parse($template->next_due_date->toDateString());
        $expense = $this->expenseService->create([
            'company_id' => $template->company_id,               // explicit, no context (rule 20 / TA-M2)
            'document_date' => $due->toDateString(),
            'is_paid' => false,
            'total' => (string) $template->amount,
            'vat_amount' => $template->vat_amount !== null ? (string) $template->vat_amount : null,
            'vat_rate' => ..., 'vat_deductible_percent' => ...,
            'expense_category_id' => $template->expense_category_id,
            'partner_id' => $template->partner_id,
            'vendor_name' => $template->vendor_name,
            'payment_method_id' => $template->payment_method_id,
            'payment_repository_id' => $template->payment_repository_id,
            'notes' => $template->notes,
            'idempotency_key' => sprintf('recurring:%s:%s', $template->id,
                RecurrenceCursor::periodKey($due, $template->frequency)),
        ], $systemUser);
        $expense->expenseMetadata?->update(['recurrence_template_id' => $template->id]);
        $next = RecurrenceCursor::next($template->start_date, $template->frequency, $due);
        $template->update([
            'next_due_date' => $next->toDateString(),
            'status' => ($template->end_date !== null && $next->gt($template->end_date))
                ? RecurrenceStatus::Ended : $template->status,
        ]);
        return [$expense, $expense->wasRecentlyCreated];
    });
    if ($fresh) {                                                 // after commit, RA-H1: no re-notify on replay
        $recipients = $this->alertRecipients->forCompany($template->tenant_id, $template->company_id, 'expenses.post');
        if ($recipients->isNotEmpty()) { Notification::send($recipients, new TreasuryAlertNotification(
            alertType: 'expense.recurring.generated', data: [...])); }
    }
}
```

⚠️ `wasRecentlyCreated` note: `ExpenseService::create`'s short-circuit path returns a re-queried Document (`wasRecentlyCreated === false`); the created path returns the `Document::create` instance (`true`). Assert both in tests — this contract is load-bearing.

Schedule block (`routes/console.php`, in-process — NO `runInBackground`, RA-M3):

```php
// Schedule: materialize recurring expense drafts + due reminders.
// Run in-process so the scheduler observes the command's partial-failure exit.
Schedule::command('expenses:generate-recurring')
    ->dailyAt('05:30')
    ->withoutOverlapping();
```

- [ ] **Step 1: Failing tests** — draft created with template's fields (incl. VAT trio + `recurrence_template_id`, `document_date` = due, `payment_date` NULL, `is_paid` false, status Draft); cursor advanced origin-anchored; **replay run (same period): no second draft, cursor NOT double-advanced, NO second notification** (assert `DatabaseNotification` count); notification recipients = `expenses.post` holders of THAT company only; ended when `next > end_date`; **two-companies-one-tenant: company-A template never generates for company B**; lead-days gate honors company timezone (fixture company with `Pacific/Auckland` vs UTC edge date); `HorizonQueueCoverageTest` untouched (database channel — no queue).
- [ ] **Steps 2–4: red → implement → green.** `php artisan schedule:list` shows the entry.
- [ ] **Step 5: Commit** `feat(expense): recurring draft generation command + due notifications`

### Task 11: Forecast feeds in UpcomingPaymentsService

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php:38-40` (concat), plus two new private methods next to `openUnpaidExpenses` (`:117-133`)
- Test: `apps/api/tests/Feature/Accounting/UpcomingPaymentsRecurringTest.php`

**Interfaces:**
- Consumes: `ExpenseRecurrenceTemplate`, `RecurrenceCursor` (same direct-query pattern the file already uses for `ExpenseMetadata` — keep the existing in-file convention; note in the class docblock that this is the file's established cross-module read style).
- Produces in Money-Out, per spec §6.4 partition: (1) `materializedRecurringDrafts()` — same query shape as `openUnpaidExpenses` but `status = Draft` + `whereHas('expenseMetadata', fn($q) => $q->whereNotNull('recurrence_template_id'))`, due = `document_date`; (2) `projectedRecurringOccurrences()` — active templates, occurrences from `next_due_date` forward within `windowEnd` (loop `RecurrenceCursor::next` from the cursor), emitted as synthetic lines (label = template name, amount string, date) — **projection only, never materializes**; (3) existing posted-unpaid feed unchanged.

- [ ] **Step 1: Failing tests** — template with cursor inside window → projected line appears; generate the draft (call the Task 10 command) → **the period moves from projection to the draft feed (no double-count, no gap)**; post the draft unpaid → moves to the existing feed; amounts are strings summed with bcmath at company scale.
- [ ] **Steps 2–4: red → implement → green** (existing `UpcomingPaymentsService` tests by path stay green).
- [ ] **Step 5: Commit** `feat(accounting): recurring occurrences + materialized drafts feed the 30-day forecast`

### Task 12: FE — RecurringExpensesPage + notification surface + GATE 2

**Files:**
- Create: `apps/web/src/features/expenses/pages/RecurringExpensesPage.tsx`, `.../api/recurrenceApi.ts`, `.../hooks/useExpenseRecurrences.ts` (+ tests)
- Modify: routes/nav per `docs/conventions/02-NAVIGATION-ROUTING.md`; `apps/web/src/features/expenses/_invalidation.ts`; `NotificationPanel.tsx` (`KNOWN_TYPES` + `displayMessage` case); `locales/{en,fr,ar}/notifications.json` (**NESTED** `types → expense → recurring → generated`, FE-L1) + `expenses.json` namespaces; `ExpenseDetailPage.tsx` (template chip when `recurrence_template_id`).
- [ ] TDD: Vitest — list renders (name, frequency badge, next due, `formatCurrency(amount)`, status), pause/resume mutations invalidate via bare-literal prefixes, nav gated `hasPermission('expense-recurrences.view')`, form reuses W1 field set incl. PartnerPicker + VAT block; notification panel resolves the nested key and deep-links.
- [ ] `pnpm typecheck && pnpm lint` (feature paths) + transform if DTOs changed; commit `feat(web/expenses): recurring templates UI + bell notification type`.
- [ ] **GATE 2 (autonomous):** Opus review of W2 diff + tenancy-authz lane on permissions/notification. Fable escalation only on money-path BLOCKER/HIGH.

---

## WAVE 3 — Analytics + export (GATE 3 after Task 15)

### Task 13: Analytics endpoint

**Files:**
- Create: `apps/api/app/Modules/Expense/Application/Services/ExpenseAnalyticsService.php`, `.../Presentation/Controllers/ExpenseAnalyticsController.php`, `.../Requests/ExpenseAnalyticsRequest.php`
- Modify: `apps/api/app/Modules/Expense/routes.php` — `Route::get('expenses/analytics', ...)->middleware('can:expenses.view')` **registered ABOVE `expenses/{id}`** (route-order trap: `{id}` would swallow `analytics`).
- Test: `apps/api/tests/Feature/Expense/ExpenseAnalyticsTest.php`

**Interfaces:**
- Produces response per spec §7.1 (tiles/by_category/matrix/top_vendors, all money strings). Params: `date_from`, `date_to` (default: first day of month 5 months back → today), `category_id?`, `status?` default `'posted'`. Service method: `generate(string $tenantId, string $companyId, AnalyticsFilters $f): ExpenseAnalyticsData` (spatie-data DTO → transform later).
- Implementation constraints: **every aggregate one GROUP BY query** over `documents` joined to `expense_metadata`/`expense_categories`, ALL filtered `company_id = requireCompanyId()` AND `type = expense` (TA-H2); `matrix` months via `to_char(document_date, 'YYYY-MM')`; `top_vendors` groups `COALESCE(partner_id::text, vendor_name)` with partner name join; `mom_delta_percent` = current vs previous equal-length period, bcmath, `null` when previous = 0; SUMs read as strings. Check `\DB::select("SELECT indexname FROM pg_indexes WHERE tablename='documents'")` in a plan step — add composite index migration `documents(company_id, type, status, document_date)` ONLY if no equivalent exists (RA-L4).

- [ ] **Step 1: Failing tests** — seeded fixture (2 categories × 3 months × posted/draft mix, one VAT expense): totals string-exact; draft excluded under default status, included with `status=draft`; category filter; month bucketing at boundaries (doc on the 1st/last day); **two-companies-one-tenant leak test: company B rows never in company A response** (TA-H2); top_vendors groups by partner when set, else vendor_name.
- [ ] **Steps 2–4: red → implement → green.**
- [ ] **Step 5: Commit** `feat(expense): analytics endpoint (tiles/category/matrix/top-vendors)`

### Task 14: Streamed CSV export

**Files:**
- Create: `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseExportController.php`
- Modify: `apps/api/app/Modules/Expense/routes.php` — `Route::get('expenses/export', ...)->middleware('can:expenses.export')` (also above `{id}`); seeder already carries `expenses.export` from Task 9.
- Test: `apps/api/tests/Feature/Expense/ExpenseExportTest.php`

**Interfaces:**
- Produces `GET /expenses/export` — same filters as `index()` (reuse the exact filter block, extracted into a shared query-builder method on a small `ExpenseIndexQuery` class consumed by BOTH `index()` and export so filters can't drift). `response()->streamDownload` (exemplar `ImportController.php:527-533`) writing UTF-8 BOM `"\xEF\xBB\xBF"` then header row then `->cursor()` rows; columns per spec §7.3; money cells = raw stored decimal strings; filename `expenses-{date_from}-{date_to}.csv`.
- [ ] **Step 1: Failing tests** — 45 seeded rows with `per_page=20`-style filters → CSV contains ALL 45 (not one page); BOM present; deny-path: role without `expenses.export` → 403; company-B rows absent (leak test); VAT columns populated.
- [ ] **Steps 2–4: red → implement → green.**
- [ ] **Step 5: Commit** `feat(expense): permission-gated streamed CSV export`

### Task 15: FE — DateRangeFilter + tiles + analytics page + export buttons + GATE 3

**Files:**
- Modify: `apps/web/src/features/expenses/pages/ExpenseListPage.tsx` (replace lone date input `:136-143` with `<DateRangeFilter>` — props `{label, fromValue, toValue, onFromChange, onToChange}`; add tiles row; export button), `expenseApi.ts` (+`getAnalytics`, `exportCsv`), `useExpenses.ts` (+`useExpenseAnalytics` with `tenantScopedKey`)
- Create: `apps/web/src/features/expenses/pages/ExpenseAnalyticsPage.tsx` (template: `features/finance/pages/CashMovementsReportPage.tsx` — PageHeader, DataTable, tokens) + route/nav.
- Test: page-level Vitest for both pages.
- [ ] TDD essentials: tiles pass ONLY `status`/`category_id`/`date_from`/`date_to` to analytics — `search` never sent; **caption `t('expenses:analytics.searchExcluded')` rendered when a search is active** (FE-H1); tiles use `formatCurrency`; legacy-net caption `t('expenses:analytics.legacyNetCaption')` on the analytics page (MP-L4); export uses the **authenticated blob pattern** verbatim from `PayrollExportPage.tsx:53-60` (`api.get('/expenses/export', {params, responseType: 'blob'})` → objectURL → click → revoke), button gated `hasPermission('expenses.export')`; matrix table `overflow-x-auto`, RTL-checked; ar/fr/en keys complete.
- [ ] `pnpm typecheck && pnpm lint`; commit `feat(web/expenses): date-to filter, tiles, analytics page, CSV export`.
- [ ] **GATE 3 (autonomous):** Opus review of W3 diff (incl. FE-conventions lane).

---

## WAVE 4 — Hardening + closeout (GATE 4 = final)

### Task 16: Outbound direction guards (5 points)

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php` (`custodyTransfer:132`, `deposit:172`, `clear:189`, `bounce:313` — guard immediately after each `findOrFail`), `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php` (`assertEligible:265` — first check)
- Test: `apps/api/tests/Feature/Treasury/OutboundInstrumentGuardTest.php`

**Interfaces:** guard text identical at all five points:

```php
if ($instrument->direction === InstrumentDirection::Outbound) {
    throw new DomainException('Outbound (supplier-direction) instruments have no collection lifecycle; deposit/clear/bounce apply to inbound instruments only.');
}
```

- [ ] **Step 1: Failing tests** — an Outbound instrument (created via the manual registration endpoint) rejected by each of the five entry points (custodyTransfer, deposit, clear, bounce, remittance addLine via `assertEligible`); `receive()` and `cancel()` still work on Outbound (regression — the deferred-supplier branch depends on receive); an Inbound instrument still clears end-to-end (regression by path).
- [ ] **Steps 2–4: red → implement → green.**
- [ ] **Step 5: Commit** `fix(treasury): reject inbound lifecycle actions on outbound instruments (5 guard points)`

### Task 17: Phase closeout

- [ ] `php artisan typescript:transform`; i18n completeness check en/fr/ar for every key added this phase; `_invalidation.ts` audit; `./scripts/preflight.sh` green; commit.
- [ ] Write `docs/handoff/HANDOFF-outbound-instruments-<date>.md` (D4 owe — scope per spec §12 first bullet) + `docs/handoff/treasury-phase4-deploy-checklist.md` (spec §10 verbatim: tenants:migrate; perm reseed + `permission:cache-reset`; scheduler verify; VatDeductible-presence verification query; NO Horizon change).
- [ ] **GATE 4 (final, autonomous):** full-branch review — Fable-tier treasury lane (W1+W4 money surfaces), Opus lanes (W2/W3), tenancy-authz + frontend-conventions lanes. ALL must APPROVE before the merge request goes to the owner.

---

## Plan self-review notes (done at write time)

- **Spec coverage:** §5→Tasks 1-6; §6→7-12; §7→13-15; §8→16; §8.5→9 (+14 deny-path); §9 test cases distributed into task Step-1 lists; §10→17; §12 G20-handoff→17. No uncovered spec section.
- **Type consistency:** `RecurrenceCursor` signatures identical across Tasks 8/9/10/11; `ExpenseVatSplit::deductible` shared GL/tax-detail (Task 4); DTO field names match `CreateExpenseDTO` extension (Tasks 2/3/5).
- **Known deliberate deviations:** none from spec Rev 2; `bcround` (not "bcroundHalfUp") per spec correction; tax-detail widening migration added per spec §5.3 note.
