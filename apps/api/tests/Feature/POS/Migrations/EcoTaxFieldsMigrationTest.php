<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema tests for Task 9: eco-tax forward-compatibility columns.
 *
 * Asserts that eco_tax_amount, eco_tax_rate, eco_tax_category exist on both
 * pos_receipt_lines and document_lines, all nullable with no defaults.
 *
 * Phase 1 ships columns + fillable only — no writer integration.
 */
final class EcoTaxFieldsMigrationTest extends TestCase
{
    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }
    }

    // -------------------------------------------------------------------------
    // pos_receipt_lines
    // -------------------------------------------------------------------------

    public function test_pos_receipt_lines_has_eco_tax_amount_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type, numeric_precision, numeric_scale '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_lines' AND column_name = 'eco_tax_amount'"
        );

        $this->assertNotNull($row, 'eco_tax_amount column missing from pos_receipt_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_amount must be nullable');
        $this->assertSame('numeric', $row->data_type);
    }

    public function test_pos_receipt_lines_has_eco_tax_rate_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_lines' AND column_name = 'eco_tax_rate'"
        );

        $this->assertNotNull($row, 'eco_tax_rate column missing from pos_receipt_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_rate must be nullable');
        $this->assertSame('numeric', $row->data_type);
    }

    public function test_pos_receipt_lines_has_eco_tax_category_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type, character_maximum_length '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_lines' AND column_name = 'eco_tax_category'"
        );

        $this->assertNotNull($row, 'eco_tax_category column missing from pos_receipt_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_category must be nullable');
        $this->assertSame('character varying', $row->data_type);
        $this->assertSame(64, (int) $row->character_maximum_length);
    }

    // -------------------------------------------------------------------------
    // document_lines
    // -------------------------------------------------------------------------

    public function test_document_lines_has_eco_tax_amount_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'document_lines' AND column_name = 'eco_tax_amount'"
        );

        $this->assertNotNull($row, 'eco_tax_amount column missing from document_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_amount must be nullable');
        $this->assertSame('numeric', $row->data_type);
    }

    public function test_document_lines_has_eco_tax_rate_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'document_lines' AND column_name = 'eco_tax_rate'"
        );

        $this->assertNotNull($row, 'eco_tax_rate column missing from document_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_rate must be nullable');
        $this->assertSame('numeric', $row->data_type);
    }

    public function test_document_lines_has_eco_tax_category_column(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT is_nullable, data_type, character_maximum_length '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'document_lines' AND column_name = 'eco_tax_category'"
        );

        $this->assertNotNull($row, 'eco_tax_category column missing from document_lines');
        $this->assertSame('YES', $row->is_nullable, 'eco_tax_category must be nullable');
        $this->assertSame('character varying', $row->data_type);
        $this->assertSame(64, (int) $row->character_maximum_length);
    }

    // -------------------------------------------------------------------------
    // Phase 1: eco-tax columns remain null on new rows (no writer integration)
    // -------------------------------------------------------------------------

    public function test_pos_receipt_lines_eco_tax_columns_default_null(): void
    {
        $this->skipUnlessPostgres();

        // All three eco-tax columns must have no default value (phase 1 = nullable, no default)
        $columns = DB::select(
            'SELECT column_name, column_default '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'pos_receipt_lines' ".
            "AND column_name IN ('eco_tax_amount','eco_tax_rate','eco_tax_category')"
        );

        $this->assertCount(3, $columns, 'Expected exactly 3 eco-tax columns on pos_receipt_lines');

        foreach ($columns as $col) {
            $this->assertNull($col->column_default,
                "Column pos_receipt_lines.{$col->column_name} must have no default (phase 1)"
            );
        }
    }

    public function test_document_lines_eco_tax_columns_default_null(): void
    {
        $this->skipUnlessPostgres();

        $columns = DB::select(
            'SELECT column_name, column_default '.
            'FROM information_schema.columns '.
            "WHERE table_name = 'document_lines' ".
            "AND column_name IN ('eco_tax_amount','eco_tax_rate','eco_tax_category')"
        );

        $this->assertCount(3, $columns, 'Expected exactly 3 eco-tax columns on document_lines');

        foreach ($columns as $col) {
            $this->assertNull($col->column_default,
                "Column document_lines.{$col->column_name} must have no default (phase 1)"
            );
        }
    }
}
