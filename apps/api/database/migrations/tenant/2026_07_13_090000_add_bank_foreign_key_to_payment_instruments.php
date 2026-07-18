<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'payment_instruments_bank_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('payment_instruments')
            || ! Schema::hasTable('banks')
            || ! Schema::hasColumn('payment_instruments', 'bank_id')) {
            return;
        }

        $orphanCount = DB::table('payment_instruments')
            ->whereNotNull('bank_id')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('banks')
                    ->whereColumn('banks.id', 'payment_instruments.bank_id');
            })
            ->update(['bank_id' => null]);

        if ($orphanCount > 0) {
            Log::warning(
                'Migration: nulled orphaned payment instrument bank references.',
                ['orphan_count' => $orphanCount],
            );
        }

        if ($this->hasBankForeignKey()) {
            return;
        }

        Schema::table('payment_instruments', function (Blueprint $table): void {
            $table->foreign('bank_id', self::FOREIGN_KEY)
                ->references('id')
                ->on('banks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_instruments')
            || ! Schema::hasColumn('payment_instruments', 'bank_id')
            || ! $this->hasBankForeignKey()) {
            return;
        }

        Schema::table('payment_instruments', function (Blueprint $table): void {
            $table->dropForeign(['bank_id']);
        });
    }

    private function hasBankForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('payment_instruments') as $foreignKey) {
            if (in_array('bank_id', $foreignKey['columns'], true)
                && $foreignKey['foreign_table'] === 'banks') {
                return true;
            }
        }

        return false;
    }
};
