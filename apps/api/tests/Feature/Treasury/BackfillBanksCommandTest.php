<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class BackfillBanksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_previews_without_writing_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());

        $this->command('treasury:backfill-banks', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertSuccessful();

        self::assertSame(0, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_backfill_seeds_the_directory_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('treasury:backfill-banks')->assertSuccessful();
        $seeded = Bank::query()->where('tenant_id', $tenant->id)->count();
        self::assertSame(32, $seeded);

        // Re-run is a no-op: no duplicate rows, count stable.
        $this->command('treasury:backfill-banks')->assertSuccessful();
        self::assertSame(32, Bank::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_rerun_preserves_admin_managed_fields_and_custom_banks(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('treasury:backfill-banks')->assertSuccessful();

        // Admin deactivates and renames a canonical bank; adds a custom bank.
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

    public function test_company_without_a_bank_directory_is_reported_and_skipped(): void
    {
        $tenant = Tenant::factory()->create();
        // No directory file ships for a US company (only TN.json exists).
        Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'US']);

        $this->command('treasury:backfill-banks')
            ->expectsOutputToContain('no bank directory')
            ->assertSuccessful();

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
