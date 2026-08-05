<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

/**
 * `pos:verify-chains` under the PRODUCTION tenancy mode (`db_per_tenant=true`).
 *
 * Wave-2 review R4 (fiscal) / R5 (tenancy): every other test in the wave runs
 * in single-schema compatibility mode, where `tenancy()->initialize()` is a
 * no-op and every connection points at the same database. That makes the suite
 * structurally blind to the entire class of defect the wave exists to close —
 * a collaborator that captured a connection at CONSTRUCTION time stays pinned
 * to CENTRAL while the command believes it is reading the tenant's database.
 *
 * This leg binds a real per-tenant SQLite database, builds the fixture INSIDE
 * it, and plants a contradicting row in CENTRAL. A verifier that reads the
 * bound tenant reports the tenant's truth; a central-pinned one reports
 * central's. The two answers are deliberately opposite, so the pin cannot hide.
 */
final class VerifyPosChainCommandDbPerTenantTest extends TestCase
{
    use ProvisionsTenantDatabases;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * B1 regression (both wave-2 reviews).
     *
     * `ReceiptHashService` took a `ConnectionInterface` in its constructor, so
     * the connection was resolved when the console kernel built the command —
     * before `forEachTenant()` ever calls `tenancy()->initialize()`. Its
     * authoritative arm (`verifyTerminalChainFiscalArm`) therefore read
     * `fiscal_events` from CENTRAL.
     *
     * Fixture: the tenant's own database holds a terminal with a clean legacy
     * receipt chain and NO fiscal_events rows. CENTRAL holds a fiscal_events
     * row for the same terminal whose `current_hash` does not match its
     * `canonical_bytes`. Reading the bound tenant ⇒ verified (exit 0); reading
     * central ⇒ `hash_mismatch` ⇒ "chain verification FAILED" (exit 1).
     */
    public function test_the_fiscal_arm_reads_the_bound_tenant_database_not_central(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $terminalId = $this->withinTenantDatabase(
            $tenant,
            fn (): string => $this->seedCleanLegacyChain($tenant),
        );

        // CENTRAL carries a corrupted chain for the SAME terminal. Nothing in
        // the bound tenant's database can see it.
        $this->plantCorruptedFiscalEventInCentral($tenant, $terminalId);

        $this->artisan('pos:verify-chains', ['--tenant' => $tenant->id, '--type' => 'receipts'])
            ->expectsOutputToContain('All chains verified successfully.')
            ->assertExitCode(0);
    }

    /**
     * The other half of the same proof: when the corruption really IS in the
     * bound tenant's database, the verifier must still catch it. Without this
     * the test above could be satisfied by a verifier that reads nothing at all.
     */
    public function test_a_corrupted_fiscal_event_inside_the_bound_tenant_is_caught(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create());

        $this->withinTenantDatabase($tenant, function () use ($tenant): void {
            $terminalId = $this->seedCleanLegacyChain($tenant);
            $this->insertCorruptedFiscalEvent(DB::connection(), $tenant, $terminalId);
        });

        $this->artisan('pos:verify-chains', ['--tenant' => $tenant->id, '--type' => 'receipts'])
            ->expectsOutputToContain('chain verification FAILED')
            ->assertExitCode(1);
    }

    /**
     * Seed a terminal whose legacy (`fiscal_event_id IS NULL`) receipt chain
     * verifies clean, so `verifyReceiptChain()` gets past its `count === 0`
     * short-circuit and actually reaches the authoritative fiscal arm.
     *
     * @return string the terminal id
     */
    private function seedCleanLegacyChain(Tenant $tenant): string
    {
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'chain_sequence' => 1,
            'previous_hash' => null,
            'fiscal_event_id' => null,
            'fiscal_status' => FiscalStatus::Fiscalized->value,
            'is_voided' => false,
            'is_training' => false,
            'sealed_hash_algorithm' => null,
        ]);

        $receipt->setRelation('terminal', $terminal);

        /** @var ReceiptHashService $hashService */
        $hashService = app(ReceiptHashService::class);
        $sealed = $hashService->calculateHash($receipt, null);

        $receipt->forceFill(['fiscal_hash' => $sealed])->save();
        $terminal->forceFill(['last_hash' => $sealed])->save();

        return (string) $terminal->id;
    }

    private function plantCorruptedFiscalEventInCentral(Tenant $tenant, string $terminalId): void
    {
        $this->insertCorruptedFiscalEvent(DB::connection(), $tenant, $terminalId);
    }

    private function insertCorruptedFiscalEvent(mixed $connection, Tenant $tenant, string $terminalId): void
    {
        $connection->table('fiscal_events')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => (string) Str::uuid(),
            'terminal_id' => $terminalId,
            'operator_id' => (string) Str::uuid(),
            'event_type' => 'SALE_RECEIPT',
            'event_version' => 1,
            'signature_version' => 'v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-08-05 10:00:00',
            'business_date' => '2026-08-05',
            'chain_context' => 'operational',
            'server_received_at' => '2026-08-05 10:00:01',
            'canonical_bytes' => 'these-bytes-do-not-hash-to-the-stored-current-hash',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'created_at' => '2026-08-05 10:00:01',
        ]);
    }
}
