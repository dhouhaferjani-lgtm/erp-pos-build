<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;

/**
 * VAT Summary Data DTO
 *
 * Application-level data transfer object combining VAT summary,
 * declaration data, credits, and special items.
 */
readonly class VatSummaryData
{
    /**
     * @param  array{total_base: string, total_vat: string, breakdowns: array<int, array<string, string|int|bool|null>>}  $outputVat
     * @param  array{total_base: string, total_vat: string, breakdowns: array<int, array<string, string|int|bool|null>>}  $inputVat
     * @param  array<string, string|int|float>  $specialItems
     * @param  array<string, string|int|float|array<string, string|int|float>>  $declaration
     */
    public function __construct(
        public array $outputVat,
        public array $inputVat,
        public string $netVat,
        public string $creditBroughtForward,
        public string $creditCarriedForward,
        public string $amountPayable,
        public array $specialItems,
        public array $declaration,
    ) {}

    /**
     * Create from domain objects.
     *
     * @param  array<string, string|int|float>  $specialItems
     */
    public static function fromDomainObjects(
        VatSummary $vatSummary,
        VatDeclarationData $declaration,
        array $specialItems,
        string $creditBroughtForward,
        string $creditCarriedForward,
        string $netVat,
        string $amountPayable,
    ): self {
        $outputBase = '0.000';
        $outputBreakdowns = [];
        foreach ($vatSummary->outputBreakdowns as $breakdown) {
            $outputBase = bcadd($outputBase, $breakdown->baseAmount, 3);
            $outputBreakdowns[] = $breakdown->toArray();
        }

        $inputBase = '0.000';
        $inputBreakdowns = [];
        foreach ($vatSummary->inputBreakdowns as $breakdown) {
            $inputBase = bcadd($inputBase, $breakdown->baseAmount, 3);
            $inputBreakdowns[] = $breakdown->toArray();
        }

        return new self(
            outputVat: [
                'total_base' => $outputBase,
                'total_vat' => $vatSummary->totalOutputVat,
                'breakdowns' => $outputBreakdowns,
            ],
            inputVat: [
                'total_base' => $inputBase,
                'total_vat' => $vatSummary->totalInputVat,
                'breakdowns' => $inputBreakdowns,
            ],
            netVat: $netVat,
            creditBroughtForward: $creditBroughtForward,
            creditCarriedForward: $creditCarriedForward,
            amountPayable: $amountPayable,
            specialItems: $specialItems,
            declaration: $declaration->toArray(),
        );
    }

    /**
     * Get the total output VAT amount.
     */
    public function getTotalOutputVat(): string
    {
        return $this->outputVat['total_vat'];
    }

    /**
     * Get the total input VAT amount.
     */
    public function getTotalInputVat(): string
    {
        return $this->inputVat['total_vat'];
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, string|array<string, string|int|float|array<int, array<string, string|int|bool|null>>|array<string, string|int|float>>>
     */
    public function toArray(): array
    {
        return [
            'output_vat' => $this->outputVat,
            'input_vat' => $this->inputVat,
            'net_vat' => $this->netVat,
            'credit_brought_forward' => $this->creditBroughtForward,
            'credit_carried_forward' => $this->creditCarriedForward,
            'amount_payable' => $this->amountPayable,
            'special_items' => $this->specialItems,
            'declaration' => $this->declaration,
        ];
    }
}
