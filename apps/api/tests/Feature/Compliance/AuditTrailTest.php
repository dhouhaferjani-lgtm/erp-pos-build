<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Compliance\Presentation\Requests\ListAuditEventsRequest;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Redirector;
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

    /**
     * Gate r1 finding B1: a present-but-empty query parameter must be treated
     * as ABSENT, never as a filter value of `''`.
     *
     * Laravel skips every non-implicit rule for a present-but-empty string
     * (Validator::presentOrRuleIsImplicit), so before the fix each empty
     * parameter reached the controller as a real `''` value:
     *  - `event_type=`                 -> where('event_type','')      -> 0 rows
     *  - `aggregate_type=&aggregate_id=` -> aggregate branch with two empty
     *                                     predicates                  -> 0 rows
     *  - `from=&to=`                   -> CarbonImmutable::parse('') is *now*,
     *                                     so the read was silently scoped to
     *                                     today (the 10-day-old event below is
     *                                     what makes this leg falsifying)
     *  - `per_page=`                   -> (int) '' === 0 -> Eloquent's own
     *                                     default of 15, not the documented 50
     *  - `include=`                    -> not 'payload', so payload stays out
     */
    public function test_empty_query_parameters_are_treated_as_absent(): void
    {
        /** @var AuditService $auditService */
        $auditService = app(AuditService::class);

        $this->travelTo(now()->subDays(10));
        $auditService->record(
            companyId: $this->company->id,
            userId: $this->user->id,
            eventType: 'document.created',
            aggregateType: 'Document',
            aggregateId: 'doc-old',
            payload: ['secret' => 'old'],
        );
        $this->travelBack();

        foreach (['doc-1', 'doc-2', 'doc-3'] as $aggregateId) {
            $auditService->record(
                companyId: $this->company->id,
                userId: $this->user->id,
                eventType: 'document.created',
                aggregateType: 'Document',
                aggregateId: $aggregateId,
                payload: ['secret' => $aggregateId],
            );
        }

        $response = $this->actingAs($this->user, 'sanctum')->getJson(
            '/api/v1/audit/events?event_type=&aggregate_type=&aggregate_id=&from=&to=&per_page=&page=&include=',
        );

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);

        $event = $response->json('data.0');
        self::assertIsArray($event);
        self::assertArrayNotHasKey('payload', $event);
        self::assertArrayNotHasKey('metadata', $event);
    }

    /**
     * Gate r1 finding B1, middleware-independence leg.
     *
     * Over HTTP the global TrimStrings + ConvertEmptyStringsToNull middleware
     * already turns `?event_type=` into `null`, so the HTTP tests above cannot
     * distinguish "the request handles blanks" from "the middleware did it".
     * This exercises the FormRequest directly, with no global middleware in
     * play: without prepareForValidation() the blank values survive validation
     * as `''` (Laravel skips non-implicit rules for a present-but-empty
     * string), which is exactly the value that used to reach `where()` and
     * `CarbonImmutable::parse()`.
     */
    public function test_form_request_normalizes_blank_parameters_without_the_global_middleware(): void
    {
        $request = ListAuditEventsRequest::create(
            '/api/v1/audit/events?event_type=&aggregate_type=&aggregate_id=&from=&to=&per_page=&page=&include=%20',
            'GET',
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        $request->validateResolved();

        foreach (['event_type', 'aggregate_type', 'aggregate_id', 'from', 'to', 'per_page', 'page', 'include'] as $key) {
            self::assertNull($request->validated($key), "blank {$key} must normalize to null");
        }
    }

    /**
     * Gate r1 finding B1: stripping empty strings must NOT weaken the
     * all-or-nothing pair contract. An empty half is still a half-pair.
     */
    public function test_aggregate_type_with_empty_aggregate_id_still_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?aggregate_type=Document&aggregate_id=')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.aggregate_id.0',
                'The aggregate id field is required when aggregate type is present.',
            );
    }

    public function test_from_with_empty_to_still_returns_validation_error(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=2026-09-01&to=')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'error.errors.to.0',
                'The to field is required when from is present.',
            );
    }

    /**
     * Gate r1 finding N1: the span contract is "at most 92 days between `from`
     * and `to`" — i.e. diffInDays(from, to) <= 92. Only a 151-day case was
     * pinned before, so narrowing the rule to `>= 92` or widening it to `> 93`
     * would not have turned a single test red. These two cases pin both sides
     * of the exact boundary.
     */
    public function test_audit_date_range_accepts_the_92_day_span_boundary(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=2026-01-01&to=2026-04-03')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_audit_date_range_rejects_the_93_day_span_boundary(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/audit/events?from=2026-01-01&to=2026-04-04')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.errors.to.0', 'The date range may not exceed 92 days.');
    }
}
