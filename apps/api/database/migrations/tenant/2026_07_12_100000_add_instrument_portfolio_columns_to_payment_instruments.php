<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_instruments', 'direction')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->string('direction', 10)->default('inbound')->after('status');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'kind')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                // Nullable during the additive cutover so legacy writers remain green
                // until the server-side creation paths are converged in later waves.
                $table->string('kind', 10)->nullable()->after('direction');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'origin')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->string('origin', 5)->default('web')->after('kind');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'bank_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->uuid('bank_id')->nullable()->after('bank_account');
                $table->index('bank_id', 'payment_instruments_bank_id_idx');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'idempotency_key')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->string('idempotency_key', 255)->nullable()->after('bank_id');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'remittance_id')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                // The FK is added after instrument_remittances exists (Task 5).
                $table->uuid('remittance_id')->nullable()->after('idempotency_key');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'needs_details')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->boolean('needs_details')->default(false)->after('remittance_id');
            });
        }

        if (! Schema::hasColumn('payment_instruments', 'dishonor_routing')) {
            Schema::table('payment_instruments', function (Blueprint $table): void {
                $table->string('dishonor_routing', 12)->nullable()->after('bounce_reason');
            });
        }

        $this->backfillKind();

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS payment_instruments_idempotency_key_uniq '.
                'ON payment_instruments (idempotency_key) WHERE idempotency_key IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS payment_instruments_idempotency_key_uniq');
        }

        $columns = [
            'direction',
            'kind',
            'origin',
            'bank_id',
            'idempotency_key',
            'remittance_id',
            'needs_details',
            'dishonor_routing',
        ];

        foreach (array_reverse($columns) as $column) {
            if (Schema::hasColumn('payment_instruments', $column)) {
                Schema::table('payment_instruments', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function backfillKind(): void
    {
        DB::table('payment_instruments')
            ->whereNull('kind')
            ->orderBy('id')
            ->chunkById(100, function ($instruments): void {
                foreach ($instruments as $instrument) {
                    $methodCode = DB::table('payment_methods')
                        ->where('id', (string) $instrument->payment_method_id)
                        ->value('code');
                    $normalizedCode = is_string($methodCode) ? strtoupper($methodCode) : '';
                    $kind = str_contains($normalizedCode, 'CHECK') || str_contains($normalizedCode, 'CHEQUE')
                        ? 'cheque'
                        : 'other';

                    DB::table('payment_instruments')
                        ->where('id', (string) $instrument->id)
                        ->update(['kind' => $kind]);
                }
            }, 'id');
    }
};
