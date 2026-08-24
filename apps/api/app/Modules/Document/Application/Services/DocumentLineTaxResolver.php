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
        // Campaign defect N-1 (fiscal gate r2 finding 4): THE CONFIGURATION THE
        // LINE NAMES OUTRANKS ANY RATE THE CLIENT ECHOED ALONGSIDE IT.
        //
        // This branch used to sit BELOW the explicit-rate branch, so a payload
        // carrying both `tax_rate: '19.00'` and `tax_configuration_id: TVA_7`
        // resolved to 19 % — the client's stale copy winning over the band the
        // operator actually picked. That IS N-1, arriving by a second route: the
        // shipped web bundle no longer sends both, but a mobile/integration
        // caller can, and a browser still running a pre-deploy cached bundle
        // does until it refreshes.
        //
        // A configuration id is a STATEMENT ABOUT WHICH BAND THIS LINE IS ON;
        // a rate is a number that may be a stale copy of one. When the caller
        // supplies both, the server reads the band off the configuration itself
        // rather than trusting the copy. An id that resolves to nothing (not a
        // LINE_ITEMS percentage row, wrong country, deleted) falls through to
        // the rate exactly as before, so no caller loses a working path.
        //
        // Ordering below, highest first: line configuration > explicit line
        // rate > product configuration > product rate > company configuration >
        // company rate > '0.00'.
        if ($this->hasStringValue($line['tax_configuration_id'] ?? null)) {
            $rate = $this->rateFromConfigurationId((string) $line['tax_configuration_id'], $company);
            if ($rate !== null) {
                return $rate;
            }
        }

        if ($this->hasNumericValue($line['tax_rate'] ?? null)) {
            return $this->formatRate((string) $line['tax_rate']);
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
