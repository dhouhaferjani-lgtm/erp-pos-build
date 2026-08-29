<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\VariantIndexNames;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task C2 — DB-level barcode partial-unique violation (the validate-then-write
 * race) is centrally remapped to a 422 barcode validation error, while SKU /
 * variant_code violations are NOT mislabelled as barcode errors.
 *
 * Exercises the service catch path directly (bypassing the FormRequest fast
 * path) so the 23505 mapper is what is actually under test. Requires real
 * PostgreSQL partial unique indexes — run with -c phpunit-pgsql.xml.
 */
class VariantBarcodeRaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private ProductVariantService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL: relies on partial unique indexes / SQLSTATE 23505 conflict mapping not reproduced by SQLite.');
        }

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->product = ProductFactory::new()->createOne([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->service = app(ProductVariantService::class);
    }

    private function makeVariant(string $code, ?string $barcode = null): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => $code,
            'sku' => $code,
            'name_suffix' => $code,
            'barcode' => $barcode,
        ]);
    }

    public function test_db_barcode_violation_maps_to_422_not_500(): void
    {
        $this->makeVariant('V-A', '3401234');
        $variantB = $this->makeVariant('V-B', null);

        try {
            // Bypasses the FormRequest fast-path: writes a colliding barcode
            // straight through the service, hitting the partial-unique index.
            $this->service->updateVariant($variantB, ['barcode' => '3401234']);
            $this->fail('Expected ValidationException for duplicate barcode.');
        } catch (ValidationException $e) {
            // Core contract: a DB-level barcode collision becomes a 422 barcode
            // validation error, never a raw QueryException / 500.
            $this->assertArrayHasKey('barcode', $e->errors());
            // The conflict name is strictly best-effort: under RefreshDatabase the
            // failing write aborts the wrapping transaction, so the name lookup is
            // unavailable and the generic message is used. Assert the generic
            // fallback is well-formed.
            $this->assertStringContainsString('Barcode already used by another variant', $e->errors()['barcode'][0]);
        }
    }

    public function test_sku_violation_is_not_reported_as_barcode(): void
    {
        $this->makeVariant('SKU-DUP');
        $variantB = $this->makeVariant('SKU-OTHER');

        try {
            // Force a SKU collision through the service: it must become a SKU
            // validation error, never be mislabelled as a barcode conflict.
            $this->service->updateVariant($variantB, ['sku' => 'SKU-DUP']);
            $this->fail('Expected the SKU unique violation to map to validation.');
        } catch (ValidationException $e) {
            $this->assertArrayNotHasKey('barcode', $e->errors());
            $this->assertArrayHasKey('sku', $e->errors());
        } catch (QueryException $e) {
            $this->assertStringContainsString(VariantIndexNames::COMPANY_SKU_UNIQUE, $e->getMessage());
        }
    }
}
