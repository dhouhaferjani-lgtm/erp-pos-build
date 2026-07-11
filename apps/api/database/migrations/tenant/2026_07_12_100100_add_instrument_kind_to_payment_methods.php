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
        if (! Schema::hasColumn('payment_methods', 'instrument_kind')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->string('instrument_kind', 10)->nullable()->after('has_maturity');
            });
        }

        DB::table('payment_methods')
            ->where('has_maturity', true)
            ->whereNull('instrument_kind')
            ->orderBy('id')
            ->chunkById(100, function ($methods): void {
                foreach ($methods as $method) {
                    $code = strtoupper((string) $method->code);
                    $kind = match ($code) {
                        'CHECK' => 'cheque',
                        'TRAITE', 'LCR', 'BILL_EXCHANGE' => 'effet',
                        default => 'other',
                    };

                    DB::table('payment_methods')
                        ->where('id', (string) $method->id)
                        ->update(['instrument_kind' => $kind]);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_methods', 'instrument_kind')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->dropColumn('instrument_kind');
            });
        }
    }
};
