<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\ImportJob;

final class ImportUnitErrorSummaryService
{
    /**
     * @return array{unknown_units: list<array{text: string, count: int, accepted: list<string>}>}
     */
    public function summarize(ImportJob $job): array
    {
        /** @var array<string, array{text: string, count: int, accepted: list<string>}> $unknown */
        $unknown = [];

        foreach ($job->rows()
            ->reorder()
            ->where('import_error_code', ImportErrorCode::UnitUnknown->value)
            ->select('import_error_detail')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('import_error_detail')
            ->get() as $row) {
            $detail = $row->import_error_detail ?? [];
            $supplied = $detail['supplied'] ?? null;
            if (! is_string($supplied) || trim($supplied) === '') {
                continue;
            }

            $text = trim($supplied);
            $accepted = $detail['accepted'] ?? [];
            $accepted = is_array($accepted)
                ? array_values(array_filter($accepted, is_string(...)))
                : [];

            if (! isset($unknown[$text])) {
                $unknown[$text] = [
                    'text' => $text,
                    'count' => 0,
                    'accepted' => $accepted,
                ];
            }
            $unknown[$text]['count'] += (int) $row->getAttribute('aggregate');
        }

        ksort($unknown, SORT_STRING);

        return ['unknown_units' => array_values($unknown)];
    }
}
