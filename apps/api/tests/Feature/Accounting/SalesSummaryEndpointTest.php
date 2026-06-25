<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Accounting\Concerns\InteractsWithOwnerReporting;
use Tests\TestCase;

final class SalesSummaryEndpointTest extends TestCase
{
    use InteractsWithOwnerReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnerReportingFixtures();
    }

    public function test_owner_can_fetch_sales_summary_with_returns(): void
    {
        Sanctum::actingAs($this->owner);
        $sale = $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '120.00');
        $this->seedReturn($this->locationA, $this->terminalA, '2026-06-11 10:00:00', '-20.00', $sale);

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16', $this->companyHeaders())
            ->assertOk()
            ->assertJsonPath('data.grossSales', '120.00')
            ->assertJsonPath('data.returnsAmount', '20.00')
            ->assertJsonPath('data.salesCount', 1)
            ->assertJsonStructure(['data' => ['currencyCode', 'grossSales', 'returnsAmount', 'salesCount', 'averageBasket', 'delta' => ['grossSalesAbs', 'grossSalesPct']]]);
    }

    public function test_location_scope_is_enforced(): void
    {
        Sanctum::actingAs($this->owner);
        $this->seedReceipt($this->locationA, $this->terminalA, '2026-06-10 10:00:00', '120.00');
        $this->seedReceipt($this->locationB, $this->terminalB, '2026-06-10 10:00:00', '300.00');

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16&location_ids[]='.$this->locationA->id, $this->companyHeaders())
            ->assertOk()
            ->assertJsonPath('data.grossSales', '120.00');
    }

    public function test_non_owner_is_forbidden(): void
    {
        Sanctum::actingAs($this->userWithoutPermission);

        $this->getJson('/api/v1/reports/sales/summary?from=2026-06-09&to=2026-06-16', $this->companyHeaders())
            ->assertForbidden();
    }
}
