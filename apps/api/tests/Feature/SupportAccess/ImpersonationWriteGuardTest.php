<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\Services\ElevationService;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImpersonationWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $subject;

    private User $tenantAdmin;

    private SuperAdmin $operator;

    private SessionLifecycleService $sessions;

    private ElevationService $elevations;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->sessions = $this->app->make(SessionLifecycleService::class);
        $this->elevations = $this->app->make(ElevationService::class);

        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->get('/_test/write-guard/read', static fn () => response()->json(['reached' => true]));
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->post('/_test/write-guard/write', static fn () => response()->json(['reached' => true]));
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->post('/_test/fiscal/unknown-mutation', static fn () => response()->json(['reached' => true]))
            ->name('test.fiscal.unknown-mutation');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->delete('/_test/tenants/{tenant}/deprovision', static fn () => response()->json(['reached' => true]))
            ->name('tenants.deprovision');
        Route::middleware([
            'api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class,
        ])->post('/_test/integrations/rotate-secret', static fn () => response()->json(['reached' => true]))
            ->name('integrations.rotate-secret');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_readonly_session_allows_safe_methods_and_refuses_ordinary_writes(): void
    {
        [, , $plainToken] = $this->startedSession();

        $this->bearer($plainToken)->getJson('/_test/write-guard/read')
            ->assertOk()
            ->assertJsonPath('reached', true);
        Auth::forgetGuards();
        $this->bearer($plainToken)->postJson('/_test/write-guard/write')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY')
            ->assertJsonMissing(['reached' => true]);
    }

    public function test_write_elevation_requires_reason_and_distinct_configured_partner(): void
    {
        [, $session, $plainToken] = $this->startedSession();

        try {
            $this->elevations->request($this->operator, $session->id, '');
            self::fail('Elevation without a reason must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $elevation = $this->elevations->request($this->operator, $session->id, 'Correct a non-fiscal product description');

        try {
            $this->elevations->approve($this->operator, $elevation->id);
            self::fail('Operator must not self-approve elevation.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $unknown = $this->superAdmin('unknown@example.test');
        try {
            $this->elevations->approve($unknown, $elevation->id);
            self::fail('Unknown approver must not approve elevation.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $partner = $this->superAdmin('partner@example.test', 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [$partner->email]);
        $approved = $this->elevations->approve($partner, $elevation->id);
        $freshSession = $session->fresh();

        self::assertNotNull($freshSession);
        self::assertSame(SessionAccessLevel::WriteElevated, $freshSession->access_level);
        self::assertSame($partner->id, $freshSession->write_approved_by);
        self::assertNotNull($approved->approved_at);

        $token = CentralPersonalAccessToken::query()->findOrFail($session->personal_access_token_id);
        self::assertContains('support:write', $token->abilities);
        self::assertNotContains('support:read', $token->abilities);

        Auth::forgetGuards();
        $this->bearer($plainToken)->postJson('/_test/write-guard/write')
            ->assertOk()
            ->assertJsonPath('reached', true);
    }

    public function test_exit_is_the_only_readonly_post_safelist_and_ends_every_credential(): void
    {
        [$grant, $session, $plainToken] = $this->startedSession();

        $this->bearer($plainToken)
            ->postJson("/api/v1/support-access/sessions/{$session->id}/exit")
            ->assertOk();

        $freshSession = $session->fresh();
        $freshGrant = $grant->fresh();
        self::assertNotNull($freshSession?->ended_at);
        self::assertSame(GrantStatus::Revoked, $freshGrant?->status);
        self::assertDatabaseMissing('personal_access_tokens', ['id' => $session->personal_access_token_id]);
    }

    public function test_hard_blocked_actions_remain_refused_after_elevation(): void
    {
        [, $session, $plainToken] = $this->startedSession();
        $partner = $this->superAdmin('partner@example.test', 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [$partner->email]);
        $elevation = $this->elevations->request($this->operator, $session->id, 'Approved test write');
        $this->elevations->approve($partner, $elevation->id);

        $id = Str::uuid()->toString();
        $paths = [
            ['POST', '/_test/fiscal/unknown-mutation'],
            ['DELETE', "/_test/tenants/{$this->tenant->id}/deprovision"],
            ['POST', '/_test/integrations/rotate-secret'],
            ['POST', "/api/v1/users/{$this->subject->id}/reset-password"],
            ['POST', "/api/v1/invoices/{$id}/post"],
            ['POST', "/api/v1/invoices/{$id}/cancel"],
            ['POST', "/api/v1/credit-notes/{$id}/post"],
            ['POST', "/api/v1/credit-notes/{$id}/cancel"],
            ['POST', "/api/v1/pos/receipts/{$id}/void"],
            ['POST', "/api/v1/payments/{$id}/refund"],
            ['POST', "/api/v1/payments/{$id}/reverse"],
            ['POST', "/api/v1/fiscal/quarantine/{$id}/best-effort-parse"],
            ['POST', '/api/v1/auth/forgot-password'],
            ['POST', '/api/v1/auth/reset-password'],
            ['POST', '/api/v1/support-access/grants'],
            ['POST', "/api/v1/support-access/requests/{$id}/approve"],
        ];

        foreach ($paths as [$method, $path]) {
            Auth::forgetGuards();
            $this->withHeader('Authorization', 'Bearer '.$plainToken)
                ->json($method, $path)
                ->assertForbidden("{$method} {$path} must remain hard-blocked")
                ->assertJsonPath('error.code', 'IMPERSONATION_ACTION_BLOCKED');
        }
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
            'ticket_ref' => 'SUP-5001',
            'requested_at' => now()->subMinute(),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHours(2),
            'tenant_approved_by' => $this->tenantAdmin->id,
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
