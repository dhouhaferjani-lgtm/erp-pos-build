<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class DeliveryNoteBillingUnrelatedModelFixture extends Model
{
    /** @var array<string, string> */
    public array $payload = [];

    protected $table = 'unrelated_billing_records';

    public function write(): void
    {
        $this->payload['invoiced_at'] = 'not-a-delivery-note';
        $this->update(['invoice_id' => 'also-unrelated']);
    }
}
