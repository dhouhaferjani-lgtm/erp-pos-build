<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3-refund-chain-integration spec §5.2 — one idempotent compensation
 * record per rejected refund event.
 *
 * `fiscal_event_id` is the `fiscal_events.id` of the REJECTED REFUND EVENT
 * ITSELF — universally addressable regardless of whether the rejection
 * came from projection dead-letter or ingress quarantine (§5.1's gap (2)).
 * No FK to `fiscal_events` — a quarantined ingress row may have no
 * corresponding projection row and the compensation endpoint must remain
 * reachable for both classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Final-review MINOR M-1 — self-guarding idempotence, matching the
        // `Schema::hasTable`/`hasColumn` no-op guard the other six
        // migrations in the 2026_07_31_9xxxxx / 2026_08_01 range open with.
        // A partially-applied batch re-run on a staging tenant must no-op,
        // not throw "relation already exists".
        if (Schema::hasTable('fiscal_refund_compensations')) {
            return;
        }

        Schema::create('fiscal_refund_compensations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('fiscal_event_id');
            $table->string('compensation_class', 32); // 'invalid_refund' | 'valid_unbooked'
            // review round-2 IMPORTANT 14 (§5.2 evidence (a)) — the refund
            // event's OWN signed `shift_id`, read from its parsed payload at
            // compensation time. Payout evidence for this compensation
            // record includes WHICH shift the original refund attempt
            // belonged to.
            $table->uuid('shift_id')->nullable();
            $table->uuid('journal_entry_id');
            $table->uuid('repository_movement_id');
            $table->uuid('operator_id');
            $table->text('operator_attestation');
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'company_id']);
        });

        // Idempotency — mirrors the repository's own established
        // "one record per source event" precedent:
        // journal_entries_treasury_transfer_source_unique
        // (2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php).
        // No driver guard — sqlite supports partial/unique indexes too.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX fiscal_refund_compensations_event_unique
            ON fiscal_refund_compensations (fiscal_event_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_refund_compensations');
    }
};
