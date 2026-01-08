<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use Illuminate\Support\Carbon;

class StampDutyService
{
    /**
     * Calculate stamp duty for a document
     */
    public function calculateStampDuty(
        string $countryCode,
        DocumentType $documentType,
        ?FiscalCategory $fiscalCategory,
        ?Carbon $documentDate = null
    ): ?StampDutyResult {
        $date = $documentDate ?? now();

        // Find active stamp duty rule matching criteria
        $rule = StampDutyRule::where('country_code', $countryCode)
            ->where('document_type', $documentType->value)
            ->where(function ($query) use ($fiscalCategory) {
                $query->whereNull('fiscal_category')
                    ->orWhere('fiscal_category', $fiscalCategory?->value);
            })
            ->where('is_active', true)
            ->where('effective_from', '<=', $date->format('Y-m-d'))
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date->format('Y-m-d'));
            })
            ->orderBy('fiscal_category', 'desc') // Prioritize specific fiscal_category over null
            ->orderBy('effective_from', 'desc')   // Use most recent rule
            ->first();

        if (! $rule) {
            return null;
        }

        return new StampDutyResult(
            amount: number_format((float) $rule->stamp_amount, 3, '.', ''),
            name: 'Stamp Duty',
            countryCode: $rule->country_code,
            ruleId: $rule->id
        );
    }

    /**
     * Check if stamp duty applies to a document
     */
    public function shouldApplyStampDuty(
        string $countryCode,
        DocumentType $documentType,
        ?FiscalCategory $fiscalCategory
    ): bool {
        return $this->calculateStampDuty($countryCode, $documentType, $fiscalCategory) !== null;
    }
}

/**
 * Value object for stamp duty calculation result
 */
readonly class StampDutyResult
{
    public function __construct(
        public string $amount,
        public string $name,
        public string $countryCode,
        public string $ruleId
    ) {}
}
