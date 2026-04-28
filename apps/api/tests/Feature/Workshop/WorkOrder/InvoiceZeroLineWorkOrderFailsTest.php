<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderInvoiced;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderNoLinesException;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit finding 🔴-6a: a WorkOrder with zero lines must not be allowed to
 * transition into `Invoiced`. Previously the transition returned 200 and wrote
 * a posted invoice with `total = 0.000` and 0 document lines — a fiscal-
 * compliance hazard. This test pins both the service-level exception and the
 * HTTP 422 envelope (`error.code === WORK_ORDER_NO_LINES`).
 *
 * Includes a regression check: a WO with at least one line still transitions
 * to `Invoiced` cleanly.
 */
final class InvoiceZeroLineWorkOrderFailsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([
            'work-orders.view',
            'work-orders.view_financials',
            'work-orders.transition',
            'work-orders.complete',
        ] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);
        $user->givePermissionTo($permissions);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_service_rejects_invoicing_a_work_order_with_zero_lines(): void
    {
        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertSame(0, $wo->lines()->count());

        $service = $this->app->make(WorkOrderTransitionService::class);

        $this->expectException(WorkOrderNoLinesException::class);

        try {
            $service->transition(new TransitionStatusCommand(
                work_order_id: $wo->id,
                to_status: WorkOrderStatus::Invoiced,
                reason_code: null,
                triggered_by_user_id: null,
                occurred_at: new \DateTimeImmutable,
                context: null,
            ));
        } finally {
            // Invariant: no Document must have been written for this tenant /
            // company when the guard rejects the transition. The guard must
            // fire *before* any document generation, so the documents table
            // must be untouched for this scope.
            $this->assertSame(
                0,
                Document::query()
                    ->where('tenant_id', $this->tenant->id)
                    ->where('company_id', $this->company->id)
                    ->count(),
                'No Document must be persisted when the guard rejects the transition.',
            );

            $wo->refresh();
            $this->assertSame(
                WorkOrderStatus::Completed,
                $wo->status,
                'WO status must remain Completed — the transaction must roll back.',
            );
            $this->assertNull(
                $wo->invoice_document_id,
                'WO must not be linked to any invoice Document.',
            );
        }
    }

    public function test_controller_returns_422_with_work_order_no_lines_code(): void
    {
        $this->actingAsUserWithPermissions(['work-orders.view', 'work-orders.transition']);

        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->postJson("/api/v1/workshop/work-orders/{$wo->id}/transition", [
            'to_status' => WorkOrderStatus::Invoiced->value,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'WORK_ORDER_NO_LINES');

        $this->assertSame(
            0,
            Document::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('company_id', $this->company->id)
                ->count(),
            'No Document must be persisted when the endpoint rejects the transition.',
        );

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $wo->status);
        $this->assertNull($wo->invoice_document_id);
    }

    public function test_service_allows_invoicing_a_work_order_that_has_at_least_one_line(): void
    {
        // InvoicePosted is dispatched after-commit; faking it avoids bringing up
        // the Accounting chart-of-accounts seed for this Workshop-only test.
        Event::fake([InvoicePosted::class, WorkOrderInvoiced::class]);

        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
        ]);

        $this->assertSame(1, $wo->lines()->count());

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Invoiced,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        $this->assertSame(WorkOrderStatus::Invoiced, $updated->status);
        $this->assertNotNull($updated->invoice_document_id);
        // The WO is linked to a posted invoice Document; confirm the Document
        // actually exists under the id recorded on the WO.
        $this->assertNotNull(
            Document::find($updated->invoice_document_id),
            'Posted invoice Document must be persisted for a WO with at least one line.',
        );
    }
}
