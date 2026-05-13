<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class WorkshopHttpTenantIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $manager;

    private TechnicianProfile $technician;

    private WorkOrder $foreignWorkOrder;

    private WorkOrderLine $foreignLine;

    private WorkOrderAssignment $foreignAssignment;

    private ServiceBundle $localBundle;

    private ServiceBundle $foreignBundle;

    private ServiceBundleComponent $foreignComponent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->tenantB = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);

        foreach ([
            'work-orders.view',
            'work-orders.view_financials',
            'work-orders.update',
            'work-orders.assign',
            'work-orders.approve',
            'work-orders.transition',
            'work-orders.cancel',
            'work-orders.complete',
            'workshop-bundles.view',
            'workshop-bundles.manage',
        ] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->manager->givePermissionTo([
            'work-orders.view',
            'work-orders.view_financials',
            'work-orders.update',
            'work-orders.assign',
            'work-orders.approve',
            'work-orders.transition',
            'work-orders.cancel',
            'work-orders.complete',
            'workshop-bundles.view',
            'workshop-bundles.manage',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->companyA->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        Sanctum::actingAs($this->manager, ['tenant:'.$this->tenantA->id]);

        $this->technician = TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->foreignWorkOrder = WorkOrder::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'status' => WorkOrderStatus::Approved,
        ]);
        $this->foreignLine = WorkOrderLine::factory()->part()->create([
            'tenant_id' => $this->tenantB->id,
            'work_order_id' => $this->foreignWorkOrder->id,
        ]);
        $this->foreignAssignment = WorkOrderAssignment::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'work_order_id' => $this->foreignWorkOrder->id,
        ]);

        $this->localBundle = ServiceBundle::factory()->forCompany($this->tenantA->id, $this->companyA->id)->create();
        $this->foreignBundle = ServiceBundle::factory()->forCompany($this->tenantB->id, $this->companyB->id)->create();
        $this->foreignComponent = ServiceBundleComponent::factory()->forBundle($this->foreignBundle)->create();
    }

    public function test_work_order_http_matrix_rejects_foreign_work_order_ids(): void
    {
        $id = $this->foreignWorkOrder->id;
        $lineId = $this->foreignLine->id;
        $assignmentId = $this->foreignAssignment->id;

        $cases = [
            ['GET', "/api/v1/workshop/work-orders/{$id}", []],
            ['PATCH', "/api/v1/workshop/work-orders/{$id}", ['diagnosis' => 'Scoped update']],
            ['POST', "/api/v1/workshop/work-orders/{$id}/lines", $this->linePayload()],
            ['POST', "/api/v1/workshop/work-orders/{$id}/lines/bundle", ['bundle_id' => $this->localBundle->id, 'quantity' => '1']],
            ['PATCH', "/api/v1/workshop/work-orders/{$id}/lines/{$lineId}", ['display_name' => 'Scoped line']],
            ['DELETE', "/api/v1/workshop/work-orders/{$id}/lines/{$lineId}", []],
            ['PUT', "/api/v1/workshop/work-orders/{$id}/lines/reorder", ['ordered_line_ids' => [$lineId]]],
            ['POST', "/api/v1/workshop/work-orders/{$id}/assignments", ['technician_profile_id' => $this->technician->id]],
            ['DELETE', "/api/v1/workshop/work-orders/{$id}/assignments/{$assignmentId}", []],
            ['PUT', "/api/v1/workshop/work-orders/{$id}/primary-technician", ['technician_profile_id' => $this->technician->id]],
            ['POST', "/api/v1/workshop/work-orders/{$id}/approval", ['approval_method' => ApprovalMethod::Phone->value]],
            ['POST', "/api/v1/workshop/work-orders/{$id}/transition", ['to_status' => WorkOrderStatus::InProgress->value]],
            ['POST', "/api/v1/workshop/work-orders/{$id}/cancel", ['reason_code' => CancellationReason::Other->value]],
            ['POST', "/api/v1/workshop/work-orders/{$id}/complete", ['completion_mileage' => 12345]],
        ];

        foreach ($cases as [$method, $uri, $payload]) {
            $response = $this->json($method, $uri, $payload);
            $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri}: ".$response->getContent());
        }
    }

    public function test_bundle_http_matrix_rejects_foreign_bundle_ids(): void
    {
        $id = $this->foreignBundle->id;
        $componentId = $this->foreignComponent->id;

        $cases = [
            ['GET', "/api/v1/workshop/bundles/{$id}", []],
            ['PATCH', "/api/v1/workshop/bundles/{$id}", ['name' => 'Scoped bundle']],
            ['DELETE', "/api/v1/workshop/bundles/{$id}", []],
            ['GET', "/api/v1/workshop/bundles/{$id}/expansion", []],
            ['POST', "/api/v1/workshop/bundles/{$id}/components", $this->componentPayload()],
            ['PATCH', "/api/v1/workshop/bundles/{$id}/components/{$componentId}", ['quantity' => '2']],
            ['DELETE', "/api/v1/workshop/bundles/{$id}/components/{$componentId}", []],
            ['PUT', "/api/v1/workshop/bundles/{$id}/vehicle-applicabilities", ['applicabilities' => [[
                'platform_vehicle_id' => null,
                'vehicle_type' => null,
                'vehicle_display' => null,
                'year_from' => null,
                'year_to' => null,
            ]]]],
        ];

        foreach ($cases as [$method, $uri, $payload]) {
            $response = $this->json($method, $uri, $payload);
            $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri}: ".$response->getContent());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(): array
    {
        return [
            'line_type' => WorkOrderLineType::MiscFee->value,
            'display_name' => 'Shop supplies',
            'quantity' => '1',
            'unit' => 'each',
            'unit_price' => '5',
            'tax_rate' => '0',
            'discount_percent' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(): array
    {
        return [
            'component_type' => BundleComponentType::NestedBundle->value,
            'component_id' => $this->localBundle->id,
            'quantity' => '1',
            'unit_id' => $this->foreignComponent->unit_id,
            'is_optional' => false,
            'display_order' => 0,
        ];
    }
}
