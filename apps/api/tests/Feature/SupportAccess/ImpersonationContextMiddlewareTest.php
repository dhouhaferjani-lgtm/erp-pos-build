<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Models\SuperAdmin;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\Services\GrantLifecycleService;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use App\Modules\SupportAccess\Presentation\Middleware\ImpersonationContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImpersonationContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $subject;

    private User $tenantAdmin;

    private SuperAdmin $operator;

    private SessionLifecycleService $sessions;

    private GrantLifecycleService $grants;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withoutMiddleware([ResolveTenancy::class, CompanyContextMiddleware::class]);

        $this->tenant = Tenant::factory()->create();
        $this->subject = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenantAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->operator = $this->superAdmin('operator@example.test');

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenant->id);
        foreach (['products.view', 'products.update', 'support-access.manage'] as $permission) {
            Permission::findOrCreate($permission, 'sanctum');
        }
        $this->subject->givePermissionTo(['products.view', 'products.update']);
        $this->tenantAdmin->givePermissionTo('support-access.manage');
        config()->set('support_access.permissions.read_only', ['products.view']);

        $this->sessions = $this->app->make(SessionLifecycleService::class);
        $this->grants = $this->app->make(GrantLifecycleService::class);

        Route::middleware([
            'api',
            'auth:sanctum',
            SetPermissionsTeam::class,
            EnforceTokenTenantClaim::class,
        ])->get('/_test/impersonation-context', function (Request $request, ImpersonationContextProvider $context) {
            return response()->json([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'impersonation' => $context->current(),
            ]);
        })->name('test.impersonation.context');

        Route::middleware([
            'api',
            'auth:sanctum',
            SetPermissionsTeam::class,
            EnforceTokenTenantClaim::class,
            'can:products.view',
        ])->get('/_test/impersonation-permission', static fn () => response()->json(['reached' => true]));

        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ordinary_token_is_a_noop(): void
    {
        $token = $this->subject->createToken('ordinary', [
            'tenant:'.$this->tenant->id,
            '*',
        ])->plainTextToken;

        $this->bearer($token)->getJson('/_test/impersonation-context')
            ->assertOk()
            ->assertJsonPath('user_id', $this->subject->id)
            ->assertJsonPath('impersonation', null);
    }

    public function test_ordinary_user_permission_checks_preserve_spatie_wildcards(): void
    {
        config()->set('permission.enable_wildcard_permission', true);
        Permission::findOrCreate('reports.*', 'sanctum');
        $this->subject->givePermissionTo('reports.*');
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        self::assertTrue($this->subject->fresh()->hasPermissionTo('reports.export'));
    }

    public function test_malformed_or_duplicate_impersonation_claims_fail_closed(): void
    {
        $token = $this->subject->createToken('malformed', [
            'tenant:'.$this->tenant->id,
            'impersonation:'.$this->operator->id,
            'impersonation:'.Str::uuid(),
            'impersonation-session:'.Str::uuid(),
            'support:read',
        ])->plainTextToken;

        $this->bearer($token)->getJson('/_test/impersonation-context')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IMPERSONATION_ENDED');
    }

    public function test_live_session_populates_context_and_keeps_tenant_claim_enforcement(): void
    {
        [$grant, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->getJson('/_test/impersonation-context')
            ->assertOk()
            ->assertJsonPath('impersonation.operator_id', $this->operator->id)
            ->assertJsonPath('impersonation.session_id', $session->id)
            ->assertJsonPath('impersonation.subject_user_id', $this->subject->id)
            ->assertJsonPath('impersonation.tenant_id', $this->tenant->id)
            ->assertJsonPath('impersonation.ticket_ref', $grant->ticket_ref);

        $token = CentralPersonalAccessToken::query()->findOrFail($session->personal_access_token_id);
        $token->abilities = array_map(
            fn (string $ability): string => str_starts_with($ability, 'tenant:')
                ? 'tenant:'.Str::uuid()
                : $ability,
            $token->abilities,
        );
        $token->save();
        Auth::forgetGuards();

        $this->bearer($plainToken)->getJson('/_test/impersonation-context')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'TOKEN_TENANT_MISMATCH');
    }

    public function test_session_token_grant_and_identity_mismatches_fail_closed(): void
    {
        $mutations = [
            'ended session' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['ended_at' => now()]),
            'expired session' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['expires_at' => now()->subSecond()]),
            'operator mismatch' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['operator_id' => Str::uuid()->toString()]),
            'subject mismatch' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['subject_user_id' => Str::uuid()->toString()]),
            'tenant mismatch' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['tenant_id' => Str::uuid()->toString()]),
            'token id mismatch' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $session->update(['personal_access_token_id' => $session->personal_access_token_id + 9999]),
            'revoked grant' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $grant->update(['status' => GrantStatus::Revoked, 'revoked_at' => now()]),
            'expired grant' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $grant->update(['expires_at' => now()->subSecond()]),
            'grant operator mismatch' => static fn (ImpersonationSession $session, ImpersonationGrant $grant) => $grant->update(['operator_id' => Str::uuid()->toString()]),
        ];

        foreach ($mutations as $label => $mutate) {
            [$grant, $session, $plainToken] = $this->startedSession();
            $mutate($session, $grant);

            $this->bearer($plainToken)->getJson('/_test/impersonation-context')
                ->assertUnauthorized("{$label} must fail closed")
                ->assertJsonPath('error.code', 'IMPERSONATION_ENDED');
        }
    }

    public function test_grant_revocation_kills_the_same_live_token_on_the_next_request(): void
    {
        [$grant, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->getJson('/_test/impersonation-context')->assertOk();
        $this->grants->revoke($this->tenantAdmin, $grant->id, 'Tenant ended support access');

        $revocation = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', SessionEventType::GrantRevoked)
            ->sole();
        $this->assertDatabaseHas('admin_audit_logs', [
            'impersonation_session_id' => $session->id,
            'impersonator_id' => $this->operator->id,
            'impersonation_hash' => $revocation->hash,
        ]);
        tenancy()->initialize($this->tenant);
        self::assertTrue(AuditEvent::query()->where([
            'impersonation_session_id' => $session->id,
            'impersonator_id' => $this->operator->id,
            'impersonation_hash' => $revocation->hash,
        ])->exists());
        tenancy()->end();

        $this->bearer($plainToken)->getJson('/_test/impersonation-context')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IMPERSONATION_ENDED');
        self::assertNotNull($session->fresh());
    }

    public function test_central_session_lookup_failure_is_not_downgraded_to_ordinary_access(): void
    {
        [, , $plainToken] = $this->startedSession();
        Schema::rename('impersonation_sessions', 'impersonation_sessions_unavailable');
        Auth::forgetGuards();

        $this->bearer($plainToken)->getJson('/_test/impersonation-context')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IMPERSONATION_ENDED');
    }

    public function test_live_permission_intersection_is_recomputed_before_gate_checks(): void
    {
        [, , $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->getJson('/_test/impersonation-permission')
            ->assertOk()
            ->assertJsonPath('reached', true);

        $this->subject->revokePermissionTo('products.view');
        $this->subject->unsetRelation('permissions');
        Auth::forgetGuards();

        $this->bearer($plainToken)->getJson('/_test/impersonation-permission')
            ->assertForbidden()
            ->assertJsonMissing(['reached' => true]);
    }

    public function test_production_middleware_order_is_claim_then_context_before_authorization(): void
    {
        $route = Route::getRoutes()->getByName('test.impersonation.context');
        self::assertNotNull($route);
        $middleware = $this->app->make(Router::class)->gatherRouteMiddleware($route);

        self::assertLessThan(
            array_search(ImpersonationContext::class, $middleware, true),
            array_search(EnforceTokenTenantClaim::class, $middleware, true),
        );
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
            'reason' => 'Investigate invoice rendering',
            'ticket_ref' => 'SUP-4001',
            'requested_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(2),
            'tenant_approved_by' => $this->tenantAdmin->id,
            'tenant_approved_at' => now()->subMinute(),
        ]);
        $started = $this->sessions->start($this->operator, $grant->id, $this->subject->id);
        $session = ImpersonationSession::query()->findOrFail($started->session_id);

        return [$grant, $session, $started->plain_text_token];
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

    private function bearer(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
