<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Gate r1 finding 3 — an operator-created branch till is indistinguishable
     * from `CASH-01`, so an ambiguous company is left exactly as it was.
     *
     * The repositories UI leaves `location_id` NULL on every row it creates.
     * Binding both of these to Main would move a branch's drawer to the wrong
     * branch, and nothing in the product could move it back.
     */
    public function test_it_leaves_two_unattributed_tills_alone_on_a_multi_location_company(): void
    {
        $company = $this->company();
        $this->location($company, 'Main Location', isDefault: true);
        $this->location($company, 'Boutique Ariana');

        $first = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);
        $second = $this->repository($company, 'CASH-OPERATOR', RepositoryType::CashRegister);
        $safe = $this->repository($company, 'SAFE-01', RepositoryType::Safe);

        $this->runBackfill();

        $this->assertNull($this->freshLocationId($first));
        $this->assertNull($this->freshLocationId($second));
        $this->assertNull(
            $this->freshLocationId($safe),
            'An ambiguous company is skipped whole — a half-armed tier is harder to reason about than the status quo.',
        );
    }

    /**
     * Gate r1 finding 1 / fiscal A — the migration must not arm a refusal it has
     * no way to surface.
     *
     * A branch whose terminal is ALREADY claimed never re-enters
     * `TerminalController::claim()`, so the 422 gate cannot fire for it. Left to
     * attribution alone, every cash sale there would have thrown in the
     * projection and dead-lettered after the customer paid. The branch gets its
     * own drawer instead.
     */
    public function test_it_provisions_a_drawer_for_a_pos_enabled_branch_that_has_none(): void
    {
        $company = $this->companyWithChart();
        $main = $this->location($company, 'Main Location', isDefault: true);
        $branch = $this->location($company, 'Boutique Ariana');

        $legacy = $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();

        $this->assertSame($main->id, $this->freshLocationId($legacy));

        $branchDrawer = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('location_id', $branch->id)
            ->where('type', RepositoryType::CashRegister)
            ->first();

        $this->assertNotNull($branchDrawer, 'The branch must leave the migration with a drawer of its own.');
        $this->assertNotNull($branchDrawer->gl_account_id, 'A drawer the resolver cannot see is not provisioning.');
        $this->assertSame(0, bccomp((string) $branchDrawer->balance, '0.000', 3));
        $this->assertSame($company->currency, $branchDrawer->currency);
    }

    /**
     * The same protection for a location whose `pos_enabled` flag is off but
     * which still carries a terminal — the flag became load-bearing only
     * recently, so a device claimed before that keeps selling regardless.
     */
    public function test_it_provisions_a_drawer_for_a_location_whose_terminal_is_already_claimed(): void
    {
        $company = $this->companyWithChart();
        $this->location($company, 'Main Location', isDefault: true);
        $branch = $this->location($company, 'Boutique Ariana', posEnabled: false);

        Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'location_id' => $branch->id,
            'genesis_seed' => str_repeat('0', 64),
            'hardware_identifier' => 'device-claimed-before-n12',
        ]);

        $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        $this->runBackfill();

        $this->assertTrue(
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('location_id', $branch->id)
                ->whereIn('type', [RepositoryType::CashRegister, RepositoryType::Safe])
                ->exists(),
        );
    }

    /**
     * Gate r1 finding 8 — `provision()` is read-then-insert, so the database has
     * to be the arbiter of "one drawer per location per type".
     */
    public function test_it_installs_the_one_drawer_per_location_type_index(): void
    {
        $company = $this->companyWithChart();
        $main = $this->location($company, 'Main Location', isDefault: true);
        $this->repository($company, 'CASH-01', RepositoryType::CashRegister);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // The index is a PARTIAL unique index, which is PostgreSQL-only —
            // and PostgreSQL is what every tenant database actually is. The
            // default suite runs on SQLite, so this case proves itself under
            // `phpunit-pgsql.xml` and states plainly that it did not otherwise.
            $this->markTestSkipped('Partial unique indexes are exercised under phpunit-pgsql.xml.');
        }

        $this->runBackfill();

        $this->assertNotEmpty(
            DB::select("SELECT 1 FROM pg_indexes WHERE indexname = 'payment_repositories_one_drawer_per_location_type'"),
        );

        // The index's predicate is the provisioner's own: attributed, a drawer
        // type, ACTIVE and GL-linked. A second drawer that matches it — which is
        // what a concurrent `pos_enabled` flip would insert — must be refused by
        // the DATABASE, not merely by the read-then-insert probe. Inserted raw,
        // so the refusal that surfaces is the index's and not a model hook's.
        $glAccountId = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash)->id;

        try {
            DB::table('payment_repositories')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'company_id' => $company->id,
                'code' => 'CASH-DUPLICATE',
                'name' => 'Second till, same location',
                'type' => RepositoryType::CashRegister->value,
                'location_id' => $main->id,
                'gl_account_id' => $glAccountId,
                'is_active' => true,
                'currency' => 'TND',
                'balance' => '0.000',
            ]);

            $this->fail('A second usable drawer at the same location must be refused by the database.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0] ?? null);
            $this->assertStringContainsString(
                'payment_repositories_one_drawer_per_location_type',
                $exception->getMessage(),
            );
        }
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

    /**
     * A company whose chart is seeded — provisioning needs the `cash` purpose
     * account, exactly as the live `pos_enabled` flip does.
     */
    private function companyWithChart(string $name = 'Backfill SARL'): Company
    {
        $company = $this->company($name);

        app(CompanyContext::class)->setCompanyId($company->id);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        app(CompanyContext::class)->clear();

        return $company;
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
