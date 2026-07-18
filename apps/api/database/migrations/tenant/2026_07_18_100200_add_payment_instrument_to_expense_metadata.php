<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'expense_metadata_payment_instrument_id_uniq';

    public function up(): void
    {
        if (! Schema::hasTable('expense_metadata') || ! Schema::hasTable('payment_instruments')) {
            return;
        }

        if (! Schema::hasColumn('expense_metadata', 'payment_instrument_id')) {
            Schema::table('expense_metadata', function (Blueprint $table): void {
                $table->uuid('payment_instrument_id')->nullable()->after('payment_repository_id');
            });
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON expense_metadata (payment_instrument_id) WHERE payment_instrument_id IS NOT NULL',
            self::UNIQUE_INDEX,
        ));

        if (! $this->hasPaymentInstrumentForeignKey()) {
            Schema::table('expense_metadata', function (Blueprint $table): void {
                $table->foreign('payment_instrument_id')
                    ->references('id')
                    ->on('payment_instruments')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('expense_metadata')
            || ! Schema::hasColumn('expense_metadata', 'payment_instrument_id')) {
            return;
        }

        if ($this->hasPaymentInstrumentForeignKey()) {
            Schema::table('expense_metadata', function (Blueprint $table): void {
                $table->dropForeign(['payment_instrument_id']);
            });
        }

        DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);

        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->dropColumn('payment_instrument_id');
        });
    }

    private function hasPaymentInstrumentForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('expense_metadata') as $foreignKey) {
            if (in_array('payment_instrument_id', $foreignKey['columns'], true)
                && $foreignKey['foreign_table'] === 'payment_instruments') {
                return true;
            }
        }

        return false;
    }
};
