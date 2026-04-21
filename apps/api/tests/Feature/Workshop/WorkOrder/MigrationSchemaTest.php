<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Validates that Task 5's migrations create the expected tables, columns,
 * and CHECK constraints per Spec §5.1.
 */
final class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_work_orders_table_exists_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('workshop_work_orders'));

        $cols = [
            'id', 'tenant_id', 'company_id', 'location_id',
            'work_order_number', 'status', 'type',
            'customer_partner_id', 'vehicle_id', 'opened_by_user_id',
            'primary_technician_profile_id',
            'mileage_at_intake', 'customer_complaint', 'diagnosis', 'internal_notes',
            'scheduled_start_at', 'scheduled_end_at', 'promised_at',
            'started_at', 'paused_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
            'approval_captured_at', 'approval_method', 'approval_captured_by_user_id', 'approval_reference',
            'currency',
            'estimated_parts_total', 'estimated_labor_total', 'estimated_other_total',
            'estimated_tax_total', 'estimated_grand_total',
            'actual_parts_total', 'actual_labor_total', 'actual_other_total',
            'actual_tax_total', 'actual_grand_total',
            'quote_document_id', 'invoice_document_id',
            'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('workshop_work_orders', $c),
                "workshop_work_orders missing column {$c}"
            );
        }
    }

    public function test_workshop_work_order_lines_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('workshop_work_order_lines'));
        foreach ([
            'id', 'tenant_id', 'work_order_id',
            'line_type', 'display_order',
            'product_id', 'service_id', 'service_bundle_id',
            'display_name', 'sku_or_code', 'description',
            'quantity', 'unit',
            'unit_price', 'tax_rate', 'discount_percent',
            'line_total_excl_tax', 'line_total_tax', 'line_total_incl_tax',
            'labor_hours_estimated', 'labor_hours_actual', 'assigned_technician_profile_id',
            'stock_reservation_id', 'is_customer_supplied',
            'core_deposit_partner_id', 'core_deposit_status', 'core_return_of_line_id',
            'from_bundle_id', 'is_bundle_informational',
            'is_completed', 'completed_at',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('workshop_work_order_lines', $c),
                "workshop_work_order_lines missing column {$c}"
            );
        }
    }

    public function test_workshop_work_order_assignments_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('workshop_work_order_assignments'));
        foreach ([
            'id', 'tenant_id', 'work_order_id', 'technician_profile_id',
            'is_lead', 'assigned_at', 'unassigned_at', 'assigned_by_user_id', 'notes',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('workshop_work_order_assignments', $c),
                "workshop_work_order_assignments missing column {$c}"
            );
        }
    }

    public function test_workshop_work_order_status_transitions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('workshop_work_order_status_transitions'));
        foreach ([
            'id', 'tenant_id', 'work_order_id',
            'from_status', 'to_status', 'reason_code',
            'triggered_by_user_id', 'triggered_at', 'context',
        ] as $c) {
            $this->assertTrue(
                Schema::hasColumn('workshop_work_order_status_transitions', $c),
                "workshop_work_order_status_transitions missing column {$c}"
            );
        }
    }

    public function test_documents_table_has_work_order_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('documents', 'work_order_id'));
    }

    public function test_document_lines_table_has_work_order_line_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('document_lines', 'work_order_line_id'));
    }

    public function test_work_order_line_id_is_not_fk_constrained(): void
    {
        // Intentional design per Spec §5.1: document_lines.work_order_line_id is a
        // bare nullable UUID, NOT FK-constrained. This keeps the Document module
        // decoupled from Workshop at the schema level.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only schema introspection.');
        }

        $fkCount = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema   = kcu.table_schema
            WHERE tc.table_name    = 'document_lines'
              AND tc.constraint_type = 'FOREIGN KEY'
              AND kcu.column_name   = 'work_order_line_id'
        SQL);

        $this->assertSame(0, (int) $fkCount->n, 'document_lines.work_order_line_id must NOT be FK-constrained');
    }

    public function test_documents_work_order_id_is_fk_constrained(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only schema introspection.');
        }

        $fkCount = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS n
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema   = kcu.table_schema
            WHERE tc.table_name    = 'documents'
              AND tc.constraint_type = 'FOREIGN KEY'
              AND kcu.column_name   = 'work_order_id'
        SQL);

        $this->assertSame(1, (int) $fkCount->n, 'documents.work_order_id must be FK-constrained');
    }
}
