<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\Enums\ProductIdentityFailure;
use App\Shared\Enums\ProductIdentityMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
    }

    public function test_supplied_sku_miss_falls_to_barcode_but_never_to_name(): void
    {
        $barcodeMatch = $this->product('BARCODE-SKU', 'Shared barcode name', '12345');
        $this->product('NAME-SKU', 'Name only match', null);
        $resolver = app(ProductResolverInterface::class);

        $resolved = $resolver->resolve(
            $this->tenant->id,
            $this->company->id,
            'MISSING-SKU',
            '12345',
            'Shared barcode name',
        );
        $this->assertSame($barcodeMatch->id, $resolved->productId);
        $this->assertSame(ProductIdentityMatch::Barcode, $resolved->matchedBy);

        $miss = $resolver->resolve(
            $this->tenant->id,
            $this->company->id,
            'MISSING-SKU',
            null,
            'Name only match',
        );
        $this->assertNull($miss->productId);
        $this->assertNull($miss->matchedBy);
    }

    public function test_two_company_local_barcode_matches_are_reported_as_ambiguous(): void
    {
        $this->product('CANDIDATE-A', 'Candidate A', 'DUPLICATE');
        $this->product('CANDIDATE-B', 'Candidate B', 'DUPLICATE');

        $resolved = app(ProductResolverInterface::class)->resolve(
            $this->tenant->id,
            $this->company->id,
            null,
            'DUPLICATE',
            'New name',
        );

        $this->assertTrue($resolved->isBarcodeAmbiguous());
        $this->assertSame(['CANDIDATE-A', 'CANDIDATE-B'], $resolved->candidateSkus);
    }

    public function test_soft_deleted_sku_holder_is_refused_with_the_stable_leading_code(): void
    {
        $deleted = $this->product('HELD-SKU', 'Deleted product', null);
        $deleted->delete();

        $resolved = app(ProductResolverInterface::class)->resolve(
            $this->tenant->id,
            $this->company->id,
            'HELD-SKU',
            null,
            'Replacement',
        );

        $this->assertSame(ProductIdentityFailure::SkuHeldByDeletedProduct, $resolved->failure);
        $this->assertSame('HELD-SKU', $resolved->failureSku);
        $this->assertNull($resolved->productId);
    }

    public function test_blank_sku_and_barcode_resolve_by_normalized_name(): void
    {
        $existing = $this->product('NAME-GENERATED', '  Brake Pad  ', null);

        $resolved = app(ProductResolverInterface::class)->resolve(
            $this->tenant->id,
            $this->company->id,
            null,
            null,
            'brake pad',
        );

        $this->assertSame($existing->id, $resolved->productId);
        $this->assertSame(ProductIdentityMatch::Name, $resolved->matchedBy);
    }

    private function product(string $sku, string $name, ?string $barcode): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'barcode' => $barcode,
        ]);
    }
}
