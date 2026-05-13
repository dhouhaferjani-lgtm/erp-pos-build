<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RepositoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_repo_find_by_id(): void
    {
        $wo = WorkOrder::factory()->create();
        $repo = $this->app->make(WorkOrderRepositoryInterface::class);

        $found = $repo->findById($wo->id);
        $this->assertNotNull($found);
        $this->assertSame($wo->id, $found->id);
    }

    public function test_work_order_repo_returns_null_for_unknown(): void
    {
        $repo = $this->app->make(WorkOrderRepositoryInterface::class);
        $this->assertNull($repo->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function test_work_order_repo_scoped_find_by_id_rejects_foreign_scope(): void
    {
        $tenantA = Tenant::factory()->create();
        $companyA = Company::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantB = Tenant::factory()->create();
        $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);

        $foreign = WorkOrder::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
        ]);

        $repo = $this->app->make(WorkOrderRepositoryInterface::class);

        $this->assertNull($repo->findByIdForScope($tenantA->id, $companyA->id, $foreign->id));
        $this->assertNotNull($repo->findByIdForScope($tenantB->id, $companyB->id, $foreign->id));
    }

    public function test_work_order_repo_scoped_find_for_update_rejects_foreign_scope(): void
    {
        $tenantA = Tenant::factory()->create();
        $companyA = Company::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantB = Tenant::factory()->create();
        $companyB = Company::factory()->create(['tenant_id' => $tenantB->id]);

        $foreign = WorkOrder::factory()->create([
            'tenant_id' => $tenantB->id,
            'company_id' => $companyB->id,
        ]);

        $repo = $this->app->make(WorkOrderRepositoryInterface::class);

        $this->assertNull($repo->findForUpdateForScope($tenantA->id, $companyA->id, $foreign->id));
        $this->assertNotNull($repo->findForUpdateForScope($tenantB->id, $companyB->id, $foreign->id));
    }

    public function test_work_order_repo_paginate_filters_by_tenant_and_company(): void
    {
        $wo1 = WorkOrder::factory()->create();
        // Distinct tenant to make the filter effect visible.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        WorkOrder::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $repo = $this->app->make(WorkOrderRepositoryInterface::class);
        $page = $repo->paginate($wo1->tenant_id, $wo1->company_id, [], 25);

        $this->assertSame(1, $page->total());
    }

    public function test_work_order_repo_list_by_statuses(): void
    {
        $received = WorkOrder::factory()->create();
        WorkOrder::factory()->diagnosed()->create([
            'tenant_id' => $received->tenant_id,
            'company_id' => $received->company_id,
        ]);

        $repo = $this->app->make(WorkOrderRepositoryInterface::class);
        $rows = $repo->listByStatuses(
            $received->tenant_id,
            $received->company_id,
            [WorkOrderStatus::Received],
        );

        $this->assertCount(1, $rows);
        $this->assertSame($received->id, $rows[0]->id);
    }

    public function test_work_order_line_repo_list_for_work_order(): void
    {
        $wo = WorkOrder::factory()->create();
        WorkOrderLine::factory()->for($wo)->count(3)->create();

        $repo = $this->app->make(WorkOrderLineRepositoryInterface::class);
        $lines = $repo->listForWorkOrder($wo->id);

        $this->assertCount(3, $lines);
    }

    public function test_work_order_line_repo_list_part_lines_only(): void
    {
        $wo = WorkOrder::factory()->create();
        WorkOrderLine::factory()->for($wo)->part()->create();
        WorkOrderLine::factory()->for($wo)->labor()->create();

        $repo = $this->app->make(WorkOrderLineRepositoryInterface::class);
        $parts = $repo->listPartLinesForWorkOrder($wo->id);

        $this->assertCount(1, $parts);
        $this->assertSame(WorkOrderLineType::Part, $parts[0]->line_type);
    }

    public function test_sequence_generates_monotonic_numbers(): void
    {
        $wo = WorkOrder::factory()->create();
        $seq = $this->app->make(WorkOrderSequenceInterface::class);

        $n1 = $seq->next($wo->company_id);
        $n2 = $seq->next($wo->company_id);
        $n3 = $seq->next($wo->company_id);

        $year = date('Y');
        $this->assertSame("WO-{$year}-000001", $n1);
        $this->assertSame("WO-{$year}-000002", $n2);
        $this->assertSame("WO-{$year}-000003", $n3);
    }

    public function test_sequence_is_scoped_per_company(): void
    {
        // Two distinct companies under the same tenant. Sequences must reset
        // independently per (tenant, company, year).
        $tenant = Tenant::factory()->create();
        $companyA = Company::factory()->create(['tenant_id' => $tenant->id]);
        $companyB = Company::factory()->create(['tenant_id' => $tenant->id]);

        $seq = $this->app->make(WorkOrderSequenceInterface::class);
        $this->assertSame('WO-'.date('Y').'-000001', $seq->next($companyA->id));
        $this->assertSame('WO-'.date('Y').'-000001', $seq->next($companyB->id));
        $this->assertSame('WO-'.date('Y').'-000002', $seq->next($companyA->id));
    }
}
