<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BlindCashCountDefaultMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php';

    private const COMPLETION_TOKEN = 'SV-9 BLIND COUNT BACKFILL COMPLETE:';

    private function runMigration(): void
    {
        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->up();
    }

    public function test_it_migrates_persisted_false_rows_and_is_idempotent_with_warning_counts(): void
    {
        $tenant = Tenant::factory()->create();
        $falseCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
        $trueCompany = Company::factory()->create(['tenant_id' => $tenant->id]);

        CompanyFraudSettings::query()
            ->where('company_id', $falseCompany->id)
            ->update(['require_blind_cash_count' => false]);
        CompanyFraudSettings::query()
            ->where('company_id', $trueCompany->id)
            ->update(['require_blind_cash_count' => true]);

        Log::spy();

        $this->runMigration();
        $this->assertDatabaseHas('company_fraud_settings', [
            'company_id' => $falseCompany->id,
            'require_blind_cash_count' => true,
        ]);

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::COMPLETION_TOKEN)
                && str_contains($message, 'tenant=')
                && str_contains($message, 'changed=1')
                && str_contains($message, 'skipped=1'))
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::COMPLETION_TOKEN)
                && str_contains($message, 'tenant=')
                && str_contains($message, 'changed=0')
                && str_contains($message, 'skipped=2'))
            ->once();
    }

    public function test_it_self_guards_when_the_settings_table_is_absent(): void
    {
        Schema::drop('company_fraud_settings');
        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::COMPLETION_TOKEN)
                && str_contains($message, 'tenant=')
                && str_contains($message, 'changed=0')
                && str_contains($message, 'skipped=0')
                && str_contains($message, 'schema=missing'))
            ->once();
    }

    public function test_it_changes_the_postgresql_column_default_to_true(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('[PG] information_schema default contract requires PostgreSQL.');
        }

        DB::statement(
            'ALTER TABLE company_fraud_settings '
            .'ALTER COLUMN require_blind_cash_count SET DEFAULT false',
        );
        $this->assertSame('false', $this->blindCashCountColumnDefault());

        $this->runMigration();

        $this->assertSame('true', $this->blindCashCountColumnDefault());
    }

    private function blindCashCountColumnDefault(): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'company_fraud_settings'
              AND column_name = 'require_blind_cash_count'
            SQL);

        $this->assertNotNull($row);
        $this->assertIsString($row->column_default);

        return $row->column_default;
    }
}
