<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Console\TenantScopedCommand;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Notifications\CriticalBatchExpiryNotification;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `batch-expiry:daily-check` — the TenantScopedCommand replacement for the
 * deleted `DailyExpiryCheck` queue job (staging: fails daily at 01:30 with
 * MaxAttemptsExceeded because it queried `product_batches` from CENTRAL
 * context under database-per-tenant).
 */
final class BatchExpiryDailyCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_command_marks_expired_batches_and_notifies_each_tenant_exactly_once(): void
    {
        $this->travelTo('2026-05-24 08:00:00');

        $fixtureA = $this->createTenantFixture('batch-daily-a', 'A');
        $fixtureB = $this->createTenantFixture('batch-daily-b', 'B');

        Notification::fake();
        app(PermissionRegistrar::class)->setPermissionsTeamId('original-team-id');

        $exitCode = Artisan::call('batch-expiry:daily-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // The registrar's team id must be restored to whatever the caller had.
        $this->assertSame('original-team-id', app(PermissionRegistrar::class)->getPermissionsTeamId());

        $this->assertTrue($fixtureA['expiredBatch']->refresh()->is_expired);
        $this->assertTrue($fixtureB['expiredBatch']->refresh()->is_expired);
        $this->assertFalse($fixtureA['criticalBatch']->refresh()->is_expired);
        $this->assertFalse($fixtureA['farBatch']->refresh()->is_expired);

        // Per-tenant scoping (plan amendment A3): every batch query filters on
        // the closure's tenant, so in legacy row-level mode the N passes do NOT
        // re-notify the same admins N times.
        Notification::assertSentToTimes($fixtureA['user'], CriticalBatchExpiryNotification::class, 1);
        Notification::assertSentToTimes($fixtureB['user'], CriticalBatchExpiryNotification::class, 1);

        // Each admin only ever sees their own tenant's batches.
        Notification::assertSentTo(
            $fixtureA['user'],
            CriticalBatchExpiryNotification::class,
            function (CriticalBatchExpiryNotification $notification) use ($fixtureA): bool {
                /** @var array{batches: list<array{batch_number: string}>} $payload */
                $payload = $notification->toArray($fixtureA['user']);
                $numbers = array_column($payload['batches'], 'batch_number');

                return $numbers === ['CRIT-A'];
            },
        );

        $this->assertStringContainsString('Batch expiry check: 2 batch(es) marked expired', $output);
    }

    public function test_command_is_registered_with_scheduler_and_the_old_job_entry_is_gone(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('batch-expiry:daily-check', $output);
        $this->assertStringNotContainsString('DailyExpiryCheck', $output);
    }

    /**
     * db-per-tenant probe (plan amendment A5): without this, a command that
     * skipped `forEachTenant()` would still pass every other assertion in this
     * file. Modeled on
     * tests/Feature/Accounting/SubledgerReconciliationCommandTest.php:38-64.
     */
    public function test_for_each_tenant_enters_and_ends_tenant_context_in_db_per_tenant_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenantA = $this->createTenant('batch-daily-probe-a');
        $tenantB = $this->createTenant('batch-daily-probe-b');

        $command = new BatchExpiryDailyCheckProbeCommand(app(CompanyContext::class));

        $seen = [];

        $exitCode = $command->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = [
                'argument' => $tenant->id,
                'helper' => tenant('id'),
                'initialized' => tenancy()->initialized,
            ];

            return 0;
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'argument'));
        $this->assertSame([$tenantA->id, $tenantB->id], array_column($seen, 'helper'));
        $this->assertSame([true, true], array_column($seen, 'initialized'));
        $this->assertFalse(tenancy()->initialized);
        $this->assertNull(tenancy()->tenant);
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

    /**
     * @return array{tenant: Tenant, user: User, expiredBatch: Batch, criticalBatch: Batch, farBatch: Batch}
     */
    private function createTenantFixture(string $slug, string $suffix): array
    {
        $tenant = $this->createTenant($slug);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Batch Company {$suffix}",
            'legal_name' => "Batch Company {$suffix} LLC",
            'tax_id' => "TAX-BATCH-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => "Batch Admin {$suffix}",
            'email' => 'batch-admin-'.strtolower($suffix).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->givePermissionTo('batches.view');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => "Batch Product {$suffix}",
            'sku' => "SKU-BATCH-{$suffix}",
            'type' => 'part',
            'is_active' => true,
            'cost_price' => '4.00',
        ]);

        $expiredBatch = $this->createBatch($tenant, $company, $product, "EXP-{$suffix}", now()->subDay());
        $criticalBatch = $this->createBatch($tenant, $company, $product, "CRIT-{$suffix}", now()->addDays(3));
        $farBatch = $this->createBatch($tenant, $company, $product, "FAR-{$suffix}", now()->addDays(120));

        return [
            'tenant' => $tenant,
            'user' => $user,
            'expiredBatch' => $expiredBatch,
            'criticalBatch' => $criticalBatch,
            'farBatch' => $farBatch,
        ];
    }

    private function createBatch(
        Tenant $tenant,
        Company $company,
        Product $product,
        string $batchNumber,
        \DateTimeInterface $expiryDate,
    ): Batch {
        return Batch::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);
    }
}

/**
 * @cross-tenant-by-design Test-only probe subclass (never registered as an
 * Artisan command) that exposes the protected forEachTenant() helper so the
 * db-per-tenant iteration contract can be asserted directly.
 */
final class BatchExpiryDailyCheckProbeCommand extends TenantScopedCommand
{
    protected function executeCommand(): int
    {
        return self::SUCCESS;
    }

    /**
     * @param  callable(Tenant): int  $fn
     */
    public function runForEachTenant(callable $fn): int
    {
        return $this->forEachTenant($fn);
    }
}
