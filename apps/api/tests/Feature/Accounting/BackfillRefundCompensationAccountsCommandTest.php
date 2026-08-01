<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §5.3 (T1 errata, Errata 4.3) —
 * `accounting:backfill-refund-compensation-accounts`.
 *
 * The load-bearing assertion (Errata 4.3): an existing 'revenue'-typed 709
 * account (the pre-fix FR/TN seeder shape) keeps its `type` UNTOUCHED
 * after the backfill — only `system_purpose` is written.
 */
final class BackfillRefundCompensationAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
        ]);
    }

    /**
     * Simulates the PRE-FIX FranceChartOfAccountsSeeder shape: a 709
     * account that exists, is typed 'revenue' (the bug this backfill
     * corrects the PURPOSE mapping for, per §5.3's T1 errata), and has NO
     * system_purpose at all.
     */
    private function seedPreFix709Account(): string
    {
        $id = Str::uuid()->toString();
        DB::table('accounts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => null,
            'code' => '709',
            'name' => 'Rabais, remises et ristournes accordés',
            'type' => 'revenue',
            'system_purpose' => null,
            'is_active' => true,
            'is_system' => true,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_existing_revenue_typed_709_account_keeps_its_type_and_gains_the_purpose(): void
    {
        $accountId = $this->seedPreFix709Account();

        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        $account = DB::table('accounts')->where('id', $accountId)->first();
        self::assertNotNull($account);
        self::assertSame('revenue', $account->type, 'the backfill must NEVER rewrite type on an existing account (Errata 4.3)');
        self::assertSame(SystemAccountPurpose::SalesReturn->value, $account->system_purpose);
    }

    public function test_refund_write_off_account_is_created_for_a_chart_that_predates_it(): void
    {
        $this->seedPreFix709Account();

        self::assertDatabaseMissing('accounts', [
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::RefundWriteOff->value,
        ]);

        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        $account = DB::table('accounts')
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::RefundWriteOff->value)
            ->first();
        self::assertNotNull($account);
        self::assertSame('6590', $account->code);
        self::assertSame('expense', $account->type);
        self::assertSame(1, (bool) $account->is_active ? 1 : 0);
    }

    public function test_backfill_is_idempotent_on_a_second_run(): void
    {
        $this->seedPreFix709Account();

        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();
        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        self::assertDatabaseCount('accounts', 2); // the original 709 + the one new RefundWriteOff row
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $accountId = $this->seedPreFix709Account();

        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $account = DB::table('accounts')->where('id', $accountId)->first();
        self::assertNotNull($account);
        self::assertNull($account->system_purpose);
        self::assertDatabaseMissing('accounts', ['system_purpose' => SystemAccountPurpose::RefundWriteOff->value]);
    }

    public function test_company_with_no_chart_at_all_is_skipped_without_error(): void
    {
        $emptyCompany = Company::factory()->create(['tenant_id' => $this->tenant->id, 'country_code' => 'FR']);

        $this->artisanCommand('accounting:backfill-refund-compensation-accounts', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        self::assertDatabaseCount('accounts', 0);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
