<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillInventoryShrinkagePurposesCommand;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BackfillInventoryShrinkagePurposesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_option_a_backfill_is_purpose_first_and_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $expenseParent = $this->account($tenant->id, $company->id, '65', 'expense');
        $gainParent = $this->account($tenant->id, $company->id, '75', 'revenue');

        $this->artisan('accounting:backfill-inventory-shrinkage-purposes')
            ->expectsOutputToContain(BackfillInventoryShrinkagePurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $expense = DB::table('accounts')->where('company_id', $company->id)->where('code', '6586')->first();
        $gain = DB::table('accounts')->where('company_id', $company->id)->where('code', '7586')->first();
        self::assertSame($expenseParent, $expense?->parent_id);
        self::assertSame(SystemAccountPurpose::InventoryShrinkageExpense->value, $expense?->system_purpose);
        self::assertSame($gainParent, $gain?->parent_id);
        self::assertSame(SystemAccountPurpose::InventoryGainIncome->value, $gain?->system_purpose);

        $ids = [$expense?->id, $gain?->id];
        $this->artisan('accounting:backfill-inventory-shrinkage-purposes')->assertSuccessful();
        self::assertSame($ids, [
            DB::table('accounts')->where('company_id', $company->id)->where('code', '6586')->value('id'),
            DB::table('accounts')->where('company_id', $company->id)->where('code', '7586')->value('id'),
        ]);
        self::assertSame(2, DB::table('accounts')->where('company_id', $company->id)->whereIn('code', ['6586', '7586'])->count());
    }

    public function test_a_legacy_purpose_holder_wins_over_the_approved_code(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $expenseParent = $this->account($tenant->id, $company->id, '65', 'expense');
        $this->account($tenant->id, $company->id, '75', 'revenue');
        $holder = $this->account($tenant->id, $company->id, '658', 'expense', $expenseParent, SystemAccountPurpose::InventoryShrinkageExpense->value);

        $this->artisan('accounting:backfill-inventory-shrinkage-purposes')->assertSuccessful();

        self::assertSame($holder, DB::table('accounts')->where('company_id', $company->id)->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)->value('id'));
        self::assertFalse(DB::table('accounts')->where('company_id', $company->id)->where('code', '6586')->exists());
    }

    #[DataProvider('nonTunisianMaps')]
    public function test_france_and_generic_charts_use_the_approved_parents_and_names(
        string $countryCode,
        string $expenseParentCode,
        string $gainParentCode,
        string $expenseName,
        string $gainName,
    ): void {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'country_code' => $countryCode,
        ]);
        $expenseParent = $this->account($tenant->id, $company->id, $expenseParentCode, 'expense');
        $gainParent = $this->account($tenant->id, $company->id, $gainParentCode, 'revenue');

        $this->artisan('accounting:backfill-inventory-shrinkage-purposes')->assertSuccessful();

        $expense = DB::table('accounts')->where('company_id', $company->id)->where('code', '6586')->first();
        $gain = DB::table('accounts')->where('company_id', $company->id)->where('code', '7586')->first();
        self::assertSame($expenseParent, $expense?->parent_id);
        self::assertSame($expenseName, $expense?->name);
        self::assertSame($gainParent, $gain?->parent_id);
        self::assertSame($gainName, $gain?->name);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function nonTunisianMaps(): array
    {
        return [
            'France' => ['FR', '65', '75', "Écarts d'inventaire — manquants et pertes", "Écarts d'inventaire — excédents"],
            'Generic' => ['GB', '6000', '7000', 'Inventory Shrinkage Expense', 'Inventory Count Gain'],
        ];
    }

    public function test_savepoint_contains_one_bad_definition_and_emits_warning_level_gate_token_last(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('SQLSTATE 25P02 containment requires PostgreSQL savepoints.');
        }
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $this->account($tenant->id, $company->id, '65', 'expense');
        $this->account($tenant->id, $company->id, '75', 'revenue');
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT m4_reject_6586 CHECK (code <> '6586')");
        Log::spy();

        $this->artisan('accounting:backfill-inventory-shrinkage-purposes')
            ->expectsOutputToContain('m4_reject_6586')
            ->expectsOutputToContain(BackfillInventoryShrinkagePurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        self::assertTrue(DB::table('accounts')->where('company_id', $company->id)->where('code', '7586')->where('system_purpose', SystemAccountPurpose::InventoryGainIncome->value)->exists());
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => $message === BackfillInventoryShrinkagePurposesCommand::SUMMARY_TOKEN_PREFIX.' 1');
    }

    private function account(string $tenantId, string $companyId, string $code, string $type, ?string $parentId = null, ?string $purpose = null): string
    {
        $id = Str::uuid()->toString();
        DB::table('accounts')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => "Account {$code}",
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
