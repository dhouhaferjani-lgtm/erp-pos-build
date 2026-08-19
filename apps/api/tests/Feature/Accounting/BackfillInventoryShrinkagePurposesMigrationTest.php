<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

final class BackfillInventoryShrinkagePurposesMigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_19_130000_backfill_inventory_shrinkage_purposes.php';

    private const GATE_TOKEN = 'INVENTORY-SHRINKAGE-PURPOSE BACKFILL MIGRATION:';

    public function test_the_tenant_migration_repairs_an_existing_chart_unattended_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $this->account($tenant->id, $company->id, '65', 'expense');
        $this->account($tenant->id, $company->id, '75', 'revenue');
        Log::spy();

        $this->runMigration();
        $firstIds = DB::table('accounts')
            ->where('company_id', $company->id)
            ->whereIn('code', ['6586', '7586'])
            ->orderBy('code')
            ->pluck('id')
            ->all();
        $this->runMigration();

        self::assertCount(2, $firstIds);
        self::assertSame($firstIds, DB::table('accounts')
            ->where('company_id', $company->id)
            ->whereIn('code', ['6586', '7586'])
            ->orderBy('code')
            ->pluck('id')
            ->all());
        self::assertTrue(DB::table('accounts')->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense->value)->exists());
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok'))
            ->twice();
    }

    public function test_a_backfill_failure_is_contained_and_emits_a_failed_deploy_gate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('SQLSTATE 25P02 containment requires PostgreSQL savepoints.');
        }
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $this->account($tenant->id, $company->id, '65', 'expense');
        $this->account($tenant->id, $company->id, '75', 'revenue');
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT m4_migration_reject_6586 CHECK (code <> '6586')");
        Log::spy();

        $this->runMigration();

        self::assertSame(1, DB::table('companies')->where('id', $company->id)->count());
        self::assertTrue(DB::table('accounts')->where('company_id', $company->id)->where('code', '7586')->exists());
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=FAILED'))
            ->once();
    }

    public function test_down_is_an_irreversible_no_op_and_reapply_remains_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $this->account($tenant->id, $company->id, '65', 'expense');
        $this->account($tenant->id, $company->id, '75', 'revenue');

        $this->assertTenantMigrationIsIrreversibleNoOp(
            self::MIGRATION,
            function (string $context) use ($company): void {
                self::assertSame(2, DB::table('accounts')
                    ->where('company_id', $company->id)
                    ->whereIn('code', ['6586', '7586'])
                    ->count(), $context);
            },
        );
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->up();
    }

    private function account(string $tenantId, string $companyId, string $code, string $type): void
    {
        DB::table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'parent_id' => null,
            'code' => $code,
            'name' => "Account {$code}",
            'type' => $type,
            'system_purpose' => null,
            'is_active' => true,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
