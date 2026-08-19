<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Support\Facades\DB;

final class DeliveryNoteBillingNonMarkerRawSqlFixture
{
    public function repairCreditNoteCache(): int
    {
        return DB::update('UPDATE credit_note_allocations SET invoice_id = invoice_id');
    }
}
