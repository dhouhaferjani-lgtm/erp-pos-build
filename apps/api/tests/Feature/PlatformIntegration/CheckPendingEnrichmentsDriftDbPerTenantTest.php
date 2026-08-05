<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Enums\EnrichmentStatus;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

/**
 * N-6 (2026-08-05 re-gate): the enrichment poller's `products.tenant_id` drift
 * signal.
 *
 * B2 gave `findPendingEnrichments()` the tenant predicate its two sibling
 * conversions already carried, but not the R1 WARNING that came with them. A
 * drifted product therefore left the polling window in complete silence — and
 * this poller is the enrichment webhook's safety net, so nothing downstream
 * recovers the submission either.
 *
 * {@see WarnsOnTenantScopeDrift} is inert when
 * `tenancy_resolver.db_per_tenant = false`, which is the rest of the suite's
 * mode, so this leg binds real per-tenant databases — the only place the signal
 * exists at all.
 */
final class CheckPendingEnrichmentsDriftDbPerTenantTest extends TestCase
{
    use ProvisionsTenantDatabases;
    use RefreshDatabase;

    private const RESOURCE = 'products (pending enrichment)';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy_resolver.db_per_tenant' => true]);

        // The poller's only outbound leg. Faked so a correctly-stamped product
        // can be polled for real without leaving the test process. `submitted`
        // maps back to EnrichmentStatus::Pending, i.e. "no change" — the poll
        // runs end to end without dispatching a status-change event.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['status' => 'submitted'], 200)]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_a_product_whose_tenant_id_drifted_inside_its_own_database_is_warned_about(): void
    {
        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $company = $this->createCompany($tenant->id, 'DRIFT');

            // Physically inside THIS tenant's database, stamped with someone
            // else's tenant id: excluded by the predicate, so never polled.
            $this->createPendingProduct((string) Str::uuid(), $company->id);
        });

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $lines = $this->driftLines($records);

        $this->assertCount(
            1,
            $lines,
            'A pending submission sitting in this tenant\'s database under another tenant\'s id is never polled and '
            .'never recovered — the silence is the defect.',
        );
        $this->assertSame($tenant->id, $lines[0]->context['tenant_id']);
        $this->assertSame(1, $lines[0]->context['rows_in_tenant_database']);
        $this->assertSame(0, $lines[0]->context['rows_matching_predicate']);
        $this->assertSame(1, $lines[0]->context['rows_dropped']);
        $this->assertCount(1, $lines[0]->context['dropped_ids']);
    }

    /**
     * The control: a correctly-stamped pending product is polled and produces
     * no drift line, so the probe pair is measuring drift and not backlog.
     */
    public function test_a_correctly_stamped_pending_product_produces_no_drift_line(): void
    {
        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $company = $this->createCompany($tenant->id, 'CLEAN');
            $this->createPendingProduct($tenant->id, $company->id);
        });

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $this->assertSame([], $this->driftLines($records));
    }

    /**
     * @param  ArrayObject<int, MessageLogged>  $records
     * @return list<MessageLogged>
     */
    private function driftLines(ArrayObject $records): array
    {
        return array_values(array_filter(
            $records->getArrayCopy(),
            static fn (MessageLogged $message): bool => $message->level === 'warning'
                && ($message->context['resource'] ?? null) === self::RESOURCE,
        ));
    }

    /**
     * @return ArrayObject<int, MessageLogged>
     */
    private function captureLog(): ArrayObject
    {
        /** @var ArrayObject<int, MessageLogged> $records */
        $records = new ArrayObject;

        Log::listen(function (MessageLogged $message) use ($records): void {
            $records[] = $message;
        });

        return $records;
    }

    private function createCompany(string $tenantId, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => "Enrichment Company {$suffix}",
            'legal_name' => "Enrichment Company {$suffix} LLC",
            'tax_id' => "TAX-ENR-DPT-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createPendingProduct(string $tenantId, string $companyId): Product
    {
        return Product::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => (string) Str::uuid(),
            'updated_at' => now()->subMinutes(15),
        ]);
    }
}
