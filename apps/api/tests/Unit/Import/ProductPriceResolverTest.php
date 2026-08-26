<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Import\Services\ProductPriceResolver;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use App\Shared\DTOs\ProductTaxDefaultDTO;
use App\Shared\Enums\ProductTaxDefaultSource;
use PHPUnit\Framework\TestCase;

final class ProductPriceResolverTest extends TestCase
{
    public function test_tax_default_contract_can_be_faked_without_database(): void
    {
        $resolver = new class implements TaxDefaultResolverInterface
        {
            public function getDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): string
            {
                return $this->resolveDefaultTaxForNewProduct($company, $categoryId)->taxRate;
            }

            public function resolveDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): ProductTaxDefaultDTO
            {
                return new ProductTaxDefaultDTO('19.00', ProductTaxDefaultSource::CompanyDefault);
            }
        };

        $this->assertSame('19.00', $resolver->getDefaultTaxForNewProduct(new Company));
        $this->assertSame(
            ProductTaxDefaultSource::CompanyDefault,
            $resolver->resolveDefaultTaxForNewProduct(new Company)->source,
        );
    }

    public function test_ttc_authority_with_consistent_candidates_has_no_warnings(): void
    {
        $result = $this->resolver()->resolve([
            'sale_price_incl_tax' => '11.900',
            'sale_price_excl_tax' => '10.000',
            'purchase_price' => '8.000',
            'margin' => '25',
        ], 'ttc', '19.00');

        $this->assertSame('11.900', $result['sale_price']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_ttc_authority_keeps_ttc_and_warns_when_ht_conflicts(): void
    {
        $result = $this->resolver()->resolve([
            'sale_price_incl_tax' => '12.000',
            'sale_price_excl_tax' => '10.000',
        ], 'ttc', '19.00');

        $this->assertSame('12.000', $result['sale_price']);
        $this->assertSame('price_conflict', $result['warnings'][0]['code']);
        $this->assertSame('sale_price_excl_tax: provided implies 11.900, kept 12.000', $result['warnings'][0]['detail']);
    }

    public function test_ht_authority_derives_ttc(): void
    {
        $result = $this->resolver()->resolve([
            'sale_price_excl_tax' => '10.000',
        ], 'ht', '19.00');

        $this->assertSame('11.900', $result['sale_price']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_margin_authority_derives_ttc_from_cost_markup(): void
    {
        $result = $this->resolver()->resolve([
            'purchase_price' => '10.000',
            'margin' => '30',
        ], 'margin', '19.00');

        $this->assertSame('15.470', $result['sale_price']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_margin_without_cost_warns_and_falls_back_to_next_candidate(): void
    {
        $result = $this->resolver()->resolve([
            'sale_price_excl_tax' => '10.000',
            'margin' => '30',
        ], 'margin', '19.00');

        $this->assertSame('11.900', $result['sale_price']);
        $this->assertSame('margin_without_cost', $result['warnings'][0]['code']);
    }

    public function test_legacy_sale_price_is_ttc_candidate(): void
    {
        $result = $this->resolver()->resolve([
            'sale_price' => '13.500',
        ], 'ttc', '19.00');

        $this->assertSame('13.500', $result['sale_price']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_no_price_candidates_returns_null_without_warnings(): void
    {
        $result = $this->resolver()->resolve([], 'ttc', '19.00');

        $this->assertNull($result['sale_price']);
        $this->assertSame([], $result['warnings']);
    }

    private function resolver(): ProductPriceResolver
    {
        return new ProductPriceResolver;
    }
}
