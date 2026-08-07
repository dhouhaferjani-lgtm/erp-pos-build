<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\AdminAuditLog;
use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Application\DTOs\GrantRequestData;
use App\Modules\SupportAccess\Application\Services\ElevationService;
use App\Modules\SupportAccess\Application\Services\GrantLifecycleService;
use App\Modules\SupportAccess\Application\Services\SessionLifecycleService;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * Live PostgreSQL/database-per-tenant acceptance lane. No RefreshDatabase:
 * CREATE DATABASE cannot run inside the test transaction used by that trait.
 */
final class SupportAccessPostgresEndToEndTest extends TestCase
{
    private static int $writeProbeHits = 0;

    private ?Tenant $tenant = null;

    /** @var list<string> */
    private array $superAdminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The live support-access acceptance lane is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);
        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        self::$writeProbeHits = 0;
        Route::middleware([
            'api',
            'auth:sanctum',
            SetPermissionsTeam::class,
            EnforceTokenTenantClaim::class,
        ])->post('/_test/support-access-e2e/non-fiscal-write', static function () {
            self::$writeProbeHits++;

            return response()->json(['updated' => true]);
        })->name('test.support-access-e2e.non-fiscal-write');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($this->tenant !== null) {
            DB::connection('central')->table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->delete();

            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (\Throwable) {
                // Best-effort cleanup; the dedicated lane database is disposable.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        DB::connection('central')->table('super_admins')->whereIn('id', $this->superAdminIds)->delete();

        parent::tearDown();
    }

    public function test_live_tenant_grant_impersonate_act_revoke_and_verify_both_audit_mirrors(): void
    {
        $runId = Str::lower(Str::random(8));
        $operator = $this->superAdmin("e2e-operator-{$runId}@example.test");
        $partner = $this->superAdmin("e2e-partner-{$runId}@example.test", 'support_approver');
        config()->set('support_access.four_eyes.approver_emails', [$partner->email]);
        config()->set('support_access.permissions.read_only', ['products.view']);
        config()->set('support_access.permissions.write', ['products.update']);

        $this->tenant = Tenant::factory()->create([
            'slug' => 'support-e2e-'.Str::lower(Str::random(8)),
            'is_sensitive' => true,
        ]);
        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));

        [$subject, $tenantAdmin, $company] = $this->tenantUsers($this->tenant);

        $grants = $this->app->make(GrantLifecycleService::class);
        $grant = $grants->requestIncident($operator, new GrantRequestData(
            tenant_id: $this->tenant->id,
            subject_user_id: $subject->id,
            type: GrantType::PerIncident,
            reason: 'Synthetic PostgreSQL support-access acceptance test',
            ticket_ref: 'SUP-E2E-PG',
            starts_at: CarbonImmutable::now()->subMinute(),
            expires_at: CarbonImmutable::now()->addHour(),
        ));
        self::assertSame(GrantStatus::PendingTenantApproval, $grant->status);

        $tenantApproved = $this->tenant->run(
            fn () => $grants->approveByTenant($tenantAdmin, $grant->id),
        );
        self::assertSame(GrantStatus::PendingInternalApproval, $tenantApproved->status);
        self::assertSame(GrantStatus::Active, $grants->approveSecond($partner, $grant->id)->status);

        $sessions = $this->app->make(SessionLifecycleService::class);
        $started = $sessions->start($operator, $grant->id, $subject->id);
        $session = ImpersonationSession::query()->findOrFail($started->session_id);
        self::assertEqualsCanonicalizing(['products.view'], $started->permissions);

        $this->bearer($started->plain_text_token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $subject->id)
            ->assertJsonPath('data.impersonation.session_id', $session->id)
            ->assertJsonPath('data.impersonation.access_level', 'read_only');

        $elevations = $this->app->make(ElevationService::class);
        $elevation = $elevations->request($operator, $session->id, 'Update a non-fiscal product description');
        $elevations->approve($partner, $elevation->id);

        Auth::forgetGuards();
        $this->bearer($started->plain_text_token)
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/_test/support-access-e2e/non-fiscal-write')
            ->assertOk()
            ->assertJsonPath('updated', true);
        self::assertSame(1, self::$writeProbeHits);

        $events = ImpersonationSessionEvent::query()
            ->where('session_id', $session->id)
            ->orderBy('sequence')
            ->get();
        self::assertCount(9, $events);
        self::assertSame(9, AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count());
        self::assertSame(9, $this->tenant->run(
            fn (): int => AuditEvent::query()->where('impersonation_session_id', $session->id)->count(),
        ));

        $grants->revoke($operator, $grant->id, 'Acceptance sequence complete');
        Auth::forgetGuards();
        $this->bearer($started->plain_text_token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IMPERSONATION_ENDED');
        self::assertSame(10, ImpersonationSessionEvent::query()->where('session_id', $session->id)->count());
        self::assertSame(10, AdminAuditLog::query()->where('impersonation_session_id', $session->id)->count());
        self::assertSame(10, $this->tenant->run(
            fn (): int => AuditEvent::query()->where('impersonation_session_id', $session->id)->count(),
        ));
        $this->artisan('support-access:audit-verify', ['--session' => $session->id])->assertExitCode(0);
    }

    /** @return array{User, User, Company} */
    private function tenantUsers(Tenant $tenant): array
    {
        return $tenant->run(function () use ($tenant): array {
            $registrar = $this->app->make(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($tenant->id);
            foreach (['products.view', 'products.update', 'support-access.manage'] as $name) {
                Permission::findOrCreate($name, 'sanctum');
            }

            $subject = User::factory()->create(['tenant_id' => $tenant->id]);
            $subject->givePermissionTo(['products.view', 'products.update']);
            $tenantAdmin = User::factory()->create(['tenant_id' => $tenant->id]);
            $tenantAdmin->givePermissionTo('support-access.manage');
            $company = Company::factory()->create(['tenant_id' => $tenant->id]);
            UserCompanyMembership::query()->create([
                'user_id' => $subject->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Admin,
                'is_primary' => true,
                'accepted_at' => now(),
                'status' => MembershipStatus::Active,
            ]);

            return [$subject, $tenantAdmin, $company];
        });
    }

    private function superAdmin(string $email, string $role = 'super_admin'): SuperAdmin
    {
        $admin = SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
        $this->superAdminIds[] = $admin->id;

        return $admin;
    }

    private function bearer(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
