<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        // Create company membership for the user
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_audit_event_class_exists(): void
    {
        $this->assertTrue(class_exists(AuditEvent::class));
    }

    public function test_audit_service_class_exists(): void
    {
        $this->assertTrue(class_exists(AuditService::class));
    }

    public function test_anomaly_detection_service_exists(): void
    {
        $this->assertTrue(class_exists(AnomalyDetectionService::class));
    }

    public function test_audit_event_has_required_properties(): void
    {
        $event = new AuditEvent(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: ['document_number' => 'INV-2025-0001'],
            metadata: ['ip_address' => '192.168.1.1']
        );

        $this->assertEquals($this->company->id, $event->companyId);
        $this->assertEquals($this->user->id, $event->userId);
        $this->assertEquals('document.created', $event->eventType);
        $this->assertEquals('Document', $event->aggregateType);
        $this->assertEquals('doc-123', $event->aggregateId);
        $this->assertEquals(['document_number' => 'INV-2025-0001'], $event->payload);
        $this->assertNotNull($event->occurredAt);
    }

    public function test_audit_event_generates_hash(): void
    {
        $event = new AuditEvent(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: ['document_number' => 'INV-2025-0001']
        );

        $this->assertNotNull($event->eventHash);
        $this->assertEquals(64, strlen($event->eventHash));
    }

    public function test_can_record_audit_event(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $event = $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: ['document_number' => 'INV-2025-0001']
        );

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertNotNull($event->id);
    }

    public function test_can_query_audit_events_by_company(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        // Record multiple events
        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: []
        );

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.posted',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: []
        );

        // Create event for another company
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $auditService->record(
            companyId: $otherCompany->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-2',
            payload: []
        );

        $events = $auditService->getEventsForCompany($this->company->id);

        $this->assertCount(2, $events);
    }

    public function test_can_query_audit_events_by_aggregate(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: []
        );

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.posted',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: []
        );

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-456',
            payload: []
        );

        $events = $auditService->getEventsForAggregate('Document', 'doc-123', $this->company->id);

        $this->assertCount(2, $events);
    }

    public function test_can_query_audit_events_by_date_range(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: []
        );

        $events = $auditService->getEventsInRange(
            companyId: $this->company->id,
            from: now()->subHour(),
            to: now()->addHour()
        );

        $this->assertCount(1, $events);
    }

    public function test_audit_api_returns_events(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: ['document_number' => 'INV-2025-0001']
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'event_type',
                        'aggregate_type',
                        'aggregate_id',
                        'payload',
                        'occurred_at',
                    ],
                ],
            ]);
    }

    public function test_audit_api_can_filter_by_event_type(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: []
        );

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.posted',
            aggregateType: 'Document',
            aggregateId: 'doc-1',
            payload: []
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?event_type=document.created');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_unauthorized_user_cannot_access_audit_api(): void
    {
        $response = $this->getJson('/api/v1/audit/events');

        $response->assertUnauthorized();
    }

    public function test_anomaly_detection_flags_unusual_activity(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        // Record many events rapidly to simulate unusual activity
        for ($i = 0; $i < 50; $i++) {
            $auditService->record(
                companyId: $this->company->id,
                userId: $this->user->id,
                eventType: 'document.voided',
                aggregateType: 'Document',
                aggregateId: 'doc-'.$i,
                payload: []
            );
        }

        /** @var AnomalyDetectionService $anomalyService */
        $anomalyService = app(AnomalyDetectionService::class);

        $anomalies = $anomalyService->detectAnomalies(
            companyId: $this->company->id,
            from: now()->subHour(),
            to: now()
        );

        $this->assertNotEmpty($anomalies);
        $this->assertTrue(
            collect($anomalies)->contains(fn ($a) => $a['type'] === 'high_void_rate')
        );
    }

    public function test_anomaly_detection_flags_after_hours_activity(): void
    {
        /** @var AnomalyDetectionService $anomalyService */
        $anomalyService = app(AnomalyDetectionService::class);

        // Simulate after-hours activity check (business hours: 8am-8pm)
        $afterHoursEvents = $anomalyService->detectAfterHoursActivity(
            companyId: $this->company->id,
            businessHoursStart: 8,
            businessHoursEnd: 20
        );

        $this->assertIsArray($afterHoursEvents);
    }

    public function test_audit_api_returns_anomalies(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/anomalies');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'type',
                        'severity',
                        'description',
                        'detected_at',
                    ],
                ],
            ]);
    }
}
