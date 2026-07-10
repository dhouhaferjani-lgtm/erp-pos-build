<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\TransferLineReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TransferLineQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_variant_aware_lines_from_a_real_initiated_transfer(): void
    {
        [$tenant, $company, $source, $destination, $user, $product, $variant] = $this->fixtures();
        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $source->id,
            quantity: '10',
            reference: 'SEED',
            userId: $user->id,
            expectedCompanyId: $company->id,
            variantId: $variant->id,
        );
        $transfer = app(StockTransferService::class)->initiate(new InitiateTransferData(
            tenantId: $tenant->id,
            companyId: $company->id,
            sourceLocationId: $source->id,
            destinationLocationId: $destination->id,
            initiatedByUserId: $user->id,
            lines: [new InitiateTransferLineData(
                productId: $product->id,
                quantity: '4.25',
                variantId: $variant->id,
            )],
        ));

        $lines = app(TransferLineReader::class)->linesForTransfer(
            $tenant->id,
            $company->id,
            $transfer->id,
        );

        $this->assertCount(1, $lines);
        $this->assertSame($product->id, $lines[0]->productId);
        $this->assertSame($variant->id, $lines[0]->variantId);
        $this->assertSame('4.2500', $lines[0]->quantity);
    }

    public function test_wrong_tenant_or_company_returns_an_empty_list(): void
    {
        [$tenant, $company, $source, $destination, $user, $product, $variant] = $this->fixtures();
        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $source->id,
            quantity: '2',
            reference: 'SEED',
            userId: $user->id,
            expectedCompanyId: $company->id,
            variantId: $variant->id,
        );
        $transfer = app(StockTransferService::class)->initiate(new InitiateTransferData(
            tenantId: $tenant->id,
            companyId: $company->id,
            sourceLocationId: $source->id,
            destinationLocationId: $destination->id,
            initiatedByUserId: $user->id,
            lines: [new InitiateTransferLineData(
                productId: $product->id,
                quantity: '1',
                variantId: $variant->id,
            )],
        ));
        $reader = app(TransferLineReader::class);

        $this->assertSame([], $reader->linesForTransfer(Str::uuid()->toString(), $company->id, $transfer->id));
        $this->assertSame([], $reader->linesForTransfer($tenant->id, Str::uuid()->toString(), $transfer->id));
    }

    /** @return array{Tenant, Company, Location, Location, User, Product, ProductVariant} */
    private function fixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        app(CompanyContext::class)->setCompanyId($company->id);
        $source = Location::factory()->create(['company_id' => $company->id]);
        $destination = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
        ]);

        return [$tenant, $company, $source, $destination, $user, $product, $variant];
    }
}
