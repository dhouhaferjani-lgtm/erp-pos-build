<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReconciliationData;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;

final readonly class ExtractionReconciler
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function reconcile(ExtractionResultData $result, string $currencyCode): ReconciliationData
    {
        $currency = trim($currencyCode);
        $scale = $this->scaleResolver->getScale($currency);
        $flags = $this->currencyFlags($result, $currency);

        foreach ($result->lines as $index => $line) {
            $lineNumber = $index + 1;
            $quantityValue = $this->fieldValue($line->quantity);

            if ($quantityValue === null || $quantityValue === '') {
                $flags[] = "line_{$lineNumber}_quantity_missing";

                continue;
            }

            $quantity = $this->normalizeNumber($quantityValue, "lines.{$index}.quantity", 'quantity', $flags);
            if ($quantity === null) {
                continue;
            }

            if ($line->unitPrice !== null && $line->lineTotal !== null) {
                $unitPrice = $this->normalizeNumber($line->unitPrice->value, "lines.{$index}.unit_price", 'money', $flags);
                $lineTotal = $this->normalizeNumber($line->lineTotal->value, "lines.{$index}.line_total", 'money', $flags);
                if ($unitPrice === null || $lineTotal === null) {
                    continue;
                }

                $expected = $this->lineTotal($quantity, $unitPrice, $scale);
                $actual = CurrencyScale::bcformatStrict($lineTotal, $scale);

                if (bccomp($expected, $actual, $scale) !== 0) {
                    $flags[] = "line_{$lineNumber}_total_mismatch";
                }
            }
        }

        if ($result->docKind === 'supplier_invoice') {
            $flags = array_merge($flags, $this->invoiceFlags($result, $scale));
        }

        /** @var list<string> $deduped */
        $deduped = array_values(array_unique($flags));

        return new ReconciliationData(
            consistent: $deduped === [],
            flags: $deduped,
        );
    }

    /**
     * @return list<string>
     */
    private function currencyFlags(ExtractionResultData $result, string $currency): array
    {
        $extracted = $this->fieldValue($result->header['currency'] ?? null);
        if ($extracted === null || $extracted === '') {
            return [];
        }

        if (mb_strtoupper($extracted) === mb_strtoupper($currency)) {
            return [];
        }

        return ['currency_mismatch'];
    }

    /**
     * @return list<string>
     */
    private function invoiceFlags(ExtractionResultData $result, int $scale): array
    {
        $flags = [];
        $subtotal = $this->fieldValue($result->header['subtotal'] ?? null);
        $taxTotal = $this->fieldValue($result->header['tax_total'] ?? null);
        $stampDuty = $this->fieldValue($result->header['stamp_duty'] ?? null);
        $grandTotal = $this->fieldValue($result->header['grand_total'] ?? null);

        if ($subtotal !== null) {
            $normalizedSubtotal = $this->normalizeNumber($subtotal, 'header.subtotal', 'money', $flags);
            if ($normalizedSubtotal === null) {
                $subtotal = null;
            } else {
                $subtotal = $normalizedSubtotal;
            }

            $lineSum = CurrencyScale::bcformatStrict('0', $scale);
            foreach ($result->lines as $index => $line) {
                if ($line->lineTotal === null) {
                    continue;
                }

                $lineTotal = $this->normalizeNumber($line->lineTotal->value, "lines.{$index}.line_total", 'money', $flags);
                if ($lineTotal === null) {
                    continue;
                }

                /** @var numeric-string $lineSum */
                $lineSum = bcadd($lineSum, CurrencyScale::bcformatStrict($lineTotal, $scale), $scale);
            }

            if ($subtotal !== null && bccomp($lineSum, CurrencyScale::bcformatStrict($subtotal, $scale), $scale) !== 0) {
                $flags[] = 'subtotal_mismatch';
            }
        }

        if ($subtotal !== null && $taxTotal !== null && $grandTotal !== null) {
            $taxTotal = $this->normalizeNumber($taxTotal, 'header.tax_total', 'money', $flags);
            $grandTotal = $this->normalizeNumber($grandTotal, 'header.grand_total', 'money', $flags);
            $stampDuty = $stampDuty === null ? null : $this->normalizeNumber($stampDuty, 'header.stamp_duty', 'money', $flags);
            if ($taxTotal === null || $grandTotal === null) {
                return $flags;
            }

            /** @var numeric-string $expected */
            $expected = bcadd(
                CurrencyScale::bcformatStrict($subtotal, $scale),
                CurrencyScale::bcformatStrict($taxTotal, $scale),
                $scale,
            );

            if ($stampDuty !== null) {
                /** @var numeric-string $expected */
                $expected = bcadd($expected, CurrencyScale::bcformatStrict($stampDuty, $scale), $scale);
            }

            if (bccomp($expected, CurrencyScale::bcformatStrict($grandTotal, $scale), $scale) !== 0) {
                $flags[] = 'grand_total_mismatch';
            }
        }

        return $flags;
    }

    /**
     * @return numeric-string
     */
    private function lineTotal(string $quantity, string $unitPrice, int $scale): string
    {
        $formattedQuantity = QuantityScale::round($quantity, 4, QuantityScale::HALF_UP);

        /** @var numeric-string $total */
        $total = bcmul(
            $formattedQuantity,
            CurrencyScale::bcformatStrict($unitPrice, $scale),
            $scale + 1,
        );

        return CurrencyScale::bcformatStrict($total, $scale);
    }

    private function fieldValue(?ExtractedFieldData $field): ?string
    {
        if ($field === null) {
            return null;
        }

        return trim($field->value);
    }

    /**
     * @param  list<string>  $flags
     * @return numeric-string|null
     */
    private function normalizeNumber(string $value, string $path, string $kind, array &$flags): ?string
    {
        $normalized = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($value));
        $normalized = str_replace(',', '.', $normalized ?? '');
        $pattern = $kind === 'quantity'
            ? '/^\d+(?:\.\d{1,4})?$/'
            : '/^-?\d+(?:\.\d{1,3})?$/';

        if (preg_match($pattern, $normalized) !== 1) {
            $flags[] = "field_unparseable:{$path}";

            return null;
        }

        /** @var numeric-string $normalized */
        return $normalized;
    }
}
