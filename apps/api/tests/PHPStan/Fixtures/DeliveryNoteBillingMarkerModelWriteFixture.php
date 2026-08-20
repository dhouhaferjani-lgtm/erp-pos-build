<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class DeliveryNoteBillingMarkerModelFixtureStub extends Model
{
    protected $table = 'delivery_note_billing_marks';

    protected $guarded = [];
}

final class DeliveryNoteBillingMarkerModelWriteFixture
{
    public function write(): void
    {
        DeliveryNoteBillingMarkerModelFixtureStub::create(['delivery_note_id' => 'dn-id']);
    }
}
