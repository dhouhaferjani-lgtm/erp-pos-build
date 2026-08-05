<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Application\Jobs\ChannelReconciliationJob;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * `channels:reconcile` — the TenantScopedCommand replacement for the
 * `$schedule->call(closure)` registration in ChannelServiceProvider.
 *
 * The closure was the WORST shape of the 2026-05-28 database-per-tenant
 * breakage: it opened with `if (! Schema::hasTable('channels')) return;`, and
 * `channels` is a TENANT table that does not exist on the central connection
 * the scheduler runs on. So every nightly tick returned cleanly, the scheduler
 * recorded a success, and no log line, no `failed_jobs` row and no non-zero
 * exit was ever produced — the reconciliation had been silently dead for
 * months.
 */
final class ChannelReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_it_dispatches_one_reconciliation_job_per_active_channel_carrying_the_iterating_tenant(): void
    {
        Queue::fake();

        $tenantA = $this->createTenant('channel-recon-a');
        $tenantB = $this->createTenant('channel-recon-b');

        $channelA1 = $this->createChannel($this->createCompany($tenantA, 'A1'), 'A1');
        $channelA2 = $this->createChannel($this->createCompany($tenantA, 'A2'), 'A2');
        $channelB1 = $this->createChannel($this->createCompany($tenantB, 'B1'), 'B1');

        $exitCode = Artisan::call('channels:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 3 channel reconciliation job(s).', $output);

        Queue::assertPushed(ChannelReconciliationJob::class, 3);

        $this->assertSame(
            $this->sorted([$channelA1->id, $channelA2->id, $channelB1->id]),
            $this->dispatchedValues('channelId'),
        );
        $this->assertSame(
            $this->sorted([$tenantA->id, $tenantA->id, $tenantB->id]),
            $this->dispatchedValues('tenantId'),
        );
    }

    public function test_it_skips_inactive_channels(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-recon-inactive');
        $company = $this->createCompany($tenant, 'INACTIVE');

        $active = $this->createChannel($company, 'live');
        $this->createChannel($company, 'dormant', isActive: false);

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        Queue::assertPushed(ChannelReconciliationJob::class, 1);
        $this->assertSame([$active->id], $this->dispatchedValues('channelId'));
    }

    /**
     * The behavioural guard that the command genuinely ITERATES the tenant
     * directory rather than issuing one fleet-wide `Channel::query()`.
     *
     * A company whose `tenant_id` has no row in the central `tenants` table is
     * unreachable by `forEachTenant()` — no iteration slot ever opens for it —
     * so its channel must never be dispatched. A non-iterating implementation
     * (the closure this command replaced) would sweep it up.
     */
    public function test_a_channel_whose_tenant_is_absent_from_the_directory_is_never_dispatched(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-recon-real');
        $reachable = $this->createChannel($this->createCompany($tenant, 'REAL'), 'real');

        $orphanCompany = Company::create([
            'tenant_id' => (string) Str::uuid(),
            'name' => 'Orphan Company',
            'legal_name' => 'Orphan Company LLC',
            'tax_id' => 'TAX-CHN-ORPHAN',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $this->createChannel($orphanCompany, 'orphan');

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        Queue::assertPushed(ChannelReconciliationJob::class, 1);
        $this->assertSame([$reachable->id], $this->dispatchedValues('channelId'));
    }

    /**
     * R2 (2026-08-05 review). `ChannelWebhookDirectoryRegistrar::forget()` is
     * wired to the Eloquent `deleted` event ONLY, so a channel removed by raw
     * SQL, a `truncate`, or a tenant database restored to a pre-channel
     * snapshot leaves its central pointer behind forever. That row is not
     * inert: ChannelWebhookController binds tenancy — a full database switch —
     * BEFORE it discovers the channel row is gone, so every stale pointer sells
     * an anonymous caller exactly the cost the fail-closed design exists to
     * avoid. The nightly sweep is the only place that already reads every
     * channel of every tenant, so it is where the prune belongs.
     */
    public function test_the_sweep_prunes_a_pointer_whose_channel_no_longer_exists_in_the_tenant(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-recon-prune');
        $live = $this->createChannel($this->createCompany($tenant, 'LIVE'), 'live');

        // A channel deleted outside Eloquent: the observer never fired, so the
        // pointer survived.
        $ghostId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $ghostId,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertNull(
            ChannelWebhookDirectoryEntry::query()->find($ghostId),
            'A pointer whose channel is absent from the owning tenant must be pruned.',
        );
        $this->assertNotNull(
            ChannelWebhookDirectoryEntry::query()->find($live->id),
            'The prune must never touch a pointer whose channel is still there.',
        );
    }

    /**
     * The deprovisioning half of R2: a tenant's pointers survive the tenant
     * itself, and no per-tenant slot ever opens for a row that is gone from the
     * central directory — so that leg cannot live inside the iteration.
     */
    public function test_the_sweep_prunes_pointers_whose_tenant_is_gone_from_the_directory(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-recon-prune-tenant');
        $live = $this->createChannel($this->createCompany($tenant, 'KEEP'), 'keep');

        $orphanId = (string) Str::uuid();
        ChannelWebhookDirectoryEntry::query()->create([
            'channel_id' => $orphanId,
            'tenant_id' => (string) Str::uuid(),
        ]);

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertNull(ChannelWebhookDirectoryEntry::query()->find($orphanId));
        $this->assertNotNull(ChannelWebhookDirectoryEntry::query()->find($live->id));
    }

    /**
     * A tenant that owns channels but none registered yet must not have the
     * prune mistake its own live channels for stale rows — the self-heal
     * `register()` runs first, so by prune time the pointer set is complete.
     */
    public function test_the_sweep_does_not_prune_the_pointers_it_just_backfilled(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('channel-recon-prune-heal');
        $channel = $this->createChannel($this->createCompany($tenant, 'HEAL'), 'heal');

        ChannelWebhookDirectoryEntry::query()->whereKey($channel->id)->delete();

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        $this->assertNotNull(ChannelWebhookDirectoryEntry::query()->find($channel->id));
    }

    public function test_it_dispatches_nothing_when_no_channel_exists(): void
    {
        Queue::fake();

        $this->createTenant('channel-recon-empty');

        $this->assertSame(0, Artisan::call('channels:reconcile'));

        Queue::assertNothingPushed();
    }

    public function test_the_command_is_registered_with_the_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan channels:reconcile', $output);
    }

    /**
     * @return list<string>
     */
    private function dispatchedValues(string $property): array
    {
        $values = Queue::pushed(ChannelReconciliationJob::class)
            ->map(static function (object $job) use ($property): string {
                return (string) (new ReflectionProperty($job, $property))->getValue($job);
            })
            ->all();

        return $this->sorted($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(Tenant $tenant, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Channel Company {$suffix}",
            'legal_name' => "Channel Company {$suffix} LLC",
            'tax_id' => "TAX-CHN-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createChannel(Company $company, string $name, bool $isActive = true): Channel
    {
        return Channel::create([
            'company_id' => $company->id,
            'name' => $name,
            'adapter_type' => 'shopify',
            'is_active' => $isActive,
            'connection_status' => ChannelConnectionStatus::Connected,
        ]);
    }
}
