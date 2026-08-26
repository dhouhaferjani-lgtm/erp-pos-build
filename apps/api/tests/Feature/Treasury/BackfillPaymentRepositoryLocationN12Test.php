<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campaign lane N-12 — `2026_08_26_100000_backfill_payment_repository_location_n12`.
 *
 * Every tenant provisioned before this lane carries `CASH-01` / `SAFE-01` with
 * `location_id = NULL` (the wave-1 finding), which is why a second POS branch's
 * takings landed in the first branch's balance. `PaymentRepositorySeeder` now
 * attributes the day-one pair, but a seeder only runs for NEW tenants; this
 * migration attributes the pair every EXISTING tenant already has.
 *
 * The migration is invoked directly rather than through `artisan migrate` —
 * `RefreshDatabase` has already run it against the empty schema, so each case
 * builds the pre-migration shape by hand and then applies it.
 */
final class BackfillPaymentRepositoryLocationN12Test extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::factory()->create()->id;
    }

    public function test_it_attributes_an_unattributed_till_and_safe_to_the_default_location(): void
    {
        $company = $this->company();
        $main = $this->location($company, 'Main Location', isDefault: true);
        $this->location($company, 'Boutique Ariana');

        $till = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);
        $safe = $this->repository($company, 'SAFE-01', RepositoryType::Safe);

        $this->runBackfill();

        $this->assertSame($main->id, $this->freshLocationId($till));
        $this->assertSame($main->id, $this->freshLocationId($safe));
    }

    public function test_it_never_moves_a_repository_that_already_has_a_location(): void
    {
        $company = $this->company();
        $this->location($company, 'Main Location', isDefault: true);
        $branch = $this->location($company, 'Boutique Ariana');

        $branchTill = $this->repository($company, 'CASH-02', RepositoryType::CashRegister, $branch->id);

        $this->runBackfill();

        $this->assertSame(
            $branch->id,
            $this->freshLocationId($branchTill),
            'An already-attributed drawer must survive the backfill untouched.',
        );
    }

    /**
     * A `bank_account` / `virtual` repository is the COMPANY's instrument, not a
     * branch's — `TenderRepositoryResolver`
     * keeps unattributed ones reachable from every location. Binding one to Main
     * here would refuse a CARD tender at every other branch.
     */
    public function test_it_leaves_a_company_wide_bank_account_unattributed(): void
    {
        $company = $this->company();
        $this->location($company, 'Main Location', isDefault: true);

        $bank = $this->repository($company, 'BANK-01', RepositoryType::BankAccount);
        $virtual = $this->repository($company, 'WALLET-01', RepositoryType::Virtual);

        $this->runBackfill();

        $this->assertNull($this->freshLocationId($bank));
        $this->assertNull($this->freshLocationId($virtual));
    }

    public function test_it_leaves_a_company_with_no_locations_alone(): void
    {
        $company = $this->company();
        $till = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();

        $this->assertNull(
            $this->freshLocationId($till),
            'No location to attribute to is the pre-N-12 shape, which the resolver still serves.',
        );
    }

    public function test_it_never_attributes_across_companies(): void
    {
        $first = $this->company('First SARL');
        $firstMain = $this->location($first, 'First Main', isDefault: true);
        $firstTill = $this->repository($first, 'CASH-01', RepositoryType::CashRegister);

        $second = $this->company('Second SARL');
        $secondMain = $this->location($second, 'Second Main', isDefault: true);
        $secondTill = $this->repository($second, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();

        $this->assertSame($firstMain->id, $this->freshLocationId($firstTill));
        $this->assertSame($secondMain->id, $this->freshLocationId($secondTill));
    }

    /**
     * Deploys re-run migrations on tenants that already have them; a second pass
     * must be a no-op rather than a second decision.
     */
    public function test_it_is_idempotent(): void
    {
        $company = $this->company();
        $main = $this->location($company, 'Main Location', isDefault: true);
        $till = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();
        $afterFirst = $this->freshLocationId($till);

        // A later, deliberate re-attribution by an operator must not be undone
        // by a re-run either.
        $branch = $this->location($company, 'Boutique Ariana');
        PaymentRepository::query()->findOrFail($till->id)
            ->forceFill(['location_id' => $branch->id])
            ->save();

        $this->runBackfill();

        $this->assertSame($main->id, $afterFirst);
        $this->assertSame($branch->id, $this->freshLocationId($till));
    }

    /**
     * `is_default` first, then a POS-enabled location, then the oldest — the same
     * order `PaymentRepositorySeeder::defaultLocationId()` uses, so a tenant
     * migrated today and a tenant registered today land identically.
     */
    public function test_it_prefers_a_pos_enabled_location_when_none_is_default(): void
    {
        $company = $this->company();
        $this->location($company, 'Entrepôt', posEnabled: false);
        $shop = $this->location($company, 'Boutique', posEnabled: true);

        $till = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();

        $this->assertSame($shop->id, $this->freshLocationId($till));
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Re-read from the database rather than from the hydrated model — the
     * migration writes with the query builder, so an in-memory attribute would
     * prove nothing.
     */
    private function freshLocationId(PaymentRepository $repository): ?string
    {
        return PaymentRepository::query()->findOrFail($repository->id)->location_id;
    }

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php'
        );
        $migration->up();
    }

    private function company(string $name = 'Backfill SARL'): Company
    {
        return Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
    }

    private function location(
        Company $company,
        string $name,
        bool $isDefault = false,
        bool $posEnabled = true,
    ): Location {
        return Location::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'type' => 'shop',
            'is_default' => $isDefault,
            'pos_enabled' => $posEnabled,
        ]);
    }

    private function repository(
        Company $company,
        string $code,
        RepositoryType $type,
        ?string $locationId = null,
    ): PaymentRepository {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'code' => $code,
            'type' => $type,
            'location_id' => $locationId,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
    }
}
