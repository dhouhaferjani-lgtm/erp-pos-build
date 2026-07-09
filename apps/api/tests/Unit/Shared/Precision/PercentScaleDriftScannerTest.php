<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Precision;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use App\Shared\Precision\PercentScaleDriftScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PercentScaleDriftScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_percent_values_that_would_be_truncated_by_scale_2_narrowing(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $bundle = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create();
        $workOrderLine = WorkOrderLine::factory()->create();
        $product = Product::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        DB::table('services')
            ->where('id', $service->id)
            ->update(['tax_rate' => '19.125']);
        DB::table('workshop_service_bundles')
            ->where('id', $bundle->id)
            ->update(['tax_rate' => '19.125']);
        DB::table('workshop_work_order_lines')
            ->where('id', $workOrderLine->id)
            ->update(['tax_rate' => '19.125']);
        DB::table('products')
            ->where('id', $product->id)
            ->update([
                'target_margin_override' => '12.125',
                'minimum_margin_override' => '8.125',
            ]);

        $findings = (new PercentScaleDriftScanner)->scanCurrentConnection();

        $this->assertContains([
            'table' => 'services',
            'column' => 'tax_rate',
            'count' => 1,
        ], $findings);
        $this->assertContains([
            'table' => 'workshop_service_bundles',
            'column' => 'tax_rate',
            'count' => 1,
        ], $findings);
        $this->assertContains([
            'table' => 'workshop_work_order_lines',
            'column' => 'tax_rate',
            'count' => 1,
        ], $findings);
        $this->assertContains([
            'table' => 'products',
            'column' => 'target_margin_override',
            'count' => 1,
        ], $findings);
        $this->assertContains([
            'table' => 'products',
            'column' => 'minimum_margin_override',
            'count' => 1,
        ], $findings);
    }

    public function test_all_percent_drift_targets_exist_in_the_schema(): void
    {
        foreach (PercentScaleDriftScanner::TARGET_COLUMNS as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Expected scanner target table [{$table}] to exist.");

            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Expected scanner target column [{$table}.{$column}] to exist.",
                );
            }
        }
    }
}
