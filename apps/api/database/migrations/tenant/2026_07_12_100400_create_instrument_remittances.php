<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No partial-unique journal-entry index is created for instrument source
 * types: one instrument legitimately produces multiple journal entries over
 * its lifecycle (remit, clear, bounce, re-present). Idempotency belongs to the
 * state machine, remittance lines, and movement/instrument keys instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instrument_remittances')) {
            Schema::create('instrument_remittances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->string('number', 32);
                $table->string('remittance_type', 16);
                $table->string('instrument_kind', 10);
                $table->foreignUuid('bank_repository_id')->constrained('payment_repositories')->restrictOnDelete();
                $table->string('status', 12)->default('draft');
                $table->timestamp('remitted_at')->nullable();
                $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->uuid('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'number'], 'instrument_remittances_company_number_uniq');
                $table->index(['company_id', 'status'], 'instrument_remittances_company_status_idx');
            });
        }

        if (! Schema::hasTable('instrument_remittance_lines')) {
            Schema::create('instrument_remittance_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('remittance_id')->constrained('instrument_remittances')->cascadeOnDelete();
                $table->foreignUuid('instrument_id')->constrained('payment_instruments')->restrictOnDelete();
                $table->decimal('amount', 15, 3);
                $table->string('line_status', 12)->default('pending');
                $table->timestamp('cleared_at')->nullable();
                $table->timestamp('bounced_at')->nullable();

                $table->unique(
                    ['remittance_id', 'instrument_id'],
                    'instrument_remittance_lines_remittance_instrument_uniq',
                );
            });
        }

        if (Schema::hasColumn('payment_instruments', 'remittance_id') && ! $this->hasRemittanceForeignKey()) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->foreign('remittance_id', 'payment_instruments_remittance_id_foreign')
                    ->references('id')
                    ->on('instrument_remittances')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_instruments')
            && Schema::hasColumn('payment_instruments', 'remittance_id')
            && $this->hasRemittanceForeignKey()) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->dropForeign('payment_instruments_remittance_id_foreign');
            });
        }

        Schema::dropIfExists('instrument_remittance_lines');
        Schema::dropIfExists('instrument_remittances');
    }

    private function hasRemittanceForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('payment_instruments') as $foreignKey) {
            if (in_array('remittance_id', $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
