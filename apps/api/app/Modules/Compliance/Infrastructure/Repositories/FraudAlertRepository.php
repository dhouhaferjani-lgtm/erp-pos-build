<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Repositories;

use App\Modules\Compliance\Domain\FraudAlert;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class FraudAlertRepository
{
    /**
     * Idempotent creation: if an alert already exists for the given z_report + type, return it.
     * Relies on the partial unique index on (metadata->>'z_report_id', alert_type) from PR-1.
     *
     * Uses driver-aware raw SQL for JSON extraction so the query works on both
     * PostgreSQL (production) and SQLite (tests).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreateByZReport(
        string $zReportId,
        string $alertType,
        array $attributes,
    ): FraudAlert {
        $existing = FraudAlert::query()
            ->where('alert_type', $alertType)
            ->whereRaw($this->jsonExtractSql(), [$zReportId])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // First-create path. Catch unique-violation from concurrent replay, re-read.
        try {
            return FraudAlert::create(array_merge($attributes, ['alert_type' => $alertType]));
        } catch (QueryException $e) {
            // Another process won the race — re-read.
            $existing = FraudAlert::query()
                ->where('alert_type', $alertType)
                ->whereRaw($this->jsonExtractSql(), [$zReportId])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Return a driver-portable SQL fragment that extracts metadata->>'z_report_id'
     * as text and compares it to a bound parameter.
     */
    private function jsonExtractSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "metadata->>'z_report_id' = ?",
            default => "json_extract(metadata, '$.z_report_id') = ?",
        };
    }
}
