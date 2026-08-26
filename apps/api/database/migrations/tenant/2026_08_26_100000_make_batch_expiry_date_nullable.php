<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign W4-1 — a lot with NO KNOWN EXPIRY must be able to say so.
 *
 * `product_batches.expiry_date` was NOT NULL, so every code path that had to
 * mint a lot for stock whose expiry nobody supplied — above all the opening
 * balance, where on day one ALL stock lives — invented one
 * (`asOfDate + 365`). FEFO then ranked the whole opening catalogue on that
 * fiction, and because the invented date is the EARLIEST on the product, the
 * transfer / delivery-note FEFO guards actively COMPELLED shipping the
 * fabricated lot first.
 *
 * Relaxing the constraint is the precondition for the real fix: no expiry
 * supplied → `expiry_date IS NULL` → FEFO ranks it AFTER every dated lot.
 *
 * `pos_receipt_line_batch_allocations.expiry_date` is a SNAPSHOT of the lot's
 * expiry at the time of sale, so it inherits the same nullability. Rows already
 * written keep the date they snapshotted — a sealed receipt's allocation row is
 * never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable()->change();
        });

        Schema::table('pos_receipt_line_batch_allocations', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable()->change();
        });
    }

    /**
     * Restoring NOT NULL would fail on any tenant that has since minted a
     * genuinely expiry-less lot, so the down leg only re-tightens when the
     * columns hold no NULLs. That keeps the rollback honest: it either fully
     * reverses the schema change or refuses, rather than deleting lot rows to
     * make room for a constraint.
     */
    public function down(): void
    {
        $nullBatches = (int) DB::table('product_batches')
            ->whereNull('expiry_date')
            ->count();

        $nullAllocations = (int) DB::table('pos_receipt_line_batch_allocations')
            ->whereNull('expiry_date')
            ->count();

        if ($nullBatches > 0 || $nullAllocations > 0) {
            throw new RuntimeException(sprintf(
                'Cannot restore NOT NULL on expiry_date: %d product_batches row(s) and %d '
                .'pos_receipt_line_batch_allocations row(s) carry a NULL expiry. Give those lots a real '
                .'expiry (or delete them) before rolling this migration back.',
                $nullBatches,
                $nullAllocations,
            ));
        }

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable(false)->change();
        });

        Schema::table('pos_receipt_line_batch_allocations', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable(false)->change();
        });
    }
};
