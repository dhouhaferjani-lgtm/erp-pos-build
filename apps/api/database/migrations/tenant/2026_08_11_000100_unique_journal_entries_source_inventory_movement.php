<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\InventoryGlSourceTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'uniq_je_source_inventory_movement';

    public function up(): void
    {
        $types = $this->quotedTypes();
        $duplicates = DB::select(
            "SELECT source_type, source_id, COUNT(*) AS duplicate_count
             FROM journal_entries
             WHERE source_type IN ({$types})
             GROUP BY source_type, source_id
             HAVING COUNT(*) > 1"
        );

        if ($duplicates !== []) {
            $first = $duplicates[0];
            throw new RuntimeException(sprintf(
                'Cannot create %s: duplicate inventory GL pair (%s, %s).',
                self::INDEX,
                (string) $first->source_type,
                (string) $first->source_id,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            ." ON journal_entries (source_type, source_id) WHERE source_type IN ({$types})"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function quotedTypes(): string
    {
        return implode(', ', array_map(
            static fn (string $type): string => "'".str_replace("'", "''", $type)."'",
            InventoryGlSourceTypes::ALL,
        ));
    }
};
