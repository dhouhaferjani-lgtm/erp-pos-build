<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\SuperAdmin;
use App\Modules\Identity\Application\DTOs\AuthUserData;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionPermission;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Services\EffectivePermissionService;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private SessionLifecycleService $sessions;

    private Tenant $tenant;

    private User $subject;

    private SuperAdmin $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator = $this->superAdmin('operator@example.test');

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        foreach (['products.view', 'products.update', 'invoices.view', 'secrets.view'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        $this->subject->givePermissionTo(['products.view', 'products.update', 'invoices.view']);

        config()->set('support_access.permissions.read_only', ['products.view', 'invoices.view', 'secrets.view']);
        $this->sessions = $this->app->make(SessionLifecycleService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_no_active_grant_and_expired_grant_fail_closed(): void
    {
        $pending = $this->grant(status: GrantStatus::PendingTenantApproval);

        try {
            $this->sessions->start($this->operator, $pending->id, $this->subject->id);
            self::fail('Pending consent must not mint a token.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $expired = $this->grant(status: GrantStatus::Active, expiresAt: CarbonImmutable::now()->subSecond());
        $this->expectException(AuthorizationException::class);
        $this->sessions->start($this->operator, $expired->id, $this->subject->id);
    }

    public function test_http_start_without_a_grant_returns_forbidden(): void
    {
        $this->actingAs($this->operator, 'sanctum-admin')
            ->postJson('/api/v1/admin/impersonation/sessions', [
                'grant_id' => Str::uuid()->toString(),
                'subject_user_id' => $this->subject->id,
            ])
            ->assertForbidden();
    }

    public function test_wrong_operator_tenant_or_subject_cannot_use_grant(): void
    {
        $grant = $this->grant();
        $otherOperator = $this->superAdmin('other@example.test');

        try {
            $this->sessions->start($otherOperator, $grant->id, $this->subject->id);
            self::fail('A different operator must not use an incident grant.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $otherTenant = Tenant::factory()->create();
        $otherSubject = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $this->expectException(AuthorizationException::class);
        $this->sessions->start($this->operator, $grant->id, $otherSubject->id);
    }

    public function test_pre_granted_window_cannot_be_started_by_its_second_approver(): void
    {
        $grant = ImpersonationGrant::query()->create([
            'tenant_id' => $this->tenant->id,
            'subject_user_id' => null,
            'operator_id' => null,
            'type' => GrantType::PreGrantedWindow,
            'status' => GrantStatus::Active,
            'reason' => 'Sensitive tenant support window',
            'ticket_ref' => 'SUP-FOUR-EYES',
            'requested_at' => CarbonImmutable::now()->subMinute(),
            'starts_at' => CarbonImmutable::now()->subMinute(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'tenant_approved_by' => Str::uuid()->toString(),
            'tenant_approved_at' => CarbonImmutable::now()->subMinute(),
            'second_approved_by' => $this->operator->id,
            'second_approved_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $this->expectException(AuthorizationException::class);
        $this->sessions->start($this->operator, $grant->id, $this->subject->id);
    }

    public function test_tokenable_is_subject_with_unique_claims_and_expiry_is_capped_at_sixty_minutes(): void
    {
        $now = CarbonImmutable::parse('2026-08-06 12:00:00');
        CarbonImmutable::setTestNow($now);
        $grant = $this->grant(expiresAt: $now->addHours(3));

        $started = $this->sessions->start($this->operator, $grant->id, $this->subject->id);
        $token = CentralPersonalAccessToken::query()->findOrFail($started->personal_access_token_id);

        self::assertSame(User::class, $token->tokenable_type);
        self::assertSame($this->subject->id, $token->tokenable_id);
        self::assertSame($this->subject->name, $started->subject_name);
        self::assertSame($now->addMinutes(60)->toISOString(), $token->expires_at?->toISOString());
        self::assertSame($token->expires_at?->toISOString(), $started->expires_at->toISOString());

        $abilities = $token->abilities;
        self::assertIsArray($abilities);
        self::assertSame(1, count(array_filter($abilities, fn (mixed $ability): bool => $ability === 'tenant:'.$this->tenant->id)));
        self::assertSame(1, count(array_filter($abilities, fn (mixed $ability): bool => $ability === 'impersonation:'.$this->operator->id)));
        self::assertSame(1, count(array_filter($abilities, fn (mixed $ability): bool => $ability === 'impersonation-session:'.$started->session_id)));
        self::assertSame(1, count(array_filter($abilities, static fn (mixed $ability): bool => $ability === 'support:read')));

        $shortGrant = $this->grant(expiresAt: $now->addMinutes(20));
        $short = $this->sessions->start($this->operator, $shortGrant->id, $this->subject->id);
        self::assertSame($now->addMinutes(20)->toISOString(), $short->expires_at->toISOString());
    }

    public function test_readonly_permissions_are_intersection_and_auth_user_data_is_filtered(): void
    {
        $grant = $this->grant();
        $started = $this->sessions->start($this->operator, $grant->id, $this->subject->id);
        $token = CentralPersonalAccessToken::query()->findOrFail($started->personal_access_token_id);

        self::assertEqualsCanonicalizing(
            ['products.view', 'invoices.view'],
            $started->permissions,
        );
        self::assertDatabaseHas('impersonation_session_permissions', [
            'session_id' => $started->session_id,
            'permission' => 'products.view',
        ]);
        self::assertSame(2, ImpersonationSessionPermission::query()->where('session_id', $started->session_id)->count());
        self::assertTrue($token->can('permission:products.view'));
        self::assertFalse($token->can('permission:products.update'));
        self::assertFalse($token->can('permission:secrets.view'));

        $this->subject->withAccessToken($token);
        self::assertTrue($this->subject->hasPermissionTo('products.view'));
        self::assertFalse($this->subject->hasPermissionTo('products.update'));

        $authData = AuthUserData::fromUser($this->subject);
        self::assertEqualsCanonicalizing(['products.view', 'invoices.view'], $authData->permissions);
    }

    public function test_subject_permission_removal_is_visible_on_next_live_intersection(): void
    {
        $grant = $this->grant();
        $started = $this->sessions->start($this->operator, $grant->id, $this->subject->id);
        $token = CentralPersonalAccessToken::query()->findOrFail($started->personal_access_token_id);
        $this->subject->withAccessToken($token);

        $this->subject->revokePermissionTo('products.view');
        $this->subject->unsetRelation('permissions');

        $effective = $this->sessions->effectivePermissionsFor($this->subject);
        self::assertNotContains('products.view', $effective);
        self::assertFalse($this->subject->hasPermissionTo('products.view'));
        self::assertNotContains('products.view', AuthUserData::fromUser($this->subject)->permissions);
    }

    public function test_self_referential_permissions_are_never_intersectable(): void
    {
        $permissions = $this->app->make(EffectivePermissionService::class);

        self::assertSame(
            ['products.update'],
            $permissions->intersect([
                'support-access.manage',
                'support-access.view',
                'roles.manage',
                'users.assign-roles',
                'products.update',
            ], SessionAccessLevel::WriteElevated),
        );
    }

    private function grant(
        GrantStatus $status = GrantStatus::Active,
        ?CarbonImmutable $expiresAt = null,
    ): ImpersonationGrant {
        return ImpersonationGrant::query()->create([
            'tenant_id' => $this->tenant->id,
            'subject_user_id' => $this->subject->id,
            'operator_id' => $this->operator->id,
            'type' => GrantType::PerIncident,
            'status' => $status,
            'reason' => 'Investigate invoice rendering',
            'ticket_ref' => 'SUP-3001',
            'requested_at' => CarbonImmutable::now()->subMinute(),
            'starts_at' => CarbonImmutable::now()->subMinute(),
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addHours(2),
            'tenant_approved_by' => Str::uuid()->toString(),
            'tenant_approved_at' => CarbonImmutable::now()->subMinute(),
        ]);
    }

    private function superAdmin(string $email): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
