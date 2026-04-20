<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Domain\Events\TerminalActivatedAudit;
use App\Modules\POS\Domain\Events\TerminalDeactivated;
use App\Modules\POS\Domain\Events\TerminalSoftwareUpdated;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for terminal lifecycle events (NF525 compliance).
 *
 * Covers:
 * - Terminal activation audit trail
 * - Terminal deactivation audit trail
 * - Software version change audit trail
 * - Terminal events in JET XML export
 */
final class TerminalLifecycleEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private Terminal $terminal;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant Lifecycle',
            'slug' => 'test-lifecycle-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company Lifecycle',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin-lifecycle-'.Str::random(8).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->location = Location::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main Store',
            'type' => 'shop',
            'is_active' => true,
        ]);

        $this->terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'code' => 'POS01',
            'name' => 'Terminal 1',
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => 2026,
            'is_active' => false,
            'hardware_identifier' => 'HW-LIFECYCLE-001',
        ]);
    }

    public function test_activation_creates_audit_trail_entry(): void
    {
        // Keep the broadcast event faked but let domain events flow through
        Event::fake([TerminalActivated::class]);

        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/activate");

        $response->assertStatus(200);

        // Verify audit event was created
        $auditEvent = AuditEvent::where('company_id', $this->company->id)
            ->where('event_type', 'terminal.activated')
            ->where('aggregate_type', 'Terminal')
            ->where('aggregate_id', $this->terminal->id)
            ->first();

        $this->assertNotNull($auditEvent, 'Terminal activation should create an audit trail entry');
        $this->assertEquals('terminal.activated', $auditEvent->event_type);

        $payload = $auditEvent->payload;
        $this->assertEquals($this->terminal->code, $payload['terminal_code']);
        $this->assertEquals($this->adminUser->id, $payload['activated_by']);
    }

    public function test_deactivation_creates_audit_trail_entry(): void
    {
        // Start with an active terminal
        $this->terminal->update(['is_active' => true, 'activated_at' => now()]);

        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/deactivate", [
            'reason' => 'Maintenance required',
        ]);

        $response->assertStatus(200);

        // Verify audit event was created
        $auditEvent = AuditEvent::where('company_id', $this->company->id)
            ->where('event_type', 'terminal.deactivated')
            ->where('aggregate_type', 'Terminal')
            ->where('aggregate_id', $this->terminal->id)
            ->first();

        $this->assertNotNull($auditEvent, 'Terminal deactivation should create an audit trail entry');
        $this->assertEquals('terminal.deactivated', $auditEvent->event_type);

        $payload = $auditEvent->payload;
        $this->assertEquals($this->terminal->code, $payload['terminal_code']);
        $this->assertEquals('Maintenance required', $payload['reason']);
        $this->assertEquals($this->adminUser->id, $payload['deactivated_by']);
    }

    public function test_deactivation_without_reason_creates_audit_trail(): void
    {
        $this->terminal->update(['is_active' => true, 'activated_at' => now()]);

        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/deactivate");

        $response->assertStatus(200);

        $auditEvent = AuditEvent::where('company_id', $this->company->id)
            ->where('event_type', 'terminal.deactivated')
            ->where('aggregate_id', $this->terminal->id)
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertEquals('', $auditEvent->payload['reason']);
    }

    public function test_software_version_change_creates_audit_entry(): void
    {
        // Set initial version
        $this->terminal->update([
            'is_active' => true,
            'activated_at' => now(),
            'pos_software_version' => '1.0.0',
        ]);

        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'pos_software_version' => '1.1.0',
        ]);

        $response->assertStatus(200);

        // Verify audit event was created
        $auditEvent = AuditEvent::where('company_id', $this->company->id)
            ->where('event_type', 'terminal.software_updated')
            ->where('aggregate_type', 'Terminal')
            ->where('aggregate_id', $this->terminal->id)
            ->first();

        $this->assertNotNull($auditEvent, 'Software version change should create an audit trail entry');
        $this->assertEquals('terminal.software_updated', $auditEvent->event_type);

        $payload = $auditEvent->payload;
        $this->assertEquals($this->terminal->code, $payload['terminal_code']);
        $this->assertEquals('1.0.0', $payload['previous_version']);
        $this->assertEquals('1.1.0', $payload['new_version']);
    }

    public function test_software_version_same_value_does_not_create_audit_entry(): void
    {
        $this->terminal->update([
            'is_active' => true,
            'activated_at' => now(),
            'pos_software_version' => '1.0.0',
        ]);

        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'pos_software_version' => '1.0.0',
        ]);

        $response->assertStatus(200);

        $auditEventCount = AuditEvent::where('company_id', $this->company->id)
            ->where('event_type', 'terminal.software_updated')
            ->count();

        $this->assertEquals(0, $auditEventCount, 'Same version should not create audit entry');
    }

    public function test_terminal_lifecycle_events_appear_in_jet_export(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Create audit events for terminal lifecycle
        $this->createAuditEvent('terminal.activated', [
            'terminal_code' => $this->terminal->code,
            'activated_by' => $this->adminUser->id,
        ]);

        $this->createAuditEvent('terminal.deactivated', [
            'terminal_code' => $this->terminal->code,
            'reason' => 'End of day',
            'deactivated_by' => $this->adminUser->id,
        ]);

        $this->createAuditEvent('terminal.software_updated', [
            'terminal_code' => $this->terminal->code,
            'previous_version' => '1.0.0',
            'new_version' => '1.1.0',
        ]);

        // Make the terminal active so it's included in the export
        $this->terminal->update(['is_active' => true, 'activated_at' => now()]);

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->endOfYear()->toDateString(),
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $xml = $response->getContent();
        $this->assertNotEmpty($xml);

        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml), 'JET export should produce valid XML');

        // Verify the EvenementsTerminal section exists
        $terminalEventsSection = $doc->getElementsByTagName('EvenementsTerminal');
        $this->assertGreaterThan(0, $terminalEventsSection->length,
            'JET XML should contain EvenementsTerminal section');

        $section = $terminalEventsSection->item(0);
        $this->assertNotNull($section);
        $this->assertEquals('3', $section->getAttribute('count'));

        // Verify individual event types are present
        $eventElements = $doc->getElementsByTagName('EvenementTerminal');
        $this->assertEquals(3, $eventElements->length);

        $eventTypes = [];
        for ($i = 0; $i < $eventElements->length; $i++) {
            $eventTypes[] = $eventElements->item($i)->getAttribute('type');
        }

        $this->assertContains('ACTIVATION_TERMINAL', $eventTypes);
        $this->assertContains('DESACTIVATION_TERMINAL', $eventTypes);
        $this->assertContains('MAJ_LOGICIEL', $eventTypes);
    }

    public function test_jet_export_header_includes_software_version(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Make the terminal active
        $this->terminal->update(['is_active' => true, 'activated_at' => now()]);

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->endOfYear()->toDateString(),
        ]);

        $response->assertStatus(200);

        $xml = $response->getContent();
        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        // Verify header contains VersionLogiciel
        $versionElements = $doc->getElementsByTagName('VersionLogiciel');
        $this->assertGreaterThan(0, $versionElements->length);
        $this->assertNotEmpty($versionElements->item(0)->textContent);
    }

    /**
     * Create an audit event for testing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createAuditEvent(string $eventType, array $payload): AuditEvent
    {
        $event = new AuditEvent(
            companyId: $this->company->id,
            userId: $this->adminUser->id,
            eventType: $eventType,
            aggregateType: 'Terminal',
            aggregateId: $this->terminal->id,
            payload: $payload,
            metadata: [
                'event_class' => match ($eventType) {
                    'terminal.activated' => TerminalActivatedAudit::class,
                    'terminal.deactivated' => TerminalDeactivated::class,
                    'terminal.software_updated' => TerminalSoftwareUpdated::class,
                    default => $eventType,
                },
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
            ],
            attributes: [
                'tenant_id' => $this->tenant->id,
            ]
        );

        $event->save();

        return $event;
    }
}
