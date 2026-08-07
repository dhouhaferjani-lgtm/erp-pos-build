<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\DTOs\GrantRequestData;
use App\Modules\SupportAccess\Application\Services\GrantLifecycleService;
use App\Modules\SupportAccess\Application\Services\RequestImpersonationContext;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrantEvent;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Domain\Services\GrantChainVerifier;
use App\Modules\SupportAccess\Infrastructure\Notifications\SupportAccessGrantNotification;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GrantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private GrantLifecycleService $service;

    private Tenant $tenant;

    private User $subject;

    private User $tenantAdmin;

    private SuperAdmin $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(GrantLifecycleService::class);
        $this->tenant = Tenant::factory()->create();
        $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator = $this->superAdmin('operator@example.test');

        Permission::findOrCreate('support-access.manage', 'sanctum');
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->tenantAdmin->givePermissionTo('support-access.manage');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_incident_request_requires_complete_purpose_and_waits_for_tenant_consent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->requestIncident($this->operator, $this->incidentData(reason: ''));
    }

    public function test_non_sensitive_tenant_approval_activates_incident_and_enforces_tenant_isolation(): void
    {
        $grant = $this->service->requestIncident($this->operator, $this->incidentData());

        self::assertSame(GrantStatus::PendingTenantApproval, $grant->status);
        self::assertSame($this->tenant->id, $grant->tenant_id);
        self::assertSame($this->subject->id, $grant->subject_user_id);

        $otherTenant = Tenant::factory()->create();
        $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);

        try {
            $this->service->approveByTenant($otherAdmin, $grant->id);
            self::fail('A tenant administrator must not approve another tenant grant.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $active = $this->service->approveByTenant($this->tenantAdmin, $grant->id);
        self::assertSame(GrantStatus::Active, $active->status);
        self::assertSame($this->tenantAdmin->id, $active->tenant_approved_by);
        self::assertNotNull($active->tenant_approved_at);
    }

    public function test_incident_request_notifies_tenant_managers_and_can_be_rejected_with_reason(): void
    {
        Notification::fake();

        $grant = $this->service->requestIncident($this->operator, $this->incidentData());

        Notification::assertSentTo(
            $this->tenantAdmin,
            SupportAccessGrantNotification::class,
            static fn (SupportAccessGrantNotification $notification): bool => $notification->data->grant_id === $grant->id
                && $notification->data->event === 'grant_requested',
        );

        $rejected = $this->service->rejectByTenant($this->tenantAdmin, $grant->id, 'Not an approved support period');
        self::assertSame(GrantStatus::Rejected, $rejected->status);
        self::assertSame(
            'Not an approved support period',
            ImpersonationGrant::query()->findOrFail($grant->id)->rejection_reason,
        );

        $events = ImpersonationGrantEvent::query()
            ->where('grant_id', $grant->id)
            ->orderBy('sequence')
            ->get();
        self::assertSame(
            [SessionEventType::GrantRequested, SessionEventType::GrantRejected],
            $events->pluck('event_type')->all(),
        );
        self::assertSame([1, 2], $events->pluck('sequence')->all());
        self::assertSame($events[0]->hash, $events[1]->previous_hash);
        self::assertDatabaseHas('admin_audit_logs', [
            'impersonation_event_id' => $events[1]->id,
            'impersonation_session_id' => null,
        ]);
        self::assertTrue(DB::table('audit_events')
            ->where('impersonation_event_id', $events[1]->id)
            ->whereNull('impersonation_session_id')
            ->exists());
    }

    public function test_grant_chain_verifier_rejects_semantic_tampering(): void
    {
        $grant = $this->service->requestIncident($this->operator, $this->incidentData());
        $this->service->approveByTenant($this->tenantAdmin, $grant->id);

        $fresh = ImpersonationGrant::query()->findOrFail($grant->id);
        $events = ImpersonationGrantEvent::query()
            ->where('grant_id', $grant->id)
            ->orderBy('sequence')
            ->get();
        $verifier = $this->app->make(GrantChainVerifier::class);
        self::assertTrue($verifier->verify($events, $fresh->chain_head_hash)->valid);

        $events[0]->event_type = SessionEventType::GrantRejected;

        self::assertFalse($verifier->verify($events, $fresh->chain_head_hash)->valid);

        $this->artisan('support-access:audit-verify', ['--grant' => $grant->id])
            ->assertExitCode(0);

        DB::table('admin_audit_logs')->where('impersonation_event_id', $events[1]->id)
            ->update(['action' => 'impersonation_tampered']);
        $this->artisan('support-access:audit-verify', ['--grant' => $grant->id])
            ->assertExitCode(1);
    }

    public function test_incident_request_rejects_a_subject_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSubject = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->expectException(AuthorizationException::class);
        $this->service->requestIncident(
            $this->operator,
            new GrantRequestData(
                tenant_id: $this->tenant->id,
                subject_user_id: $otherSubject->id,
                type: GrantType::PerIncident,
                reason: 'Invalid cross-tenant subject',
                ticket_ref: 'SUP-ISOLATION',
                starts_at: CarbonImmutable::now(),
                expires_at: CarbonImmutable::now()->addHour(),
            ),
        );
    }

    public function test_sensitive_tenant_requires_configured_partner_and_operator_cannot_self_approve(): void
    {
        DB::table('tenants')->where('id', $this->tenant->id)->update(['is_sensitive' => true]);
        $partner = $this->superAdmin('partner@example.test', 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [
            $this->operator->email,
            $partner->email,
        ]);

        $grant = $this->service->requestIncident($this->operator, $this->incidentData());
        $pending = $this->service->approveByTenant($this->tenantAdmin, $grant->id);
        self::assertSame(GrantStatus::PendingInternalApproval, $pending->status);

        try {
            $this->service->approveSecond($this->operator, $grant->id);
            self::fail('The requesting operator must not be their own second approver.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $active = $this->service->approveSecond($partner, $grant->id);
        self::assertSame(GrantStatus::Active, $active->status);
        self::assertSame($partner->id, $active->second_approved_by);
    }

    public function test_pre_granted_window_is_tenant_created_scope_and_permission_gated(): void
    {
        $window = $this->service->createPreGrantedWindow(
            $this->tenantAdmin,
            new GrantRequestData(
                tenant_id: $this->tenant->id,
                subject_user_id: null,
                type: GrantType::PreGrantedWindow,
                reason: 'Quarter-close support window',
                ticket_ref: 'SUP-2050',
                starts_at: CarbonImmutable::now(),
                expires_at: CarbonImmutable::now()->addHours(4),
            ),
        );

        self::assertSame(GrantStatus::Active, $window->status);
        self::assertNull($window->subject_user_id);
        self::assertSame($this->tenantAdmin->id, $window->tenant_approved_by);

        $ordinaryUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->expectException(AuthorizationException::class);
        $this->service->createPreGrantedWindow($ordinaryUser, $this->windowData());
    }

    public function test_grant_window_cannot_exceed_the_configured_seven_day_cap(): void
    {
        config()->set('support_access.max_grant_window_hours', 168);

        $this->expectException(InvalidArgumentException::class);
        $this->service->createPreGrantedWindow(
            $this->tenantAdmin,
            new GrantRequestData(
                tenant_id: $this->tenant->id,
                subject_user_id: null,
                type: GrantType::PreGrantedWindow,
                reason: 'Overlong support window',
                ticket_ref: 'SUP-OVERLONG',
                starts_at: CarbonImmutable::now(),
                expires_at: CarbonImmutable::now()->addHours(169),
            ),
        );
    }

    public function test_live_impersonation_context_cannot_mutate_tenant_grants(): void
    {
        $pending = $this->service->requestIncident($this->operator, $this->incidentData());
        $this->app->make(RequestImpersonationContext::class)->set(new ImpersonationContextData(
            operator_id: $this->operator->id,
            session_id: Str::uuid()->toString(),
            subject_user_id: $this->tenantAdmin->id,
            subject_name: $this->tenantAdmin->name,
            tenant_id: $this->tenant->id,
            access_level: SessionAccessLevel::WriteElevated,
            reason: 'Attempted consent mutation',
            ticket_ref: 'SUP-DENY-CONSENT',
            expires_at: CarbonImmutable::now()->addHour(),
        ));

        try {
            $this->service->createPreGrantedWindow($this->tenantAdmin, $this->windowData());
            self::fail('An impersonated subject must not create a pre-granted window.');
        } catch (AuthorizationException) {
            self::assertDatabaseCount('impersonation_grants', 1);
        }

        $this->expectException(AuthorizationException::class);
        $this->service->approveByTenant($this->tenantAdmin, $pending->id);
    }

    public function test_expired_approval_fails_closed_and_revocation_is_idempotent(): void
    {
        $now = CarbonImmutable::parse('2026-08-06 10:00:00');
        CarbonImmutable::setTestNow($now);
        $expiring = $this->service->requestIncident(
            $this->operator,
            $this->incidentData(expiresAt: $now->addMinute()),
        );

        CarbonImmutable::setTestNow($now->addMinutes(2));
        try {
            $this->service->approveByTenant($this->tenantAdmin, $expiring->id);
            self::fail('Expired consent requests must fail closed.');
        } catch (DomainException) {
            $fresh = ImpersonationGrant::query()->find($expiring->id);
            self::assertInstanceOf(ImpersonationGrant::class, $fresh);
            self::assertSame(GrantStatus::Expired, $fresh->status);
        }

        CarbonImmutable::setTestNow($now);
        $grant = $this->service->requestIncident($this->operator, $this->incidentData());
        $active = $this->service->approveByTenant($this->tenantAdmin, $grant->id);
        $revoked = $this->service->revoke($this->tenantAdmin, $active->id, 'Issue resolved');
        $again = $this->service->revoke($this->tenantAdmin, $active->id, 'Ignored duplicate');

        self::assertSame(GrantStatus::Revoked, $revoked->status);
        self::assertSame($revoked->revoked_at?->toISOString(), $again->revoked_at?->toISOString());
        self::assertSame('Issue resolved', $again->revocation_reason);
    }

    public function test_routes_have_the_locked_auth_tenancy_permission_stacks_and_admin_annotations(): void
    {
        $admin = $this->route('admin.impersonation.requests.store');
        self::assertContains('api', $admin->gatherMiddleware());
        self::assertContains('auth:sanctum-admin', $admin->gatherMiddleware());
        self::assertContains('central_admin_role:super_admin', $admin->gatherMiddleware());
        self::assertContains(SetPermissionsTeam::class, $admin->gatherMiddleware());

        $tenant = $this->route('support-access.grants.store');
        self::assertContains('api', $tenant->gatherMiddleware());
        self::assertContains('auth:sanctum', $tenant->gatherMiddleware());
        self::assertContains(SetPermissionsTeam::class, $tenant->gatherMiddleware());
        self::assertContains(EnforceTokenTenantClaim::class, $tenant->gatherMiddleware());
        self::assertContains('can:support-access.manage', $tenant->gatherMiddleware());

        foreach (['store', 'approveSecond', 'revoke'] as $method) {
            $reflection = new ReflectionMethod($admin->getControllerClass(), $method);
            self::assertCount(1, $reflection->getAttributes(CrossTenantRoute::class));
        }
    }

    private function incidentData(string $reason = 'Investigate invoice rendering', ?CarbonImmutable $expiresAt = null): GrantRequestData
    {
        return new GrantRequestData(
            tenant_id: $this->tenant->id,
            subject_user_id: $this->subject->id,
            type: GrantType::PerIncident,
            reason: $reason,
            ticket_ref: 'SUP-2049',
            starts_at: CarbonImmutable::now(),
            expires_at: $expiresAt ?? CarbonImmutable::now()->addHours(2),
        );
    }

    private function windowData(): GrantRequestData
    {
        return new GrantRequestData(
            tenant_id: $this->tenant->id,
            subject_user_id: null,
            type: GrantType::PreGrantedWindow,
            reason: 'Scheduled support window',
            ticket_ref: 'SUP-2051',
            starts_at: CarbonImmutable::now(),
            expires_at: CarbonImmutable::now()->addHour(),
        );
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

    private function route(string $name): IlluminateRoute
    {
        $route = Route::getRoutes()->getByName($name);
        self::assertInstanceOf(IlluminateRoute::class, $route);

        return $route;
    }
}
