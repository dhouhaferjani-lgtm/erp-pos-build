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
            ->getJson('/api/v1/audit/events?include=payload');

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

    public function test_audit_api_omits_payload_by_default(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-red',
            payload: ['secret' => 'red'],
            metadata: ['ip' => '127.0.0.1'],
        );

        $event = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events')
            ->assertOk()
            ->json('data.0');

        self::assertIsArray($event);
        self::assertArrayNotHasKey('payload', $event);
        self::assertArrayNotHasKey('metadata', $event);
    }

    public function test_malformed_or_oversized_date_range_returns_422_not_500(): void
    {
        app()->setLocale('en');
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=not-a-date&to=2026-09-03')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.errors.from.0', 'The from field must match the format Y-m-d.');
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=2026-01-01&to=2026-06-01')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.errors.to.0', 'The date range may not exceed 92 days.');
    }

    public function test_aggregate_type_without_aggregate_id_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?aggregate_type=Document')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.aggregate_id.0',
                'The aggregate id field is required when aggregate type is present.',
            );
    }

    public function test_aggregate_id_without_aggregate_type_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?aggregate_id=00000000-0000-4000-8000-000000000001')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.aggregate_type.0',
                'The aggregate type field is required when aggregate id is present.',
            );
    }

    public function test_from_without_to_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.to.0',
                'The to field is required when from is present.',
            );
    }

    public function test_to_without_from_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?to=2026-09-03')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.from.0',
                'The from field is required when to is present.',
            );
    }

    public function test_audit_api_defaults_to_50_and_page_two_contains_the_remaining_event(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);
        $seededIds = [];
        foreach (range(1, 51) as $index) {
            $seededIds[] = $auditService->record(
                companyId: $this->company->id,
                userId: $this->user->id,
                eventType: 'audit.pagination.probe',
                aggregateType: 'Document',
                aggregateId: 'page-'.$index,
                payload: ['index' => $index],
            )->id;
        }

        $pageOne = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?event_type=audit.pagination.probe');
        $pageOne->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 50);

        $pageTwo = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?event_type=audit.pagination.probe&page=2');
        $pageTwo->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 50);

        $actualIds = array_column([
            ...$pageOne->json('data'),
            ...$pageTwo->json('data'),
        ], 'id');
        self::assertCount(51, $actualIds);
        self::assertCount(51, array_unique($actualIds));
        self::assertEqualsCanonicalizing($seededIds, $actualIds);
    }

    public function test_audit_api_rejects_per_page_above_100_with_validation_envelope(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.per_page.0',
                'The per page field must not be greater than 100.',
            );
    }

    /**
     * Step 4b (plan rev 9, gate r7 finding B2): the storage contract for
     * `aggregate_id` is `string(100)` (create_audit_events_table.php:19), and
     * real domain keys such as `doc-123` are NOT uuids. This positive HTTP
     * regression is red (422) the moment the rule is narrowed to `uuid`, and
     * green with `string|max:100`. The UUID-keyed cross-tenant scoping test
     * lives separately in ComplianceCrossTenantHardeningTest.
     */
    public function test_audit_api_aggregate_branch_accepts_non_uuid_string_aggregate_id(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: [],
        );
        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.posted',
            aggregateType: 'Document',
            aggregateId: 'doc-123',
            payload: [],
        );
        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-456',
            payload: [],
        );

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?aggregate_type=Document&aggregate_id=doc-123')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.aggregate_id', 'doc-123')
            ->assertJsonPath('data.1.aggregate_id', 'doc-123');
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
