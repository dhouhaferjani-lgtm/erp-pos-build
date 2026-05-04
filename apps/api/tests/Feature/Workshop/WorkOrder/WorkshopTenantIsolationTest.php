<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Section 8 (api.workshop cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 4 callsites in WorkOrderCreationService — all bare
 * `Partner::query()->find($partnerId)` reads previously used to derive
 * tenant_id / company_id / opened_by_user_id / currency for the new
 * work order. The pre-fix design implicitly trusted whichever tenant the
 * partner was attached to, which made cross-tenant work-order creation
 * possible whenever a malicious or buggy upstream supplied a foreign
 * partner UUID.
 *
 *   - api.workshop.001 — resolveTenantId  (line 124, was Partner::find)
 *   - api.workshop.002 — resolveCompanyId (line 134, was Partner::find)
 *   - api.workshop.003 — resolveOpenedByUserId (line 144, was Partner::find)
 *   - api.workshop.004 — resolveCurrency (line 162, was Partner::find +
 *                         unscoped Company::find)
 *
 * Fix:
 *   - WorkOrderCreationServiceInterface signature gains explicit
 *     $tenantId, $companyId parameters. Caller (AppointmentConversionService)
 *     supplies them from the appointment's own tenant_id / company_id
 *     (the appointment is already loaded inside a tenant-scoped repository).
 *   - WorkOrderCreationService uses the supplied tenant + company directly,
 *     verifies the supplied partner_id belongs to that tenant + company
 *     (defense-in-depth — refuses cross-tenant partner exfiltration),
 *     and scopes the Currency lookup the same way.
 *
 * The api.workshop service-tier callsite is reached from a Scheduling
 * domain-event listener (no HTTP / CompanyContext). Hence the explicit
 * parameter handoff rather than constructor-injected CompanyContext.
 */
final class WorkshopTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private Partner $partnerA;

    private Partner $partnerB;

    private Vehicle $vehicleA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-workshop-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-workshop-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-WO',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-WO',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Customer A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Customer B',
            'type' => 'customer',
        ]);

        $this->vehicleA = Vehicle::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
        ]);

        // resolveOpenedByUserId() picks the first User in the tenant.
        // Seed one so the WO insert FK constraint is satisfied.
        User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tech A',
            'email' => 'tech-a-wo@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * workshop_work_orders.appointment_id has a FK to scheduling_appointments
     * (post 🟠-1 fix), so each test must seed a real appointment fixture.
     */
    private function makeAppointment(): Appointment
    {
        return Appointment::factory()->confirmed()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'customer_partner_id' => $this->partnerA->id,
            'vehicle_id' => $this->vehicleA->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.workshop.001/002 — partner-scope guard refuses cross-tenant
    // ──────────────────────────────────────────────────────────────────

    public function test_create_from_appointment_refuses_cross_tenant_partner(): void
    {
        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing cross-tenant work order creation');

        $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerB->id, // foreign tenant
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );
    }

    public function test_create_from_appointment_refuses_partner_in_wrong_company_within_same_tenant(): void
    {
        // Same-tenant but different company. Partner A belongs to companyA;
        // we ask for a WO under tenantA + a sibling company that doesn't
        // own the partner. Should still be refused.
        $siblingCompany = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A2',
            'legal_name' => 'Company A2 LLC',
            'tax_id' => 'TAX-A2-WO',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing cross-tenant work order creation');

        $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerA->id, // belongs to companyA, not siblingCompany
            tenantId: $this->tenantA->id,
            companyId: $siblingCompany->id,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.workshop.001/002/003/004 — happy-path passes through the
    // scoped reads and produces a WO in the supplied tenant + company.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_from_appointment_succeeds_with_in_scope_partner(): void
    {
        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);

        $wo = $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $this->assertSame($this->tenantA->id, $wo->tenant_id);
        $this->assertSame($this->companyA->id, $wo->company_id);
        $this->assertSame($this->partnerA->id, $wo->customer_partner_id);
        $this->assertSame($this->vehicleA->id, $wo->vehicle_id);
        $this->assertSame('EUR', $wo->currency);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariant — Partner-existence guard MUST filter
    // by tenant_id AND company_id (Treasury R3 Finding-14 invariant).
    // ──────────────────────────────────────────────────────────────────

    public function test_create_from_appointment_partner_guard_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);
        $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Locate the partner-existence guard (assertPartnerInScope) — it is
        // the first `select * from "partners"` (or equivalent EXISTS shape)
        // hitting the partners table within this transaction.
        $partnerGuardQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partners"')
                && str_contains($sql, '"id" =')
            ) {
                $partnerGuardQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnerGuardQuery,
            'Partner-existence guard query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $partnerGuardQuery,
            'assertPartnerInScope must filter by tenant_id. Got SQL: '.$partnerGuardQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $partnerGuardQuery,
            'assertPartnerInScope must also filter by company_id. Got SQL: '.$partnerGuardQuery,
        );
    }

    public function test_create_from_appointment_currency_lookup_query_includes_tenant_predicate(): void
    {
        DB::enableQueryLog();

        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);
        $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // resolveCurrency triggers a `select * from "companies" where ...`.
        $companyQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "companies"')
                && str_contains($sql, '"id" =')
            ) {
                $companyQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $companyQuery,
            'resolveCurrency company lookup must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $companyQuery,
            'resolveCurrency must filter by tenant_id. Got SQL: '.$companyQuery,
        );
    }

    public function test_created_work_order_persists_with_supplied_tenant_and_company(): void
    {
        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);
        $wo = $creation->createFromAppointment(
            appointmentId: $this->makeAppointment()->id,
            plannedServices: [],
            vehicleId: $this->vehicleA->id,
            partnerId: $this->partnerA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        // Ensure the row is genuinely scoped — a downstream tenant-A query
        // sees it; a tenant-B query does not.
        $sameTenant = WorkOrder::query()
            ->where('tenant_id', $this->tenantA->id)
            ->where('company_id', $this->companyA->id)
            ->whereKey($wo->id)
            ->first();
        $crossTenant = WorkOrder::query()
            ->where('tenant_id', $this->tenantB->id)
            ->whereKey($wo->id)
            ->first();

        $this->assertNotNull($sameTenant);
        $this->assertNull($crossTenant);
    }
}
