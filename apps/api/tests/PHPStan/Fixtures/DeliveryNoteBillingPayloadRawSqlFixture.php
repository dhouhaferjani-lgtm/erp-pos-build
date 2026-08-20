<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Support\Facades\DB;

final class DeliveryNoteBillingPayloadRawSqlFixture
{
    public function write(): void
    {
        DB::statement('UPDATE documents SET payload = \'{"invoiced_at":"2026-08-18T10:00:00+00:00"}\' WHERE type = \'delivery_note\'');
    }
}
