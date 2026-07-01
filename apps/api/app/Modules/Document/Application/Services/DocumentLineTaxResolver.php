<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type DocumentLinePayload array{description: string, quantity: string, unit_price: string, product_id?: string, service_id?: string, tax_rate?: string|null, tax_configuration_id?: string|null, discount_percent?: string|null, discount_amount?: string|null, notes?: string|null}
 */
final class DocumentLineTaxResolver
{
    /**
     * @param  array<int, DocumentLinePayload>  $lines
     * @param  Collection<array-key, Product>  $productsById
     * @return array<int, DocumentLinePayload>
     */
    public function resolve(array $lines, Company $company, Collection $productsById): array
    {
        return array_map(
            function (array $line) use ($company, $productsById): array {
                /** @var Product|null $product */
                $product = isset($line['product_id']) ? $productsById->get($line['product_id']) : null;
                $line['tax_rate'] = $this->resolveTaxRate($line, $company, $product);

                return $line;
            },
            $lines,
        );
    }

    /**
     * @param  DocumentLinePayload  $line
     * @return numeric-string
     */
    private function resolveTaxRate(array $line, Company $company, ?Product $product): string
    {
        if ($this->hasNumericValue($line['tax_rate'] ?? null)) {
            return $this->formatRate((string) $line['tax_rate']);
        }

        if ($this->hasStringValue($line['tax_configuration_id'] ?? null)) {
            $rate = $this->rateFromConfigurationId((string) $line['tax_configuration_id'], $company);
            if ($rate !== null) {
                return $rate;
            }
        }

        if ($product !== null && $this->hasStringValue($product->default_tax_configuration_id)) {
            $rate = $this->rateFromConfigurationId((string) $product->default_tax_configuration_id, $company);
            if ($rate !== null) {
                return $rate;
            }
        }

        if ($product !== null && $this->hasNumericValue($product->tax_rate)) {
            return $this->formatRate((string) $product->tax_rate);
        }

        if ($this->hasStringValue($company->default_tax_configuration_id)) {
            $rate = $this->rateFromConfigurationId((string) $company->default_tax_configuration_id, $company);
            if ($rate !== null) {
                return $rate;
            }
        }

        if ($this->hasNumericValue($company->default_tax_rate)) {
            return $this->formatRate((string) $company->default_tax_rate);
        }

        return '0.00';
    }

    /**
     * @return numeric-string|null
     */
    private function rateFromConfigurationId(string $configurationId, Company $company): ?string
    {
        $configuration = TaxConfiguration::query()
            ->where('country_code', $company->country_code)
            ->where('applies_to', 'LINE_ITEMS')
            ->find($configurationId);

        if (! $configuration instanceof TaxConfiguration || ! $configuration->isPercentage()) {
            return null;
        }

        return $this->formatRate($configuration->percentage_rate ?? '0');
    }

    private function hasStringValue(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function hasNumericValue(string|int|float|null $value): bool
    {
        return $value !== null && (string) $value !== '';
    }

    /**
     * @return numeric-string
     */
    private function formatRate(string $rate): string
    {
        return CurrencyScale::bcformatStrict($rate, 2);
    }
}
