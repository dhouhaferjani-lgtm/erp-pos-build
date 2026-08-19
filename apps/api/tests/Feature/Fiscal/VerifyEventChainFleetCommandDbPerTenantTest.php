<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Physical PostgreSQL acceptance leg for the fleet wrapper's parent/child
 * tenancy lifecycle. Compatibility mode cannot exercise the child command
 * re-binding after the fleet enumerator has ended its own tenant context.
 */
final class VerifyEventChainFleetCommandDbPerTenantTest extends TestCase
{
    private ?Tenant $tenant = null;

    private ?string $manifestPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The fiscal fleet DB-per-tenant acceptance leg is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($this->manifestPath !== null) {
            $this->app->make(Filesystem::class)->delete($this->manifestPath);
        }

        if ($this->tenant !== null) {
            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (Throwable) {
                // Best effort; this acceptance lane always uses a disposable DB.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_fleet_enumeration_and_child_verification_rebind_the_physical_tenant_database(): void
    {
        $this->tenant = Tenant::factory()->create([
            'slug' => 'fiscal-fleet-'.Str::lower(Str::random(8)),
        ]);
        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));

        tenancy()->initialize($this->tenant);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $genesisSeed = hash('sha256', 'physical-fleet-'.$this->tenant->id);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => $genesisSeed,
        ]);
        $actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $actor->givePermissionTo('fiscal.events.verify_chain');
        $eventId = $this->insertTenantEvent($company, $terminal, $actor, $genesisSeed);
        tenancy()->end();

        $this->assertFalse(
            DB::connection('central')->table('fiscal_events')->where('id', $eventId)->exists(),
            'The load-bearing chain fixture must exist only in the physical tenant database.',
        );

        $path = tempnam(sys_get_temp_dir(), 'autoerp-fiscal-fleet-physical-');
        $this->assertIsString($path);
        $this->manifestPath = $path;
        $this->app->make(Filesystem::class)->put($path, json_encode([
            $this->tenant->id => $actor->id,
        ], JSON_THROW_ON_ERROR));

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $path])
            ->expectsOutputToContain('enumerated 1 distinct (terminal_id, chain_context) pair(s) from fiscal_events')
            ->expectsOutputToContain(sprintf('terminal=%s context=operational', $terminal->id))
            ->expectsOutputToContain('chain verified')
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $this->tenant->id))
            ->assertExitCode(0);

        $this->assertFalse(tenancy()->initialized);
    }

    private function insertTenantEvent(
        Company $company,
        Terminal $terminal,
        User $actor,
        string $genesisSeed,
    ): string {
        $eventId = (string) Str::uuid();
        $payload = [
            'last_good_hash' => str_repeat('b', 64),
            'last_good_sequence' => 0,
            'offending_record_reference' => [
                'observed_previous_hash' => $genesisSeed,
                'sequence_number' => 1,
                'terminal_id' => $terminal->id,
            ],
            'reason' => 'physical DB-per-tenant fleet fixture',
        ];
        $envelope = [
            'business_date' => '2026-08-12',
            'chain_context' => 'operational',
            'company_id' => $company->id,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'event_type' => FiscalEventType::CHAIN_BREAK_DETECTED->value,
            'event_version' => 1,
            'operator_id' => $actor->id,
            'payload' => $payload,
            'previous_hash' => $genesisSeed,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenant?->id,
            'terminal_id' => $terminal->id,
        ];
        ksort($envelope);
        $canonicalBytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        DB::table('fiscal_events')->insert([
            'id' => $eventId,
            'tenant_id' => $this->tenant?->id,
            'company_id' => $company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $actor->id,
            'event_type' => FiscalEventType::CHAIN_BREAK_DETECTED->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'business_date' => '2026-08-12',
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'server_received_at' => Carbon::now('UTC'),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $genesisSeed,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => Carbon::now('UTC'),
        ]);

        return $eventId;
    }
}
