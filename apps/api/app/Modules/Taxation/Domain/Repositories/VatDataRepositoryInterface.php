<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;

interface VatDataRepositoryInterface
{
    /**
     * Aggregate document tax details by tax rate and direction for a company within a date range.
     *
     * Joins document_tax_details with documents to determine direction:
     * - Invoice / CreditNote → OUTPUT
     * - Expense → INPUT
     *
     * Excludes stamp duty entries. Joins tax_configurations to resolve recoverability.
     *
     * @return VatAggregation[]
     */
    public function aggregateByRateAndDirection(string $companyId, string $dateFrom, string $dateTo): array;
}
