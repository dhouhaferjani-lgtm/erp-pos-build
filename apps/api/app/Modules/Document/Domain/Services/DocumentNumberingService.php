<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DocumentNumberingService
{
    /**
     * Generate the next document number for a company and type
     *
     * Format: PREFIX-YYYY-NNNN (e.g., INV-2025-0001)
     */
    public function generateNumber(string $tenantId, string $companyId, DocumentType $type): string
    {
        return $this->generateForKey($tenantId, $companyId, $type->value, $type->getPrefix());
    }

    /**
     * Generate the next sequence number for a raw sequence key.
     *
     * Format: PREFIX-YYYY-NNNN (e.g., GRN-2026-0001)
     */
    public function generateForKey(string $tenantId, string $companyId, string $sequenceType, string $prefix): string
    {
        try {
            return $this->generateForKeyOnce($tenantId, $companyId, $sequenceType, $prefix);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        return $this->generateForKeyOnce($tenantId, $companyId, $sequenceType, $prefix);
    }

    private function generateForKeyOnce(string $tenantId, string $companyId, string $sequenceType, string $prefix): string
    {
        return DB::transaction(function () use ($tenantId, $companyId, $sequenceType, $prefix): string {
            $year = (int) date('Y');

            $sequence = DocumentSequence::where('company_id', $companyId)
                ->where('type', $sequenceType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = DocumentSequence::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'type' => $sequenceType,
                    'year' => $year,
                    'last_number' => 0,
                ]);
            }

            $nextNumber = $sequence->last_number + 1;
            $sequence->update(['last_number' => $nextNumber]);

            return sprintf('%s-%d-%04d', $prefix, $year, $nextNumber);
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true);
    }

    /**
     * Get the current sequence number without incrementing
     */
    public function getCurrentNumber(string $companyId, DocumentType $type): int
    {
        $year = (int) date('Y');

        $sequence = DocumentSequence::where('company_id', $companyId)
            ->where('type', $type->value)
            ->where('year', $year)
            ->first();

        return $sequence !== null ? $sequence->last_number : 0;
    }
}
