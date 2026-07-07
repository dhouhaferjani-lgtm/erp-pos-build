<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReconciliationData;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;

final readonly class ExtractionReconciler
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function reconcile(ExtractionResultData $result, ?string $currencyCode = null): ReconciliationData
    {
        $currency = $currencyCode ?? $this->fieldValue($result->header['currency'] ?? null);
        $scale = $this->scaleResolver->getScale($currency);
        $flags = [];

        foreach ($result->lines as $index => $line) {
            $lineNumber = $index + 1;
            $quantity = $this->fieldValue($line->quantity);

            if ($quantity === null || $quantity === '') {
                $flags[] = "line_{$lineNumber}_quantity_missing";

                continue;
            }

            if ($line->unitPrice !== null && $line->lineTotal !== null) {
                $expected = $this->lineTotal($quantity, $line->unitPrice->value, $scale);
                $actual = CurrencyScale::bcformatStrict($line->lineTotal->value, $scale);

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
    private function invoiceFlags(ExtractionResultData $result, int $scale): array
    {
        $flags = [];
        $subtotal = $this->fieldValue($result->header['subtotal'] ?? null);
        $taxTotal = $this->fieldValue($result->header['tax_total'] ?? null);
        $stampDuty = $this->fieldValue($result->header['stamp_duty'] ?? null);
        $grandTotal = $this->fieldValue($result->header['grand_total'] ?? null);

        if ($subtotal !== null) {
            $lineSum = CurrencyScale::bcformatStrict('0', $scale);
            foreach ($result->lines as $line) {
                if ($line->lineTotal === null) {
                    continue;
                }

                /** @var numeric-string $lineSum */
                $lineSum = bcadd($lineSum, CurrencyScale::bcformatStrict($line->lineTotal->value, $scale), $scale);
            }

            if (bccomp($lineSum, CurrencyScale::bcformatStrict($subtotal, $scale), $scale) !== 0) {
                $flags[] = 'subtotal_mismatch';
            }
        }

        if ($subtotal !== null && $taxTotal !== null && $grandTotal !== null) {
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
        /** @var numeric-string $total */
        $total = bcmul(
            CurrencyScale::bcformatStrict($quantity, 4),
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
}
