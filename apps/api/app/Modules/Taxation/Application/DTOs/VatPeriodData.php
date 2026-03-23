<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Support\Carbon;

/**
 * VAT Period Data DTO
 *
 * Data transfer object for VAT period entity.
 */
readonly class VatPeriodData
{
    /**
     * @param  array<int, array<string, string|int|bool|null>>  $breakdowns
     * @param  array<string, mixed>|null  $specialItems
     * @param  array<string, mixed>|null  $declarationData
     */
    public function __construct(
        public string $id,
        public string $companyId,
        public string $countryCode,
        public VatPeriodType $periodType,
        public string $label,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public VatPeriodStatus $status,
        public ?string $totalOutputVat,
        public ?string $totalInputVat,
        public ?string $netVat,
        public string $creditBroughtForward,
        public string $creditCarriedForward,
        public string $amountPayable,
        public ?array $specialItems,
        public ?array $declarationData,
        public ?Carbon $closedAt,
        public ?string $closedBy,
        public ?Carbon $filedAt,
        public ?string $filedBy,
        public ?string $filingReference,
        public ?string $notes,
        public ?Carbon $createdAt,
        public ?Carbon $updatedAt,
        public array $breakdowns = [],
    ) {}

    /**
     * Create from entity.
     */
    public static function fromEntity(VatPeriod $period): self
    {
        $breakdowns = [];
        if ($period->relationLoaded('breakdowns')) {
            $breakdowns = $period->breakdowns->map(
                static fn ($breakdown): array => [
                    'id' => $breakdown->id,
                    'direction' => $breakdown->direction->value,
                    'tax_rate' => $breakdown->tax_rate,
                    'tax_configuration_id' => $breakdown->tax_configuration_id,
                    'base_amount' => $breakdown->base_amount,
                    'vat_amount' => $breakdown->vat_amount,
                    'document_count' => $breakdown->document_count,
                    'is_recoverable' => $breakdown->is_recoverable,
                ]
            )->toArray();
        }

        return new self(
            id: $period->id,
            companyId: $period->company_id,
            countryCode: $period->country_code,
            periodType: $period->period_type,
            label: $period->label,
            periodStart: $period->period_start,
            periodEnd: $period->period_end,
            status: $period->status,
            totalOutputVat: $period->total_output_vat,
            totalInputVat: $period->total_input_vat,
            netVat: $period->net_vat,
            creditBroughtForward: $period->credit_brought_forward,
            creditCarriedForward: $period->credit_carried_forward,
            amountPayable: $period->amount_payable,
            specialItems: $period->special_items,
            declarationData: $period->declaration_data,
            closedAt: $period->closed_at,
            closedBy: $period->closed_by,
            filedAt: $period->filed_at,
            filedBy: $period->filed_by,
            filingReference: $period->filing_reference,
            notes: $period->notes,
            createdAt: $period->created_at,
            updatedAt: $period->updated_at,
            breakdowns: $breakdowns,
        );
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, string|int|bool|null|array<int, array<string, string|int|bool|null>>|array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'country_code' => $this->countryCode,
            'period_type' => $this->periodType->value,
            'period_type_label' => $this->periodType->label(),
            'label' => $this->label,
            'period_start' => $this->periodStart->toDateString(),
            'period_end' => $this->periodEnd->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_output_vat' => $this->totalOutputVat,
            'total_input_vat' => $this->totalInputVat,
            'net_vat' => $this->netVat,
            'credit_brought_forward' => $this->creditBroughtForward,
            'credit_carried_forward' => $this->creditCarriedForward,
            'amount_payable' => $this->amountPayable,
            'special_items' => $this->specialItems,
            'declaration_data' => $this->declarationData,
            'closed_at' => $this->closedAt?->toIso8601String(),
            'closed_by' => $this->closedBy,
            'filed_at' => $this->filedAt?->toIso8601String(),
            'filed_by' => $this->filedBy,
            'filing_reference' => $this->filingReference,
            'notes' => $this->notes,
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'breakdowns' => $this->breakdowns,
        ];
    }
}
