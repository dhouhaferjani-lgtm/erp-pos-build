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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class VerifyEventChainFleetCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $manifestPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        $files = $this->app->make(Filesystem::class);
        foreach ($this->manifestPaths as $path) {
            $files->delete($path);
        }

        parent::tearDown();
    }

    public function test_clean_multi_tenant_manifest_verifies_every_tenant_with_per_tenant_output(): void
    {
        $first = $this->createTenantChain('fleet-one');
        $second = $this->createTenantChain('fleet-two');
        $manifest = $this->writeManifest([
            $first['tenant']->id => $first['actor']->id,
            $second['tenant']->id => $second['actor']->id,
        ]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $first['tenant']->id))
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $second['tenant']->id))
            ->expectsOutputToContain('fleet chain verification completed: 2 tenant(s), 2 chain(s), no failures')
            ->assertExitCode(0);
    }

    public function test_directory_tenant_missing_from_manifest_is_reported_and_fails_aggregate(): void
    {
        $listed = $this->createTenantChain('listed');
        $missing = $this->createTenantChain('missing');
        $manifest = $this->writeManifest([$listed['tenant']->id => $listed['actor']->id]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('TENANT %s: MISSING FROM MANIFEST', $missing['tenant']->id))
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $listed['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_unknown_manifest_tenant_is_reported_and_fails_aggregate(): void
    {
        $known = $this->createTenantChain('known');
        $unknownTenantId = Str::uuid()->toString();
        $manifest = $this->writeManifest([
            $known['tenant']->id => $known['actor']->id,
            $unknownTenantId => Str::uuid()->toString(),
        ]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('TENANT %s: UNKNOWN TENANT', $unknownTenantId))
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $known['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_manifest_tenant_with_no_fiscal_event_pairs_fails_loudly_instead_of_verifying_zero_chains(): void
    {
        $fixture = $this->createTenantChain('no-chain-data', seedChain: false);
        $manifest = $this->writeManifest([$fixture['tenant']->id => $fixture['actor']->id]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf(
                'TENANT %s: NO-DATA — fiscal_events contains no distinct (terminal_id, chain_context) pairs',
                $fixture['tenant']->id,
            ))
            ->doesntExpectOutputToContain(sprintf('TENANT %s: VERIFIED 0 chain(s)', $fixture['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_unauthorized_actor_is_reported_by_the_existing_actor_gate(): void
    {
        $fixture = $this->createTenantChain('unauthorized', grantPermission: false);
        $manifest = $this->writeManifest([$fixture['tenant']->id => $fixture['actor']->id]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf(
                'Actor %s lacks the fiscal.events.verify_chain permission',
                $fixture['actor']->id,
            ))
            ->expectsOutputToContain(sprintf('TENANT %s: FAILED', $fixture['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_actor_from_another_tenant_cannot_authorize_manifest_entry(): void
    {
        $first = $this->createTenantChain('bound-first');
        $second = $this->createTenantChain('bound-second');
        $manifest = $this->writeManifest([
            $first['tenant']->id => $second['actor']->id,
            $second['tenant']->id => $second['actor']->id,
        ]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('Unknown actor user id %s', $second['actor']->id))
            ->expectsOutputToContain(sprintf('TENANT %s: FAILED', $first['tenant']->id))
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $second['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_malformed_json_manifest_is_rejected_before_any_chain_is_verified(): void
    {
        $fixture = $this->createTenantChain('malformed-json');
        $manifest = $this->writeRawManifest('{not-json');

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain('Malformed manifest JSON')
            ->doesntExpectOutputToContain(sprintf('TENANT %s: VERIFIED', $fixture['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_malformed_manifest_entry_is_rejected_before_any_chain_is_verified(): void
    {
        $fixture = $this->createTenantChain('malformed-entry');
        $manifest = $this->writeManifest([$fixture['tenant']->id => 42]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('Malformed manifest entry for tenant %s', $fixture['tenant']->id))
            ->doesntExpectOutputToContain(sprintf('TENANT %s: VERIFIED', $fixture['tenant']->id))
            ->assertExitCode(1);
    }

    public function test_one_broken_chain_fails_aggregate_while_other_tenants_still_run(): void
    {
        $clean = $this->createTenantChain('aggregate-clean');
        $broken = $this->createTenantChain('aggregate-broken', validHash: false);
        $manifest = $this->writeManifest([
            $clean['tenant']->id => $clean['actor']->id,
            $broken['tenant']->id => $broken['actor']->id,
        ]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 1 chain(s)', $clean['tenant']->id))
            ->expectsOutputToContain(sprintf('TENANT %s: FAILED', $broken['tenant']->id))
            ->expectsOutputToContain('current_hash mismatch')
            ->assertExitCode(1);
    }

    public function test_enumerates_distinct_terminal_and_context_pairs_present_in_fiscal_events(): void
    {
        $fixture = $this->createTenantChain('enumeration');
        $this->insertEvent(
            tenantId: $fixture['tenant']->id,
            companyId: $fixture['company']->id,
            terminalId: $fixture['terminal']->id,
            chainContext: 'z_session',
            genesisSeed: $fixture['genesis_seed'],
        );

        $secondTerminal = Terminal::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'company_id' => $fixture['company']->id,
            'location_id' => $fixture['location']->id,
            'genesis_seed' => $fixture['genesis_seed'],
        ]);
        $this->insertEvent(
            tenantId: $fixture['tenant']->id,
            companyId: $fixture['company']->id,
            terminalId: $secondTerminal->id,
            chainContext: 'operational',
            genesisSeed: $fixture['genesis_seed'],
        );

        $manifest = $this->writeManifest([$fixture['tenant']->id => $fixture['actor']->id]);

        $this->artisan('fiscal:verify-event-chain-fleet', ['--manifest' => $manifest])
            ->expectsOutputToContain('enumerated 3 distinct (terminal_id, chain_context) pair(s) from fiscal_events')
            ->expectsOutputToContain(sprintf('terminal=%s context=operational', $fixture['terminal']->id))
            ->expectsOutputToContain(sprintf('terminal=%s context=z_session', $fixture['terminal']->id))
            ->expectsOutputToContain(sprintf('terminal=%s context=operational', $secondTerminal->id))
            ->expectsOutputToContain(sprintf('TENANT %s: VERIFIED 3 chain(s)', $fixture['tenant']->id))
            ->assertExitCode(0);
    }

    /**
     * @return array{tenant: Tenant, company: Company, location: Location, terminal: Terminal, actor: User, genesis_seed: string}
     */
    private function createTenantChain(
        string $slug,
        bool $grantPermission = true,
        bool $validHash = true,
        bool $seedChain = true,
    ): array {
        $tenant = Tenant::factory()->create(['slug' => $slug]);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);

        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $genesisSeed = hash('sha256', 'genesis-'.$slug);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => $genesisSeed,
        ]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        if ($grantPermission) {
            $actor->givePermissionTo('fiscal.events.verify_chain');
        }

        if ($seedChain) {
            $this->insertEvent(
                tenantId: $tenant->id,
                companyId: $company->id,
                terminalId: $terminal->id,
                chainContext: 'operational',
                genesisSeed: $genesisSeed,
                validHash: $validHash,
            );
        }

        return [
            'tenant' => $tenant,
            'company' => $company,
            'location' => $location,
            'terminal' => $terminal,
            'actor' => $actor,
            'genesis_seed' => $genesisSeed,
        ];
    }

    private function insertEvent(
        string $tenantId,
        string $companyId,
        string $terminalId,
        string $chainContext,
        string $genesisSeed,
        bool $validHash = true,
    ): void {
        $canonicalBytes = json_encode([
            'chain_context' => $chainContext,
            'event' => 'fleet_fixture',
            'terminal_id' => $terminalId,
        ], JSON_THROW_ON_ERROR);

        DB::table('fiscal_events')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'operator_id' => Str::uuid()->toString(),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => Carbon::now('UTC'),
            'business_date' => Carbon::now('UTC')->startOfDay(),
            'chain_context' => $chainContext,
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
            'current_hash' => $validHash ? hash('sha256', $canonicalBytes) : str_repeat('f', 64),
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * @param  array<string, string|int>  $manifest
     */
    private function writeManifest(array $manifest): string
    {
        return $this->writeRawManifest(json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function writeRawManifest(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'autoerp-fiscal-fleet-');
        $this->assertIsString($path);
        $this->app->make(Filesystem::class)->put($path, $contents);
        $this->manifestPaths[] = $path;

        return $path;
    }
}
