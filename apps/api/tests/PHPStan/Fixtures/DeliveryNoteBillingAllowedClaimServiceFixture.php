<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Billing;

use App\Modules\Document\Domain\Document;
use Illuminate\Support\Facades\DB;

class DeliveryNoteBillingClaimService
{
    public function fixtureWrite(Document $deliveryNote): void
    {
        $deliveryNote->payload['invoiced_at'] = '2026-08-18T10:00:00+00:00';
        DB::table('delivery_note_billing_marks')->insert(['delivery_note_id' => $deliveryNote->id]);
    }
}
