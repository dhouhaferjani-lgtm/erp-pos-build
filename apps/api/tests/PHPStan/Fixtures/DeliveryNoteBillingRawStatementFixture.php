<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Support\Facades\DB;

final class DeliveryNoteBillingRawStatementFixture
{
    public function write(): void
    {
        DB::statement("UPDATE delivery_note_billing_marks SET invoice_id = 'invoice-id'");
    }
}
