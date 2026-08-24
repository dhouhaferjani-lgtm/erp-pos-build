<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * N-6 — mark WHICH allocations were booked as a customer advance (Cr 419), and
 * whether that advance has since been cleared to the receivable.
 *
 * Without this marker the posting path cannot know how much 419 belongs to the
 * invoice it is sealing. Deriving it from the journal would mean matching a
 * `source_type = 'advance'` entry back through the payment to the allocation,
 * and `journal_entries(source_type, source_id)` carries no uniqueness
 * (`reference_journal_entries_no_global_source_uniqueness`), so that derivation
 * is not sound. The fact belongs on the allocation row that caused it.
 *
 * `advance_cleared_at` is what stops a DOUBLE CLEAR. Order-originated
 * prepayments are already cleared at CONVERSION by
 * `SalesOrderToInvoiceConverter::transferPrepayments()`, which re-points the
 * allocation row from the order to the new invoice; that invoice is then posted
 * and, without this column, the posting path would clear the same 419 a second
 * time — draining another partner advance or refusing on the ceiling.
 *
 * MIGRATION-BEARING: additive only. Three columns, all nullable or defaulted,
 * no constraint an existing row could violate, so no fleet-abort risk.
 * Per-tenant census (expect 0 before, and it stays a pure backfill of defaults):
 *
 *   SELECT COUNT(*) FROM information_schema.columns
 *    WHERE table_name = 'payment_allocations'
 *      AND column_name IN ('booked_as_advance','advance_journal_entry_id','advance_cleared_at');
 *
 * PRE-EXISTING ROWS ARE LEFT AT `booked_as_advance = false` deliberately: they
 * were booked Cr 411 by the pre-N-6 code, and the marker must say what the
 * LEDGER did, not what it should have done. `documents:repair-paid-never-posted`
 * is what re-books the misclassified ones and sets the marker to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_allocations')) {
            return;
        }

        Schema::table('payment_allocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_allocations', 'booked_as_advance')) {
                $table->boolean('booked_as_advance')
                    ->default(false)
                    ->after('tolerance_writeoff');
            }

            if (! Schema::hasColumn('payment_allocations', 'advance_journal_entry_id')) {
                // No FK: journal entries are append-only and hash-chained (they
                // are never deleted), and every treasury table built since
                // mid-2026 that references them does so without one. An index is
                // what the clearing lookup actually needs.
                $table->uuid('advance_journal_entry_id')
                    ->nullable()
                    ->after('booked_as_advance');
            }

            if (! Schema::hasColumn('payment_allocations', 'advance_cleared_at')) {
                $table->timestamp('advance_cleared_at')
                    ->nullable()
                    ->after('advance_journal_entry_id');
            }
        });

        if (! $this->hasIndex('payment_allocations_open_advance_index')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                // The posting path's one question: "which OPEN advances belong
                // to this document?"
                $table->index(
                    ['document_id', 'booked_as_advance', 'advance_cleared_at'],
                    'payment_allocations_open_advance_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_allocations')) {
            return;
        }

        Schema::table('payment_allocations', function (Blueprint $table): void {
            if ($this->hasIndex('payment_allocations_open_advance_index')) {
                $table->dropIndex('payment_allocations_open_advance_index');
            }

            foreach (['advance_cleared_at', 'advance_journal_entry_id', 'booked_as_advance'] as $column) {
                if (Schema::hasColumn('payment_allocations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return Schema::getConnection()
            ->getSchemaBuilder()
            ->getIndexes('payment_allocations') !== []
            && collect(Schema::getConnection()->getSchemaBuilder()->getIndexes('payment_allocations'))
                ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
