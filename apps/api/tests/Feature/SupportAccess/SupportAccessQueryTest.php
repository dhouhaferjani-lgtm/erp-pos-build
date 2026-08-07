<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\DTOs\ImpersonationAuditDetailsData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationElevation;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SupportAccessQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $tenantAdmin;

    private User $subject;

    private SuperAdmin $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ResolveTenancy::class, CompanyContextMiddleware::class]);

        $this->tenant = Tenant::factory()->create();
        $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->subject = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant Subject',
        ]);
        $this->operator = $this->superAdmin('operator@example.test');

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('support-access.view', 'sanctum');
        Permission::findOrCreate('products.view', 'sanctum');
        $this->tenantAdmin->givePermissionTo('support-access.view');
        $this->subject->givePermissionTo('products.view');
        config()->set('support_access.permissions.read_only', ['products.view']);
    }

    public function test_tenant_overview_is_scoped_from_auth_and_sanitized(): void
    {
        config()->set('support_access.max_grant_window_hours', 72);
        $grant = $this->grant($this->tenant, $this->subject, 'SUP-8001');
        $session = $this->makeSession($grant, $this->subject);
        $this->event($session, '/api/v1/products?secret=hidden');

        $otherTenant = Tenant::factory()->create();
        $otherSubject = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherGrant = $this->grant($otherTenant, $otherSubject, 'SUP-PRIVATE');
        $this->makeSession($otherGrant, $otherSubject);

        $token = $this->tenantAdmin->createToken('tenant-query', [
            'tenant:'.$this->tenant->id,
        ])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/support-access');
        $response->assertOk()
            ->assertJsonPath('data.max_grant_window_hours', 72)
            ->assertJsonPath('data.grants.0.id', $grant->id)
            ->assertJsonPath('data.active_sessions.0.id', $session->id)
            ->assertJsonPath('data.log.0.session_id', $session->id)
            ->assertJsonPath('data.log.0.ticket_ref', 'SUP-8001')
            ->assertJsonPath('data.log.0.operator_name', 'Support Operator')
            ->assertJsonPath('data.log.0.reason', 'Investigate tenant issue')
            ->assertJsonPath('data.log.0.access_level', 'read_only')
            ->assertJsonPath('data.log_meta.total', 1)
            ->assertJsonMissing(['ticket_ref' => 'SUP-PRIVATE']);

        $encoded = $response->getContent();
        self::assertIsString($encoded);
        foreach (['operator@example.test', 'ip_address', 'user_agent', 'previous_hash',
            'impersonation_hash', 'internal_approval_notes', 'raw_details', 'hidden'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_admin_overview_is_central_cross_tenant_and_paginated(): void
    {
        $first = $this->grant($this->tenant, $this->subject, 'SUP-8101');
        $session = $this->makeSession($first, $this->subject);
        $elevation = ImpersonationElevation::query()->create([
            'session_id' => $session->id,
            'requested_by' => $this->operator->id,
            'status' => ElevationStatus::Pending,
            'reason' => 'Update a non-fiscal product description',
            'requested_at' => now(),
        ]);
        $otherTenant = Tenant::factory()->create();
        $otherSubject = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherOperator = $this->superAdmin('other-operator@example.test');
        $second = $this->grant($otherTenant, $otherSubject, 'SUP-8102');
        $second->update(['operator_id' => $otherOperator->id]);
        ImpersonationSession::query()->create([
            'grant_id' => $second->id,
            'operator_id' => $otherOperator->id,
            'subject_user_id' => $otherSubject->id,
            'tenant_id' => $otherTenant->id,
            'access_level' => 'read_only',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->operator, 'sanctum-admin')
            ->getJson('/api/v1/admin/impersonation?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.grants')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 1);

        $all = $this->actingAs($this->operator, 'sanctum-admin')
            ->getJson('/api/v1/admin/impersonation?per_page=20');
        $all->assertOk();
        self::assertEqualsCanonicalizing(
            [$first->id],
            collect($all->json('data.grants'))->pluck('id')->all(),
        );
        $all->assertJsonPath('data.pending_elevations.0.id', $elevation->id)
            ->assertJsonPath('data.pending_elevations.0.session_id', $session->id)
            ->assertJsonMissingPath('data.pending_elevations.0.requested_by')
            ->assertJsonCount(1, 'data.active_sessions')
            ->assertJsonPath('data.active_sessions.0.id', $session->id);
    }

    public function test_auth_me_exposes_only_banner_safe_context_while_impersonating(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 12:00:00');
        $grant = $this->grant($this->tenant, $this->subject, 'SUP-8201');
        $started = $this->app->make(SessionLifecycleService::class)
            ->start($this->operator, $grant->id, $this->subject->id);

        $response = $this->withToken($started->plain_text_token)->getJson('/api/v1/auth/me');
        $response->assertOk()
            ->assertJsonPath('data.impersonation.session_id', $started->session_id)
            ->assertJsonPath('data.impersonation.subject_user_id', $this->subject->id)
            ->assertJsonPath('data.impersonation.subject_name', 'Tenant Subject')
            ->assertJsonPath('data.impersonation.reason', 'Investigate tenant issue')
            ->assertJsonPath('data.impersonation.ticket_ref', 'SUP-8201')
            ->assertJsonPath('data.impersonation.access_level', 'read_only')
            ->assertJsonPath('data.impersonation.remaining_seconds', 3600);

        $context = $response->json('data.impersonation');
        self::assertIsArray($context);
        self::assertSame([
            'session_id', 'subject_user_id', 'subject_name', 'reason', 'ticket_ref',
            'access_level', 'expires_at', 'remaining_seconds',
        ], array_keys($context));
        self::assertArrayNotHasKey('operator_id', $context);

        CarbonImmutable::setTestNow();
    }

    public function test_auth_me_has_null_context_for_an_ordinary_token(): void
    {
        $token = $this->subject->createToken('ordinary', ['tenant:'.$this->tenant->id])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.impersonation', null);
    }

    private function grant(Tenant $tenant, User $subject, string $ticket): ImpersonationGrant
    {
        return ImpersonationGrant::query()->create([
            'tenant_id' => $tenant->id,
            'subject_user_id' => $subject->id,
            'operator_id' => $this->operator->id,
            'type' => GrantType::PerIncident,
            'status' => GrantStatus::Active,
            'reason' => 'Investigate tenant issue',
            'ticket_ref' => $ticket,
            'requested_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(2),
            'tenant_approved_by' => $this->tenantAdmin->id,
            'tenant_approved_at' => now()->subMinute(),
        ]);
    }

    private function makeSession(ImpersonationGrant $grant, User $subject): ImpersonationSession
    {
        return ImpersonationSession::query()->create([
            'grant_id' => $grant->id,
            'operator_id' => $this->operator->id,
            'subject_user_id' => $subject->id,
            'tenant_id' => $grant->tenant_id,
            'access_level' => 'read_only',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function event(ImpersonationSession $session, string $path): void
    {
        ImpersonationSessionEvent::query()->create([
            'session_id' => $session->id,
            'sequence' => 1,
            'event_type' => SessionEventType::RequestAuthorized,
            'outcome' => AuditOutcome::Allowed,
            'operator_id' => $session->operator_id,
            'subject_user_id' => $session->subject_user_id,
            'tenant_id' => $session->tenant_id,
            'request_id' => Str::uuid()->toString(),
            'http_method' => 'GET',
            'path' => $path,
            'details' => new ImpersonationAuditDetailsData(
                ticket_ref: 'SUP-8001',
                route_name: 'products.index',
                response_status: 200,
                error_code: null,
                resource_type: null,
                resource_id: null,
            ),
            'previous_hash' => str_repeat('0', 64),
            'hash' => hash('sha256', $session->id),
            'occurred_at' => now(),
        ]);
    }

    private function superAdmin(string $email): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Support Operator',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
