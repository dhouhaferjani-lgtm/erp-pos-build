<?php

declare(strict_types=1);

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_event_type_allowed');

        $allowed = FiscalEventType::checkConstraintList();
        DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_event_type_allowed CHECK (event_type IN ({$allowed}))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_event_type_allowed');

        $allowed = implode(', ', array_map(
            static fn (string $type): string => "'{$type}'",
            [
                'SALE_RECEIPT',
                'CHAIN_BREAK_DETECTED',
                'CHAIN_RESTART',
                'TERMINAL_REGISTRY_SNAPSHOT',
                'COMPANY_DAY_CLOSURE_MANIFEST',
                'ACCOUNT_PAYMENT',
                'ACCOUNT_CHARGE',
                'ACCOUNT_REFUND',
                'ACCOUNT_PAYMENT_RECONCILED',
                'ACCOUNT_CREDIT_ISSUE',
                'ACCOUNT_CREDIT_USAGE',
                'DEPOSIT_RECEIPT',
                'IDENTITY_ALIAS_RECONCILED',
                'SALE_VOID',
                'SALE_CORRECTION',
                'REFUND_RECEIPT',
                'PARTIAL_REFUND',
                'RETURN_WITHOUT_RECEIPT',
                'OPENING_FLOAT',
                'CASH_IN',
                'CASH_OUT',
                'SAFE_DROP',
                'CASH_CORRECTION',
                'SESSION_OPEN',
                'SESSION_CLOSE',
                'X_REPORT',
                'Z_REPORT',
                'REPRINT_COPY',
            ],
        ));

        DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_event_type_allowed CHECK (event_type IN ({$allowed}))");
    }
};
