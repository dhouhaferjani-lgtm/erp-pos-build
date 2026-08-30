<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Database\ConnectionInterface;

final class TenantOnlyUniqueIndexScanner
{
    /**
     * @param  list<string>  $catalogueTables
     * @return list<TenantOnlyUniqueIndex>
     */
    public function scan(ConnectionInterface $connection, array $catalogueTables): array
    {
        return array_values(array_filter(
            $this->scanAll($connection),
            static fn (TenantOnlyUniqueIndex $index): bool => in_array($index->tableName, $catalogueTables, true),
        ));
    }

    /** @return list<TenantOnlyUniqueIndex> */
    public function scanAll(ConnectionInterface $connection): array
    {
        /** @var list<\stdClass> $rows */
        $rows = $connection->select(<<<'SQL'
            SELECT
                table_class.relname AS table_name,
                index_class.relname AS index_name,
                indexes.indisprimary AS is_primary,
                string_agg(
                    pg_get_indexdef(indexes.indexrelid, positions.position, true),
                    chr(31)
                    ORDER BY positions.position
                ) AS indexed_columns,
                pg_get_indexdef(indexes.indexrelid) AS definition
            FROM pg_index AS indexes
            INNER JOIN pg_class AS table_class ON table_class.oid = indexes.indrelid
            INNER JOIN pg_class AS index_class ON index_class.oid = indexes.indexrelid
            INNER JOIN pg_namespace AS table_namespace ON table_namespace.oid = table_class.relnamespace
            CROSS JOIN LATERAL generate_series(1, indexes.indnkeyatts) AS positions(position)
            WHERE indexes.indisunique = true
              AND table_namespace.nspname = current_schema()
            GROUP BY table_class.relname, index_class.relname, indexes.indexrelid, indexes.indisprimary
            ORDER BY table_class.relname, index_class.relname
            SQL);

        $indexes = [];
        foreach ($rows as $row) {
            $tableName = (string) $row->table_name;
            $columns = explode(chr(31), (string) $row->indexed_columns);

            if ((bool) $row->is_primary) {
                continue;
            }
            if (count($columns) === 1 && in_array($columns[0], ['id', 'uuid'], true)) {
                continue;
            }
            if (in_array('company_id', $columns, true)) {
                continue;
            }

            $indexes[] = new TenantOnlyUniqueIndex(
                tableName: $tableName,
                indexName: (string) $row->index_name,
                columns: $columns,
                definition: (string) $row->definition,
            );
        }

        usort(
            $indexes,
            static fn (TenantOnlyUniqueIndex $left, TenantOnlyUniqueIndex $right): int => $left->key() <=> $right->key(),
        );

        return $indexes;
    }
}
