<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DesignationSnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_lines_has_designation_default_snapshot_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('document_lines', 'designation_default_snapshot'),
            'Expected document_lines.designation_default_snapshot column to exist.'
        );
    }

    public function test_designation_default_snapshot_is_nullable(): void
    {
        $columns = Schema::getColumns('document_lines');
        $match = array_values(array_filter(
            $columns,
            static fn (array $col): bool => ($col['name'] ?? null) === 'designation_default_snapshot'
        ));

        $this->assertNotEmpty($match, 'Expected designation_default_snapshot metadata from Schema::getColumns.');
        $this->assertTrue(
            (bool) ($match[0]['nullable'] ?? false),
            'Expected designation_default_snapshot to be nullable.'
        );
    }
}
