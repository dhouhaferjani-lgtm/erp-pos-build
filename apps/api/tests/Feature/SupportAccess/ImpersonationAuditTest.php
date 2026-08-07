<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\Services\ElevationService;
use App\Modules\SupportAccess\Application\Services\RequestImpersonationContext;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationAuditDelivery;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Infrastructure\Audit\TenantImpersonationAuditStore;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\AdminAuditService;
use App\Shared\Contracts\SupportAccess\AdminImpersonationAuditWriter;
use App\Shared\Contracts\SupportAccess\TenantImpersonationAuditWriter;
use App\Shared\DTOs\SupportAccess\GrantAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationAuditMirrorData;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImpersonationAuditTest extends TestCase
{
    use RefreshDatabase;

    private static int $probeHits = 0;

    private Tenant $tenant;

    private User $subject;

    private SuperAdmin $operator;

    private SessionLifecycleService $sessions;

    private ElevationService $elevations;

    private TenantImpersonationAuditStore $tenantAuditStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ResolveTenancy::class, CompanyContextMiddleware::class]);
        self::$probeHits = 0;

        $this->tenant = Tenant::factory()->create();
        $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator = $this->superAdmin('operator@example.test');

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        foreach (['products.view', 'products.update'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        $this->subject->givePermissionTo(['products.view', 'products.update']);
        config()->set('support_access.permissions.read_only', ['products.view']);
        config()->set('support_access.permissions.write', ['products.update']);

        $this->sessions = $this->app->make(SessionLifecycleService::class);
        $this->elevations = $this->app->make(ElevationService::class);
        $this->tenantAuditStore = $this->app->make(TenantImpersonationAuditStore::class);

        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-audit/read', static function () {
            self::$probeHits++;

            return response()->json(['reached' => true]);
        })->name('test.impersonation-audit.read');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->post('/_test/impersonation-audit/write', static function () {
            self::$probeHits++;

            return response()->json(['reached' => true]);
        })->name('test.impersonation-audit.write');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-audit/denied-response', static fn () => response()->json([
            'error' => ['code' => 'PROBE_DENIED', 'message' => 'Probe denied.'],
        ], 422))->name('test.impersonation-audit.denied-response');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-audit/failure-response', static fn () => response()->json([
            'error' => ['code' => 'PROBE_FAILED', 'message' => 'Probe failed.'],
        ], 500))->name('test.impersonation-audit.failure-response');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-audit/throws', static function (): never {
            throw new RuntimeException('downstream exploded');
        })->name('test.impersonation-audit.throws');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-audit/throws-denied', static function (): never {
            throw new AuthorizationException('downstream denied');
        })->name('test.impersonation-audit.throws-denied');
    }

    public function test_thrown_authorization_exception_appends_a_denied_terminal_event(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $this->withoutExceptionHandling();

        try {
            $this->bearer($plainToken)->getJson('/_test/impersonation-audit/throws-denied');
            self::fail('The authorization exception must be rethrown after auditing.');
        } catch (AuthorizationException $exception) {
            self::assertSame('downstream denied', $exception->getMessage());
        }

        $denied = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', SessionEventType::RequestDenied)
            ->latest('sequence')
            ->firstOrFail();
        self::assertSame(AuditOutcome::Denied, $denied->outcome);
        self::assertSame(403, $denied->details->response_status);
    }

    public function test_returned_500_and_thrown_exception_append_failed_terminal_events(): void
    {
        [, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)
            ->getJson('/_test/impersonation-audit/failure-response')
            ->assertStatus(500);

        Auth::forgetGuards();
        $this->withoutExceptionHandling();
        try {
            $this->bearer($plainToken)->getJson('/_test/impersonation-audit/throws');
            self::fail('The downstream exception must be rethrown after auditing.');
        } catch (RuntimeException $exception) {
            self::assertSame('downstream exploded', $exception->getMessage());
        }

        $failed = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', SessionEventType::RequestFailed)
            ->orderBy('sequence')
            ->get();
        self::assertCount(2, $failed);
        self::assertSame([500, 500], $failed->pluck('details.response_status')->all());
        self::assertSame([AuditOutcome::Failed, AuditOutcome::Failed], $failed->pluck('outcome')->all());
    }

    public function test_request_received_and_terminating_outcome_use_a_validated_uuid_and_real_status(): void
    {
        [, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)
            ->withHeader('X-Request-ID', 'not-a-uuid')
            ->getJson('/_test/impersonation-audit/read')
            ->assertOk();

        Auth::forgetGuards();
        $this->bearer($plainToken)
            ->getJson('/_test/impersonation-audit/denied-response')
            ->assertUnprocessable();

        $requests = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->whereIn('event_type', [
                SessionEventType::RequestReceived,
                SessionEventType::RequestAuthorized,
                SessionEventType::RequestDenied,
            ])
            ->orderBy('sequence')
            ->get();

        self::assertCount(4, $requests);
        self::assertSame(SessionEventType::RequestReceived, $requests[0]->event_type);
        self::assertSame(AuditOutcome::Observed, $requests[0]->outcome);
        self::assertTrue(Str::isUuid($requests[0]->request_id));
        self::assertSame($requests[0]->request_id, $requests[1]->request_id);
        self::assertSame(SessionEventType::RequestAuthorized, $requests[1]->event_type);
        self::assertSame(200, $requests[1]->details->response_status);
        self::assertSame(SessionEventType::RequestReceived, $requests[2]->event_type);
        self::assertSame($requests[2]->request_id, $requests[3]->request_id);
        self::assertSame(SessionEventType::RequestDenied, $requests[3]->event_type);
        self::assertSame(AuditOutcome::Denied, $requests[3]->outcome);
        self::assertSame(422, $requests[3]->details->response_status);
        self::assertSame('PROBE_DENIED', $requests[3]->details->error_code);
    }

    public function test_read_and_elevated_write_append_identical_dual_log_chain_mirrors(): void
    {
        [$grant, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')
            ->assertOk()
            ->assertJsonPath('reached', true);

        $partner = $this->superAdmin('partner@example.test', 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [$partner->email]);
        $elevation = $this->elevations->request($this->operator, $session->id, 'Update non-fiscal product description');
        $this->elevations->approve($partner, $elevation->id);

        Auth::forgetGuards();
        $this->bearer($plainToken)->postJson('/_test/impersonation-audit/write')
            ->assertOk()
            ->assertJsonPath('reached', true);

        $events = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->orderBy('sequence')
            ->get();
        self::assertCount(7, $events);
        self::assertSame(range(1, 7), $events->pluck('sequence')->all());
        self::assertSame(str_repeat('0', 64), $events[0]->previous_hash);
        foreach ($events->slice(1)->values() as $index => $event) {
            self::assertSame($events[$index]->hash, $event->previous_hash);
        }
        self::assertSame(7, $session->fresh()?->chain_sequence);
        self::assertSame($events[6]->hash, $session->fresh()?->chain_head_hash);

        $tenantMirrors = $this->tenantAuditStore->forEvents(
            $this->tenant->id,
            $events->pluck('id')->all(),
        )->keyBy('impersonation_event_id');
        foreach ($events as $event) {
            $admin = AdminAuditLog::query()->where('impersonation_event_id', $event->id)->first();
            $tenant = $tenantMirrors->get($event->id);
            self::assertNotNull($admin);
            self::assertNotNull(
                $tenant,
                "Missing tenant mirror for sequence {$event->sequence} ({$event->event_type->value}); found: ".$tenantMirrors->keys()->implode(', '),
            );

            foreach (['impersonator_id', 'impersonation_session_id', 'impersonation_event_id',
                'impersonation_sequence', 'impersonation_previous_hash', 'impersonation_hash'] as $column) {
                self::assertSame($admin->getAttribute($column), $tenant->getAttribute($column));
            }
            self::assertSame($this->operator->id, $admin->impersonator_id);
            self::assertSame($session->id, $admin->impersonation_session_id);
            self::assertSame($grant->tenant_id, $tenant->tenant_id);
            self::assertSame($this->subject->id, $tenant->user_id);
            self::assertSame($event->hash, $tenant->impersonation_hash);
            self::assertNotSame(
                $event->hash,
                $tenant->event_hash,
                'NF525 audit event_hash must remain independent from the support-session chain hash.',
            );
        }

        self::assertSame(2, self::$probeHits);
    }

    public function test_tenant_mirror_failure_returns_503_before_probe_executes(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $beforeEvents = ImpersonationSessionEvent::query()->where('session_id', $session->id)->count();
        $beforeAdmin = AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count();
        $beforeTenant = $this->tenantMirrorCount($session->id);
        $this->app->instance(TenantImpersonationAuditWriter::class, new class implements TenantImpersonationAuditWriter
        {
            public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void
            {
                throw new RuntimeException('tenant audit unavailable');
            }

            public function writeGrantMirror(GrantAuditMirrorData $event): void {}
        });

        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IMPERSONATION_AUDIT_UNAVAILABLE')
            ->assertJsonMissing(['reached' => true]);

        self::assertSame(0, self::$probeHits);
        self::assertSame($beforeEvents + 1, ImpersonationSessionEvent::query()->where('session_id', $session->id)->count());
        self::assertSame($beforeAdmin + 1, AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count());
        self::assertSame($beforeTenant, $this->tenantMirrorCount($session->id));
    }

    public function test_admin_mirror_failure_returns_503_before_probe_executes(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $beforeEvents = ImpersonationSessionEvent::query()->where('session_id', $session->id)->count();
        $beforeAdmin = AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count();
        $beforeTenant = $this->tenantMirrorCount($session->id);
        $this->app->instance(AdminImpersonationAuditWriter::class, new class implements AdminImpersonationAuditWriter
        {
            public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void
            {
                throw new RuntimeException('admin audit unavailable');
            }

            public function writeGrantMirror(GrantAuditMirrorData $event): void {}
        });

        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IMPERSONATION_AUDIT_UNAVAILABLE');

        self::assertSame(0, self::$probeHits);
        self::assertSame($beforeEvents + 1, ImpersonationSessionEvent::query()->where('session_id', $session->id)->count());
        self::assertSame($beforeAdmin, AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count());
        self::assertSame($beforeTenant, $this->tenantMirrorCount($session->id));
    }

    public function test_terminal_mirror_failure_leaves_a_durable_reconcilable_delivery(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $realWriter = $this->app->make(TenantImpersonationAuditWriter::class);
        $this->app->instance(TenantImpersonationAuditWriter::class, new class($realWriter) implements TenantImpersonationAuditWriter
        {
            private int $attempts = 0;

            public function __construct(private readonly TenantImpersonationAuditWriter $delegate) {}

            public function writeImpersonationMirror(ImpersonationAuditMirrorData $event): void
            {
                $this->attempts++;
                if ($this->attempts === 2) {
                    throw new RuntimeException('terminal tenant mirror unavailable');
                }
                $this->delegate->writeImpersonationMirror($event);
            }

            public function writeGrantMirror(GrantAuditMirrorData $event): void
            {
                $this->delegate->writeGrantMirror($event);
            }
        });

        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IMPERSONATION_AUDIT_UNAVAILABLE');
        self::assertSame(1, self::$probeHits, 'The test must fail only after downstream dispatch.');

        $delivery = ImpersonationAuditDelivery::query()
            ->where('aggregate_type', 'session')
            ->whereNull('tenant_delivered_at')
            ->latest('created_at')
            ->firstOrFail();
        self::assertNotNull($delivery->admin_delivered_at);
        self::assertSame(1, $delivery->attempt_count);

        $this->app->instance(TenantImpersonationAuditWriter::class, $realWriter);
        $this->artisan('support-access:audit-reconcile')->assertExitCode(0);
        self::assertNotNull($delivery->fresh()?->tenant_delivered_at);
        self::assertSame(
            ImpersonationSessionEvent::query()->where('session_id', $session->id)->count(),
            $this->tenantMirrorCount($session->id),
        );
    }

    public function test_verify_command_detects_a_missing_mirror(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')->assertOk();

        $this->artisan('support-access:audit-verify', ['--session' => $session->id])
            ->assertExitCode(0);

        AdminAuditLog::query()->where('impersonation_session_id', $session->id)->update([
            'impersonation_hash' => str_repeat('f', 64),
        ]);

        $this->artisan('support-access:audit-verify', ['--session' => $session->id])
            ->assertExitCode(1);
    }

    public function test_verify_command_detects_semantic_mirror_tampering(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $this->bearer($plainToken)->getJson('/_test/impersonation-audit/read')->assertOk();

        $event = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', SessionEventType::RequestAuthorized)
            ->firstOrFail();
        AdminAuditLog::query()->where('impersonation_event_id', $event->id)->update([
            'new_values' => ['outcome' => 'allowed', 'method' => 'DELETE', 'path' => '/tampered', 'details' => []],
        ]);

        $this->artisan('support-access:audit-verify', ['--session' => $session->id])
            ->assertExitCode(1);
    }

    public function test_denied_write_appends_a_dual_log_chain_event_without_executing_the_action(): void
    {
        [, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->postJson('/_test/impersonation-audit/write')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');

        $denial = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', SessionEventType::RequestDenied)
            ->firstOrFail();
        self::assertSame(AuditOutcome::Denied, $denial->outcome);
        self::assertSame('IMPERSONATION_READ_ONLY', $denial->details->error_code);
        self::assertDatabaseHas('admin_audit_logs', ['impersonation_event_id' => $denial->id]);
        self::assertTrue($this->tenantAuditStore->forEvents($this->tenant->id, [$denial->id])->isNotEmpty());
        self::assertSame(0, self::$probeHits);
    }

    public function test_all_audit_event_creation_paths_and_admin_logs_inherit_impersonation_attribution(): void
    {
        [, $session] = $this->startedSession();
        $anchor = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->latest('sequence')
            ->firstOrFail();
        $this->app->make(RequestImpersonationContext::class)->set(new ImpersonationContextData(
            operator_id: $this->operator->id,
            session_id: $session->id,
            subject_user_id: $this->subject->id,
            subject_name: $this->subject->name,
            tenant_id: $this->tenant->id,
            access_level: $session->access_level,
            reason: 'Attribution coverage',
            ticket_ref: 'SUP-ATTRIBUTION',
            expires_at: CarbonImmutable::instance($session->expires_at),
            audit_event_id: $anchor->id,
            audit_sequence: $anchor->sequence,
            audit_previous_hash: $anchor->previous_hash,
            audit_hash: $anchor->hash,
        ));

        $direct = AuditEvent::fromClientEnvelope([
            'event_id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'operator_id' => $this->subject->id,
            'event_type' => 'pos.client_sync',
            'aggregate_type' => 'Receipt',
            'aggregate_id' => Str::uuid()->toString(),
            'payload' => [],
            'metadata' => [],
            'occurred_at' => now()->toIso8601String(),
        ]);
        $direct->saveOrFail();
        $admin = $this->app->make(AdminAuditService::class)->log(
            admin: $this->operator,
            action: 'attribution_probe',
            tenant: $this->tenant,
        );

        foreach ([$direct, $admin] as $audit) {
            self::assertSame($this->operator->id, $audit->impersonator_id);
            self::assertSame($session->id, $audit->impersonation_session_id);
            self::assertSame($anchor->id, $audit->impersonation_event_id);
            self::assertSame($anchor->hash, $audit->impersonation_hash);
        }
    }

    public function test_session_and_elevation_lifecycle_transitions_are_chained_and_mirrored(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $partner = $this->superAdmin('partner-lifecycle@example.test', 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [$partner->email]);

        $rejectedElevation = $this->elevations->request($this->operator, $session->id, 'Rejected lifecycle audit proof');
        $this->elevations->reject($partner, $rejectedElevation->id, 'Insufficiently scoped reason');
        $elevation = $this->elevations->request($this->operator, $session->id, 'Lifecycle audit proof');
        $this->elevations->approve($partner, $elevation->id);
        Auth::forgetGuards();
        $this->bearer($plainToken)
            ->postJson("/api/v1/support-access/sessions/{$session->id}/exit")
            ->assertOk();

        $events = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->orderBy('sequence')
            ->get();
        self::assertEqualsCanonicalizing([
            SessionEventType::SessionStarted,
            SessionEventType::WriteElevationRequested,
            SessionEventType::WriteElevationRejected,
            SessionEventType::WriteElevationRequested,
            SessionEventType::WriteElevationApproved,
            SessionEventType::RequestReceived,
            SessionEventType::RequestAuthorized,
            SessionEventType::SessionEnded,
            SessionEventType::GrantRevoked,
        ], $events->pluck('event_type')->all());
        self::assertSame(range(1, $events->count()), $events->pluck('sequence')->all());
        self::assertSame($events->count(), AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count());
        self::assertSame($events->count(), $this->tenantMirrorCount($session->id));
    }

    private function tenantMirrorCount(string $sessionId): int
    {
        $eventIds = ImpersonationSessionEvent::query()
            ->where('session_id', $sessionId)
            ->pluck('id')
            ->all();

        return $this->tenantAuditStore->forEvents($this->tenant->id, $eventIds)->count();
    }

    /** @return array{ImpersonationGrant, ImpersonationSession, string} */
    private function startedSession(): array
    {
        $grant = ImpersonationGrant::query()->create([
            'tenant_id' => $this->tenant->id,
            'subject_user_id' => $this->subject->id,
            'operator_id' => $this->operator->id,
            'type' => GrantType::PerIncident,
            'status' => GrantStatus::Active,
            'reason' => 'Investigate product rendering',
            'ticket_ref' => 'SUP-7001',
            'requested_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(2),
            'tenant_approved_by' => Str::uuid()->toString(),
            'tenant_approved_at' => now()->subMinute(),
        ]);
        $started = $this->sessions->start($this->operator, $grant->id, $this->subject->id);

        return [
            $grant,
            ImpersonationSession::query()->findOrFail($started->session_id),
            $started->plain_text_token,
        ];
    }

    private function superAdmin(string $email, string $role = 'super_admin'): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function bearer(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
