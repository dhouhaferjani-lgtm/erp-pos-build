<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Tenant;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

/**
 * `channels:reconcile` under the PRODUCTION tenancy mode
 * (`tenancy_resolver.db_per_tenant = true`).
 *
 * The rest of the suite runs in single-schema compat mode, where
 * {@see WarnsOnTenantScopeDrift} is deliberately INERT —
 * there the unfiltered set legitimately holds every other tenant's rows, so the
 * probes are not even issued. That makes the compat suite structurally unable
 * to exercise the R1 drift signal or anything gated on it, which is exactly the
 * blind spot the 2026-08-05 re-gate named:
 *
 *   - **N-2**: `warnOnTenantScopeDrift()` RETURNS the dropped count and the
 *     command discarded it, so a drifted `companies.tenant_id` both warned AND
 *     pruned — upgrading R1's scenario from "never reconciled" to "webhooks
 *     dead", because a tenant whose channels all drift enumerates ZERO live
 *     ids and `pruneTenant()` then deletes every pointer it owns.
 *   - **N-5**: nothing pinned that this command hands the concern a COHERENT
 *     probe pair — the part most likely to be wrong — and with the
 *     pre-N-1 scope the channels probe emitted a false-positive drift warning
 *     for every tenant holding a soft-deleted company.
 *
 * Each test binds a real per-tenant SQLite database and builds its fixture
 * INSIDE it, so "which database did the query land on" is an observable fact
 * rather than an assumption.
 */
final class ChannelReconcileCommandDbPerTenantTest extends TestCase
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

    /**
     * N-2. A tenant whose `companies.tenant_id` has drifted enumerates none of
     * its own channels, so the live-id set is EMPTY and an ungated prune reads
     * that as "this tenant owns no channel any more" — deleting every pointer
     * it has. The drift count the same call already computes is the signal that
     * the enumeration cannot be trusted; the prune must stand down on it.
     */
    public function test_a_drifted_tenant_warns_and_its_pointers_are_left_alone(): void
    {
        Queue::fake();

        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            // The drift: a company sitting in THIS tenant's database while its
            // tenant_id column points somewhere else (a restore from another
            // tenant's dump, a seeder default, a mis-provisioned tenant).
            $company = $this->createCompany((string) Str::uuid(), 'DRIFT');
            $this->createChannel($company, 'drifted');

            unset($tenant);
        });

        // A pointer this tenant legitimately owns — the collateral damage.
        $survivorId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $survivorId,
            'tenant_id' => $tenant->id,
        ]);

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertSame(
            1,
            $this->driftWarnings($records, 'channels'),
            'A channel excluded by the tenant_id predicate inside its own tenant database must raise exactly one '
            .'drift warning.',
        );
        $this->assertNotNull(
            ChannelWebhookDirectoryEntry::query()->find($survivorId),
            'The prune must stand down for a tenant whose enumeration is known to be incomplete — otherwise a '
            .'drifted tenant_id column silently deletes every webhook pointer the tenant owns.',
        );
    }

    /**
     * The control for the test above: with no drift the prune still does its
     * job, so the gate is a gate and not a blanket disable.
     */
    public function test_a_clean_tenant_still_prunes_its_stale_pointers(): void
    {
        Queue::fake();

        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $company = $this->createCompany($tenant->id, 'CLEAN');
            $this->createChannel($company, 'clean');
        });

        $ghostId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $ghostId,
            'tenant_id' => $tenant->id,
        ]);

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertSame(0, $this->driftWarnings($records, 'channels'));
        $this->assertNull(
            ChannelWebhookDirectoryEntry::query()->find($ghostId),
            'A pointer whose channel is absent from a cleanly-enumerated tenant must still be pruned.',
        );
    }

    /**
     * N-5. A soft-deleted company is not drift: its `tenant_id` is correct and
     * the channel is still owned by this tenant. Before N-1 the probe scope
     * applied the SoftDeletes global scope, so every tenant holding one got a
     * nightly false-positive drift warning — and, once N-2 gates the prune on
     * that count, a false positive would additionally disable the prune for
     * that tenant forever.
     */
    public function test_a_soft_deleted_company_is_neither_drift_nor_a_reason_to_skip_the_prune(): void
    {
        Queue::fake();

        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $company = $this->createCompany($tenant->id, 'TRASHED');
            $this->createChannel($company, 'trashed');
            $company->delete();
        });

        $ghostId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $ghostId,
            'tenant_id' => $tenant->id,
        ]);

        $records = $this->captureLog();

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertSame(
            0,
            $this->driftWarnings($records, 'channels'),
            'Ownership does not change when a company is soft-deleted, so its channels are not drift.',
        );
        $this->assertNull(
            ChannelWebhookDirectoryEntry::query()->find($ghostId),
            'A false-positive drift signal must not disable the prune for a healthy tenant.',
        );
    }

    /**
     * @param  ArrayObject<int, MessageLogged>  $records
     */
    private function driftWarnings(ArrayObject $records, string $resource): int
    {
        $matching = array_filter(
            $records->getArrayCopy(),
            static fn (MessageLogged $message): bool => $message->level === 'warning'
                && ($message->context['resource'] ?? null) === $resource
                && array_key_exists('rows_dropped', $message->context),
        );

        return count($matching);
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
            'name' => "Channel Company {$suffix}",
            'legal_name' => "Channel Company {$suffix} LLC",
            'tax_id' => "TAX-CHN-DPT-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createChannel(Company $company, string $name): Channel
    {
        return Channel::create([
            'company_id' => $company->id,
            'name' => $name,
            'adapter_type' => 'shopify',
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Connected,
        ]);
    }
}
