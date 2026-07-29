<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\LegacyMockInterface;
use Tests\TestCase;

final class BackfillBanksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_previews_without_writing_rows(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());

        $this->command('treasury:backfill-banks', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertSuccessful();

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_backfill_seeds_the_directory_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('treasury:backfill-banks')->assertSuccessful();
        self::assertSame(32, Bank::query()->where('tenant_id', $tenant->id)->count());

        // Second apply is command-level idempotent: zero creations reported, count stable.
        $this->command('treasury:backfill-banks')
            ->expectsOutputToContain('0 created')
            ->assertSuccessful();
        self::assertSame(32, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_apply_reports_updated_rows_honestly(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('treasury:backfill-banks')->assertSuccessful();

        // Drift a canonical directory-owned field; re-apply must refresh it and REPORT it.
        $canonical = Bank::query()->where('tenant_id', $tenant->id)->where('is_custom', false)->firstOrFail();
        $canonical->update(['bic' => 'DRIFTED0']);

        $this->command('treasury:backfill-banks')
            ->expectsOutputToContain('0 created, 1 updated')
            ->assertSuccessful();

        self::assertNotSame('DRIFTED0', Bank::query()->whereKey($canonical->id)->firstOrFail()->bic);
    }

    public function test_multi_company_same_tenant_dry_run_does_not_overcount(): void
    {
        $tenant = Tenant::factory()->create();
        // Two TN companies of the SAME tenant share the tenant-scoped directory.
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        // First company would create 32; second (same tenant+country) 0 → total 32, NOT 64.
        $this->command('treasury:backfill-banks', ['--dry-run' => true])
            ->expectsOutputToContain('Bank directory backfill: 32 created, 0 updated across 2 company/companies')
            ->assertSuccessful();

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_rerun_preserves_admin_managed_fields_and_custom_banks(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('treasury:backfill-banks')->assertSuccessful();

        $canonical = Bank::query()->where('tenant_id', $tenant->id)->where('is_custom', false)->firstOrFail();
        $canonical->update(['is_active' => false, 'name' => 'Renamed By Admin']);

        $custom = Bank::query()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
            'name' => 'Custom Local Bank',
            'short_name' => 'CLB',
            'bic' => null,
            'rib_bank_code' => null,
            'city' => 'Tunis',
            'is_active' => true,
            'is_custom' => true,
            'position' => 999,
        ]);

        $this->command('treasury:backfill-banks')->assertSuccessful();

        $canonical->refresh();
        self::assertFalse($canonical->is_active, 'admin is_active must be preserved on re-run');
        self::assertSame('Renamed By Admin', $canonical->name, 'admin name must be preserved on re-run');

        self::assertTrue(Bank::query()->whereKey($custom->id)->exists(), 'custom banks must never be touched');
        self::assertSame(33, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_company_without_a_bank_directory_is_reported_and_skipped_exit_zero(): void
    {
        $tenant = Tenant::factory()->create();
        // No directory file ships for a US company (only TN.json exists).
        Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'US']);

        $this->command('treasury:backfill-banks')
            ->expectsOutputToContain('skipped (no directory)')
            ->assertSuccessful();

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_no_companies_reports_stable_marker_exit_zero(): void
    {
        Tenant::factory()->create();

        $this->command('treasury:backfill-banks')
            ->expectsOutputToContain('across 0 company/companies')
            ->assertSuccessful();
    }

    public function test_delegate_throw_fails_loud_and_aborts(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'ZZ']);

        // Give the fake country a directory file the seeder will choke on (invalid JSON),
        // exercising the real delegate-throw path end to end.
        $path = database_path('data/banks/ZZ.json');

        $logSpy = Log::spy();

        try {
            file_put_contents($path, 'this-is-not-valid-json');

            $this->command('treasury:backfill-banks')
                ->expectsOutputToContain('No further companies were processed.')
                ->assertFailed();
        } finally {
            @unlink($path);
        }

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', [
            Mockery::on(static fn (string $message): bool => $message === 'treasury:backfill-banks failed for a company; aborting.'),
            Mockery::type('array'),
        ]);

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
