<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillTolerancePurposesCommand;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `accounting:backfill-tolerance-purposes` (spec §4.2, Task 5).
 *
 * The chart of accounts is built by the per-country COA seeders, not by a
 * migration, so `accounts` is EMPTY after RefreshDatabase's migrate:fresh.
 * Every test therefore seeds exactly the chart rows it wants to reason about
 * — that is also the brownfield shape the command exists to repair.
 */
final class BackfillTolerancePurposesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Backfill Tenant',
            'slug' => 'backfill-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill Shop',
            'legal_name' => 'Backfill Shop SARL',
            'tax_id' => 'TAX-BF-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);
    }

    private function seedAccount(
        string $code,
        string $type,
        ?string $parentId,
        ?string $purpose = null,
        bool $isActive = true,
        ?string $companyId = null,
    ): string {
        $id = (string) Str::uuid();
        DB::table('accounts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyId ?? $this->company->id,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => $isActive,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_creates_missing_tolerance_accounts_under_the_tn_parents(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $revenueParent = $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $expense = DB::table('accounts')->where('company_id', $this->company->id)->where('code', '6580')->first();
        $income = DB::table('accounts')->where('company_id', $this->company->id)->where('code', '7580')->first();

        $this->assertNotNull($expense);
        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense->value, $expense->system_purpose);
        $this->assertSame($expenseParent, $expense->parent_id);
        $this->assertSame('expense', $expense->type);
        $this->assertTrue((bool) $expense->is_system);

        $this->assertNotNull($income);
        $this->assertSame(SystemAccountPurpose::PaymentToleranceIncome->value, $income->system_purpose);
        $this->assertSame($revenueParent, $income->parent_id);
        $this->assertSame('revenue', $income->type);
    }

    public function test_creates_missing_tolerance_accounts_under_the_generic_parents(): void
    {
        $generic = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Generic Shop',
            'legal_name' => 'Generic Shop Ltd',
            'tax_id' => 'TAX-BF-2',
            'country_code' => 'GB',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
        ]);

        // TN company keeps its own parents so the run stays clean overall.
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $expenseParent = $this->seedAccount('6000', 'expense', null, null, true, $generic->id);
        $revenueParent = $this->seedAccount('7000', 'revenue', null, null, true, $generic->id);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $expense = DB::table('accounts')->where('company_id', $generic->id)->where('code', '6580')->first();
        $income = DB::table('accounts')->where('company_id', $generic->id)->where('code', '7580')->first();

        $this->assertNotNull($expense);
        $this->assertSame($expenseParent, $expense->parent_id);
        $this->assertSame('Payment Tolerance Expense', $expense->name);

        $this->assertNotNull($income);
        $this->assertSame($revenueParent, $income->parent_id);
        $this->assertSame('Payment Tolerance Income', $income->name);
    }

    public function test_promotes_an_existing_code_matched_account_lacking_a_purpose(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $orphan = $this->seedAccount('6580', 'expense', $expenseParent, null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $row = DB::table('accounts')->where('id', $orphan)->first();
        $this->assertNotNull($row);
        $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense->value, $row->system_purpose);
    }

    public function test_hard_fails_when_the_parent_account_is_absent(): void
    {
        // No 65 / 75 parents seeded.
        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('is missing parent account')
            ->assertFailed();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
    }

    public function test_dry_run_does_not_promote_an_existing_account(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $orphan = $this->seedAccount('6580', 'expense', $expenseParent, null);

        $this->artisan('accounting:backfill-tolerance-purposes', ['--dry-run' => true])
            ->assertSuccessful();

        $row = DB::table('accounts')->where('id', $orphan)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->system_purpose);
    }

    public function test_rejects_an_existing_account_with_the_wrong_type(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $this->seedAccount('6580', 'revenue', $expenseParent, null);

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('has wrong type')
            ->assertFailed();
    }

    public function test_rejects_an_inactive_code_matched_account(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $orphan = $this->seedAccount('6580', 'expense', $expenseParent, null, false);

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('is inactive')
            ->assertFailed();

        $row = DB::table('accounts')->where('id', $orphan)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->system_purpose);
    }

    public function test_refuses_to_repurpose_an_account_that_already_carries_another_purpose(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $occupied = $this->seedAccount(
            '6580',
            'expense',
            $expenseParent,
            SystemAccountPurpose::GeneralExpense->value,
        );

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('refusing to repurpose')
            ->assertFailed();

        $row = DB::table('accounts')->where('id', $occupied)->first();
        $this->assertNotNull($row);
        $this->assertSame(SystemAccountPurpose::GeneralExpense->value, $row->system_purpose);
    }

    /**
     * `accounts_company_purpose_unique` is UNIQUE(company_id, system_purpose):
     * a company whose chart already carries the tolerance purpose on a LEGACY
     * code (the enum's own comments say 658/758) is already resolvable —
     * Account::findByPurpose looks the account up BY PURPOSE, never by code.
     * Creating or promoting 6580 there would violate that unique index and
     * abort the whole tenant run with a QueryException, so the command must
     * check the purpose FIRST and leave such a chart untouched.
     */
    public function test_leaves_a_chart_whose_purpose_is_held_by_a_legacy_code_untouched(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $revenueParent = $this->seedAccount('75', 'revenue', null);
        $legacyExpense = $this->seedAccount(
            '658',
            'expense',
            $expenseParent,
            SystemAccountPurpose::PaymentToleranceExpense->value,
        );
        $legacyIncome = $this->seedAccount(
            '758',
            'revenue',
            $revenueParent,
            SystemAccountPurpose::PaymentToleranceIncome->value,
        );

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '7580',
        ]);

        $this->assertSame(
            $legacyExpense,
            DB::table('accounts')
                ->where('company_id', $this->company->id)
                ->where('system_purpose', SystemAccountPurpose::PaymentToleranceExpense->value)
                ->value('id'),
        );
        $this->assertSame(
            $legacyIncome,
            DB::table('accounts')
                ->where('company_id', $this->company->id)
                ->where('system_purpose', SystemAccountPurpose::PaymentToleranceIncome->value)
                ->value('id'),
        );
    }

    /**
     * A revenue account holding `payment_tolerance_expense` must NOT be counted
     * satisfied: the gate would read clean while
     * GeneralLedgerService::createRepositoryAdjustmentJournalEntry debits a cash
     * loss to a revenue account. The holder branch validates type exactly as the
     * code-matched branch does.
     */
    public function test_reports_a_purpose_holder_with_the_wrong_type_as_a_failure(): void
    {
        $this->seedAccount('65', 'expense', null);
        $revenueParent = $this->seedAccount('75', 'revenue', null);
        $this->seedAccount(
            '758',
            'revenue',
            $revenueParent,
            SystemAccountPurpose::PaymentToleranceExpense->value,
        );
        // Income side is healthy so the failure below is unambiguously the holder.
        $this->seedAccount(
            '7580',
            'revenue',
            $revenueParent,
            SystemAccountPurpose::PaymentToleranceIncome->value,
        );

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('has wrong type')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        // The expense parent exists, so a fall-through to the create path would
        // have minted a duplicate purpose holder (and hit the unique index).
        $this->assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'code' => '6580',
        ]);
    }

    public function test_reports_a_chart_with_the_two_purposes_swapped(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $revenueParent = $this->seedAccount('75', 'revenue', null);
        // 6580 is an expense account but carries the INCOME purpose, and vice versa.
        $this->seedAccount(
            '6580',
            'expense',
            $expenseParent,
            SystemAccountPurpose::PaymentToleranceIncome->value,
        );
        $this->seedAccount(
            '7580',
            'revenue',
            $revenueParent,
            SystemAccountPurpose::PaymentToleranceExpense->value,
        );

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('has wrong type')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 2')
            ->assertFailed();
    }

    /**
     * `companies` soft-deletes and this command uses the raw query builder, which
     * applies no model scope. A trashed chartless company would otherwise inflate
     * the deploy-gate token for an otherwise healthy tenant.
     */
    public function test_ignores_a_soft_deleted_company_without_a_chart(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $trashed = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Shop',
            'legal_name' => 'Closed Shop SARL',
            'tax_id' => 'TAX-BF-9',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);
        $trashed->delete();
        $this->assertNotNull($trashed->fresh()?->deleted_at);

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();
    }

    public function test_does_not_write_accounts_into_a_soft_deleted_company(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $trashed = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Shop',
            'legal_name' => 'Closed Shop SARL',
            'tax_id' => 'TAX-BF-8',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);
        // Parents present: without the deleted_at filter the command WOULD mint
        // system accounts inside a deleted company.
        $this->seedAccount('65', 'expense', null, null, true, $trashed->id);
        $this->seedAccount('75', 'revenue', null, null, true, $trashed->id);
        $trashed->delete();

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $this->assertDatabaseMissing('accounts', [
            'company_id' => $trashed->id,
            'code' => '6580',
        ]);
        $this->assertDatabaseMissing('accounts', [
            'company_id' => $trashed->id,
            'code' => '7580',
        ]);
    }

    public function test_reports_an_inactive_purpose_holder_as_a_failure(): void
    {
        $expenseParent = $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);
        $this->seedAccount(
            '658',
            'expense',
            $expenseParent,
            SystemAccountPurpose::PaymentToleranceExpense->value,
            false,
        );

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain('is inactive')
            ->assertFailed();
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();
        $this->artisan('accounting:backfill-tolerance-purposes')->assertSuccessful();

        $this->assertSame(1, DB::table('accounts')->where('company_id', $this->company->id)->where('code', '6580')->count());
        $this->assertSame(1, DB::table('accounts')->where('company_id', $this->company->id)->where('code', '7580')->count());
    }

    /**
     * `tenants:run` swallows the child exit code (see the command docblock), so
     * the last line is the machine-readable gate deploy checklists grep for.
     * Its exact shape is pinned here — do not reword it.
     */
    public function test_emits_the_stable_summary_token_with_a_zero_count_on_a_clean_run(): void
    {
        $this->seedAccount('65', 'expense', null);
        $this->seedAccount('75', 'revenue', null);

        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();
    }

    public function test_emits_the_stable_summary_token_with_the_failure_count(): void
    {
        // Neither parent exists: both definitions fail for the single company.
        $this->artisan('accounting:backfill-tolerance-purposes')
            ->expectsOutputToContain(BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX.' 2')
            ->assertFailed();
    }

    public function test_summary_token_prefix_is_the_documented_literal(): void
    {
        $this->assertSame(
            'TOLERANCE-PURPOSE BACKFILL FAILURES:',
            BackfillTolerancePurposesCommand::SUMMARY_TOKEN_PREFIX,
        );
    }
}
