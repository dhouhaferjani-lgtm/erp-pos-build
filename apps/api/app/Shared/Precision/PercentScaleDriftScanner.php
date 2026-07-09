<?php

declare(strict_types=1);

namespace App\Shared\Precision;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PercentScaleDriftScanner
{
    /**
     * @var array<string, list<string>>
     */
    public const TARGET_COLUMNS = [
        'services' => ['tax_rate'],
        'workshop_service_bundles' => ['tax_rate'],
        'workshop_work_order_lines' => ['tax_rate'],
        'products' => ['target_margin_override', 'minimum_margin_override'],
    ];

    /**
     * @return list<array{table: string, column: string, count: int}>
     */
    public function scanCurrentConnection(): array
    {
        $findings = [];

        foreach (self::TARGET_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $count = $this->countOverPreciseRows($table, $column);
                if ($count > 0) {
                    $findings[] = [
                        'table' => $table,
                        'column' => $column,
                        'count' => $count,
                    ];
                }
            }
        }

        return $findings;
    }

    private function countOverPreciseRows(string $table, string $column): int
    {
        $quotedTable = $this->quoteIdentifier($table);
        $quotedColumn = $this->quoteIdentifier($column);
        $row = DB::selectOne(
            "select count(*) as aggregate from {$quotedTable} where {$quotedColumn} is not null and {$quotedColumn} <> round({$quotedColumn}, 2)"
        );

        return (int) ($row->aggregate ?? 0);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
