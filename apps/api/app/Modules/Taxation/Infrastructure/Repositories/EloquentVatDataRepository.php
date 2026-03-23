<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentVatDataRepository implements VatDataRepositoryInterface
{
    public function aggregateByRateAndDirection(string $companyId, string $dateFrom, string $dateTo): array
    {
        $rows = DB::table('document_tax_details as dtd')
            ->join('documents as d', 'dtd.document_id', '=', 'd.id')
            ->leftJoin('tax_configurations as tc', function ($join) use ($companyId): void {
                $join->on('dtd.tax_rate', '=', 'tc.percentage_rate')
                    ->on('tc.country_code', '=', DB::raw(
                        '(SELECT country_code FROM companies WHERE id = '.DB::getPdo()->quote($companyId).')'
                    ))
                    ->where('tc.is_active', true)
                    ->where('tc.is_stamp_duty', false);
            })
            ->where('d.company_id', $companyId)
            ->whereBetween('d.document_date', [$dateFrom, $dateTo])
            ->whereIn('d.type', ['invoice', 'credit_note', 'expense'])
            ->where('dtd.is_stamp_duty', false)
            ->whereNull('d.deleted_at')
            ->selectRaw("
                CASE
                    WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT'
                    WHEN d.type = 'expense' THEN 'INPUT'
                END as direction,
                dtd.tax_rate,
                SUM(dtd.tax_base) as base_amount,
                SUM(dtd.tax_amount) as vat_amount,
                COUNT(DISTINCT d.id) as document_count,
                COALESCE(tc.is_recoverable, true) as is_recoverable,
                tc.id as tax_configuration_id
            ")
            ->groupByRaw("
                CASE
                    WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT'
                    WHEN d.type = 'expense' THEN 'INPUT'
                END,
                dtd.tax_rate,
                tc.is_recoverable,
                tc.id
            ")
            ->orderBy('direction')
            ->orderBy('dtd.tax_rate')
            ->get();

        return $rows->map(fn (object $row): VatAggregation => new VatAggregation(
            direction: (string) $row->direction,
            taxRate: number_format((float) $row->tax_rate, 2, '.', ''),
            baseAmount: number_format((float) $row->base_amount, 3, '.', ''),
            vatAmount: number_format((float) $row->vat_amount, 3, '.', ''),
            documentCount: (int) $row->document_count,
            isRecoverable: (bool) $row->is_recoverable,
            taxConfigurationId: $row->tax_configuration_id,
        ))->all();
    }
}
