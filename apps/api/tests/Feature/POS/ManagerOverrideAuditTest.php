<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Events\ManagerOverrideAuthorized;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit-trail coverage for the manager-PIN override gate (N1 / G2).
 *
 * ManagerPinController::verify() is the gate a manager passes to authorise a
 * cashier exceeding a limit (variance-close override). A successful
 * verification is itself a privileged action. Before this change it
 * dispatched nothing — the downstream receipt/shift columns captured the
 * manager identity but not *when* the authorisation happened, and a failed
 * verification left no trace at all.
 *
 * These tests pin the contract: every successful verification leaves an
 * audit_events row carrying tenant + company + actor (caller) + action +
 * manager id + timestamp; a failed verification leaves none.
 */
final class ManagerOverrideAuditTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANCE_PERMISSION = 'pos.close_shift_with_variance';

    private const PIN = '9876';

    private Tenant $tenant;

    private Company $company;

    /** Cashier making the request (the caller). */
    private User $cashier;

    /** Manager with the variance permission and a known PIN. */
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Manager Override Audit Tenant',
            'slug' => 'mgr-override-audit',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Override Audit Shop',
            'legal_name' => 'Override Audit Shop LLC',
            'tax_id' => 'TAX-OVERRIDE-AUDIT',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier Alice',
            'email' => 'cashier@mgr-override-audit.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        $this->manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager Bob',
            'email' => 'manager@mgr-override-audit.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);
        $this->manager->givePermissionTo(self::VARIANCE_PERMISSION);
        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        RateLimiter::clear('verify-manager-pin:127.0.0.1:'.$this->manager->id);
    }

    public function test_manager_override_authorized_event_is_persisted_by_subscriber(): void
    {
        // Authenticated actor — DomainEventSubscriber stamps user_id from
        // Auth::id() and resolves tenant_id from Auth::user().
        $this->actingAs($this->cashier, 'sanctum');

        event(new ManagerOverrideAuthorized(
            managerId: $this->manager->id,
            callerId: $this->cashier->id,
            companyId: $this->company->id,
            verifiedAt: now()->toIso8601String(),
        ));

        $audit = AuditEvent::where('aggregate_id', $this->manager->id)
            ->where('event_type', 'pos.manager_override.authorized')
            ->first();

        $this->assertNotNull(
            $audit,
            'ManagerOverrideAuthorized must be persisted to audit_events by DomainEventSubscriber.',
        );
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->cashier->id, $audit->user_id, 'Audit actor must be the caller who initiated the request.');
        $this->assertSame('User', $audit->aggregate_type);
        $this->assertSame($this->manager->id, $audit->aggregate_id);
        $this->assertSame($this->manager->id, $audit->payload['manager_id']);
        $this->assertSame($this->cashier->id, $audit->payload['caller_id']);
        $this->assertArrayHasKey('verified_at', $audit->payload);
    }

    public function test_successful_pin_verification_persists_audit_event_end_to_end(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $this->manager->id,
                'pin' => self::PIN,
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $audit = AuditEvent::where('aggregate_id', $this->manager->id)
            ->where('event_type', 'pos.manager_override.authorized')
            ->first();

        $this->assertNotNull(
            $audit,
            'A successful manager-PIN verification must leave an audit_events row.',
        );
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->cashier->id, $audit->user_id);
        $this->assertSame('User', $audit->aggregate_type);
        $this->assertSame($this->manager->id, $audit->aggregate_id);
        $this->assertSame($this->manager->id, $audit->payload['manager_id']);
        $this->assertSame($this->cashier->id, $audit->payload['caller_id']);
        $this->assertArrayHasKey('verified_at', $audit->payload);
    }

    public function test_wrong_pin_leaves_no_audit_event(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $this->manager->id,
                'pin' => '0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $this->assertSame(
            0,
            AuditEvent::where('event_type', 'pos.manager_override.authorized')->count(),
            'A failed manager-PIN verification must not leave an audit_events row.',
        );
    }

    public function test_manager_without_permission_leaves_no_audit_event(): void
    {
        $noPermManager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Junior Manager',
            'email' => 'junior@mgr-override-audit.local',
            'password' => bcrypt('password'),
            'pos_pin' => Hash::make(self::PIN),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $noPermManager->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $noPermManager->id,
                'pin' => self::PIN,
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $this->assertSame(
            0,
            AuditEvent::where('event_type', 'pos.manager_override.authorized')->count(),
            'A verification rejected on the permission check must not leave an audit_events row.',
        );
    }
}
