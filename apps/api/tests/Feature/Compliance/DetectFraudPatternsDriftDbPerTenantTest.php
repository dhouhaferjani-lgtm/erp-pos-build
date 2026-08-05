<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Tenant;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

/**
 * `fraud:detect`'s R1 drift signal, under the PRODUCTION tenancy mode.
 *
 * N-5 (2026-08-05 re-gate): {@see WarnsOnTenantScopeDrift} was unit-tested with
 * SYNTHETIC closures only, so nothing pinned that either consumer hands it a
 * coherent probe pair — the part most likely to be wrong. The concern is
 * deliberately inert when `tenancy_resolver.db_per_tenant = false`, which is
 * every other test's mode, so the wiring could only ever be exercised here.
 *
 * The pair this command passes is soft-delete-consistent by construction: the
 * unfiltered probe, the matched probe and the enumeration are all
 * `Company::query()`, so all three carry Company's SoftDeletes global scope and
 * a trashed company can never register as drift. (`channels:reconcile` needed
 * an explicit `withTrashed()` for the same property — see N-1 — because there
 * the scope reaches the company through a relation while the unfiltered probe
 * counts `channels`.) That difference is what this test exists to hold still.
 */
final class DetectFraudPatternsDriftDbPerTenantTest extends TestCase
{
    use ProvisionsTenantDatabases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy_resolver.db_per_tenant' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_a_company_whose_tenant_id_drifted_inside_its_own_database_is_warned_about(): void
    {
        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function (): void {
            $this->createCompany((string) Str::uuid(), 'DRIFT');
        });

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('fraud:detect'));

        $lines = $this->driftLines($records, 'companies');

        $this->assertCount(
            1,
            $lines,
            'A company sitting in this tenant\'s database with someone else\'s tenant_id is silently excluded from '
            .'the nightly fraud sweep — that silence is the defect the signal exists to end.',
        );
        $this->assertSame($tenant->id, $lines[0]->context['tenant_id']);
        $this->assertSame(1, $lines[0]->context['rows_in_tenant_database']);
        $this->assertSame(0, $lines[0]->context['rows_matching_predicate']);
        $this->assertSame(1, $lines[0]->context['rows_dropped']);
    }

    public function test_a_tenant_whose_companies_are_correctly_stamped_produces_no_drift_line(): void
    {
        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $this->createCompany($tenant->id, 'CLEAN');
        });

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('fraud:detect'));

        $this->assertSame([], $this->driftLines($records, 'companies'));
    }

    /**
     * A soft-deleted company must not read as drift. Every probe in this
     * command's pair is a `Company::query()`, so the SoftDeletes scope applies
     * uniformly — the trashed row is invisible to the unfiltered count and to
     * the matched count alike.
     */
    public function test_a_soft_deleted_company_is_not_reported_as_drift(): void
    {
        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $this->createCompany($tenant->id, 'TRASHED')->delete();
        });

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('fraud:detect'));

        $this->assertSame([], $this->driftLines($records, 'companies'));
    }

    /**
     * @param  ArrayObject<int, MessageLogged>  $records
     * @return list<MessageLogged>
     */
    private function driftLines(ArrayObject $records, string $resource): array
    {
        return array_values(array_filter(
            $records->getArrayCopy(),
            static fn (MessageLogged $message): bool => $message->level === 'warning'
                && ($message->context['resource'] ?? null) === $resource
                && array_key_exists('rows_dropped', $message->context),
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
            'name' => "Fraud Company {$suffix}",
            'legal_name' => "Fraud Company {$suffix} LLC",
            'tax_id' => "TAX-FRAUD-DPT-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }
}
