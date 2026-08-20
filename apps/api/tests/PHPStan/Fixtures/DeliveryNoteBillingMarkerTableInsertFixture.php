<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Support\Facades\DB;

final class DeliveryNoteBillingMarkerTableInsertFixture
{
    public function write(): void
    {
        DB::table('delivery_note_billing_marks')->insert(['delivery_note_id' => 'dn-id']);
    }
}
