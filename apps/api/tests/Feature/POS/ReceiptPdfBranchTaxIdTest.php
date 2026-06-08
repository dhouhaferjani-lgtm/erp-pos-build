<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptPdfBranchTaxIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_uses_branch_tax_id_when_set(): void
    {
        $receipt = $this->makeReceipt('BRANCH-TAX');

        $service = $this->app->make(ReceiptPdfService::class);
        $html = view('pos.receipt', $service->viewDataFor($receipt))->render();

        $this->assertStringContainsString('BRANCH-TAX', $html);
        $this->assertStringNotContainsString('COMPANY-TAX', $html);
    }

    public function test_receipt_falls_back_to_company_tax_id_when_branch_tax_id_is_null(): void
    {
        $receipt = $this->makeReceipt(null);

        $service = $this->app->make(ReceiptPdfService::class);
        $html = view('pos.receipt', $service->viewDataFor($receipt))->render();

        $this->assertStringContainsString('COMPANY-TAX', $html);
    }

    private function makeReceipt(?string $branchTaxId): Receipt
    {
        $this->app->instance(CurrencyScaleResolverInterface::class, new class implements CurrencyScaleResolverInterface
        {
            public function getScale(?string $currencyCode = null): int
            {
                return 3;
            }

            public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
            {
                return 3;
            }
        });

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Receipt Company',
            'tax_id' => 'COMPANY-TAX',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
        ]);
        $location = Location::factory()->create([
            'company_id' => $company->id,
            'name' => 'Branch',
            'tax_id' => $branchTaxId,
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        return Receipt::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'currency' => 'EUR',
        ]);
    }
}
