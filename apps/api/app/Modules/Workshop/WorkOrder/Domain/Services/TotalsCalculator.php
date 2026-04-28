<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Services;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\WorkOrderTotals;
use App\Shared\Domain\CurrencyScale;

/**
 * Pure domain service that computes `WorkOrderTotals` from a list of lines.
 *
 * All arithmetic is performed with bcmath via `CurrencyScale::bcformat`; no
 * float intermediates. Informational bundle lines (`is_bundle_informational`
 * = true) are always skipped — the matching `BundleHeader` line carries the
 * authoritative price for fixed bundles.
 *
 * Line-type → bucket mapping:
 *   Part, CoreCharge, CoreReturn, BundleHeader → parts_total
 *   Labor                                       → labor_total
 *   Sublet, EnvironmentalFee, MiscFee           → other_total
 */
final class TotalsCalculator
{
    /**
     * @param  list<TotalsCalculatorLineInput>  $lines
     */
    public function compute(array $lines, string $currencyCode): WorkOrderTotals
    {
        $scale = CurrencyScale::for($currencyCode);

        $parts = '0';
        $labor = '0';
        $other = '0';
        $tax = '0';
        $grand = '0';

        foreach ($lines as $line) {
            if ($line->is_bundle_informational) {
                continue;
            }

            // Normalize raw line-money strings to numeric-string via bcformat
            // before feeding them to bcadd (which is typed numeric-string).
            $excl = CurrencyScale::bcformat($line->line_total_excl_tax, $scale);
            $lineTax = CurrencyScale::bcformat($line->line_total_tax, $scale);
            $incl = CurrencyScale::bcformat($line->line_total_incl_tax, $scale);

            $bucket = $this->bucketFor($line->line_type);
            match ($bucket) {
                'parts' => $parts = bcadd($parts, $excl, $scale),
                'labor' => $labor = bcadd($labor, $excl, $scale),
                'other' => $other = bcadd($other, $excl, $scale),
            };
            $tax = bcadd($tax, $lineTax, $scale);
            $grand = bcadd($grand, $incl, $scale);
        }

        return new WorkOrderTotals(
            parts_total: CurrencyScale::bcformat($parts, $scale),
            labor_total: CurrencyScale::bcformat($labor, $scale),
            other_total: CurrencyScale::bcformat($other, $scale),
            tax_total: CurrencyScale::bcformat($tax, $scale),
            grand_total: CurrencyScale::bcformat($grand, $scale),
        );
    }

    /**
     * Exhaustive mapping from line type → totals bucket. PHPStan level 8
     * flags any missing case at CI time.
     *
     * @return 'parts'|'labor'|'other'
     */
    private function bucketFor(WorkOrderLineType $type): string
    {
        return match ($type) {
            WorkOrderLineType::Part,
            WorkOrderLineType::CoreCharge,
            WorkOrderLineType::CoreReturn,
            WorkOrderLineType::BundleHeader => 'parts',
            WorkOrderLineType::Labor => 'labor',
            WorkOrderLineType::Sublet,
            WorkOrderLineType::EnvironmentalFee,
            WorkOrderLineType::MiscFee => 'other',
        };
    }
}
