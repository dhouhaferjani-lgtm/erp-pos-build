<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\Modules\Procurement\Domain\Dto\RfqPayload;
use PHPUnit\Framework\TestCase;

final class RfqPayloadTest extends TestCase
{
    public function test_it_round_trips_the_rfq_payload_shape(): void
    {
        $payload = RfqPayload::fromArray([
            'rfq' => [
                'group_id' => '01908744-7a64-7000-9f61-94b3dff5a111',
                'validity_date' => '2026-07-31',
                'supplier_reference' => 'SUP-Q-42',
                'lead_time_days' => 7,
                'response_recorded_at' => '2026-07-03T10:15:00+00:00',
                'sent_at' => '2026-07-02T09:00:00+00:00',
                'closed_reason' => 'lost',
            ],
        ]);

        $this->assertSame('01908744-7a64-7000-9f61-94b3dff5a111', $payload->groupId);
        $this->assertSame('2026-07-31', $payload->validityDate);
        $this->assertSame('SUP-Q-42', $payload->supplierReference);
        $this->assertSame(7, $payload->leadTimeDays);
        $this->assertSame('2026-07-03T10:15:00+00:00', $payload->responseRecordedAt);
        $this->assertSame('2026-07-02T09:00:00+00:00', $payload->sentAt);
        $this->assertSame('lost', $payload->closedReason);

        $this->assertSame([
            'rfq' => [
                'group_id' => '01908744-7a64-7000-9f61-94b3dff5a111',
                'validity_date' => '2026-07-31',
                'supplier_reference' => 'SUP-Q-42',
                'lead_time_days' => 7,
                'response_recorded_at' => '2026-07-03T10:15:00+00:00',
                'sent_at' => '2026-07-02T09:00:00+00:00',
                'closed_reason' => 'lost',
            ],
        ], $payload->toArray());
    }

    public function test_nullable_fields_default_to_null(): void
    {
        $payload = RfqPayload::fromArray([
            'rfq' => [
                'group_id' => '01908744-7a64-7000-9f61-94b3dff5a222',
            ],
        ]);

        $this->assertSame('01908744-7a64-7000-9f61-94b3dff5a222', $payload->groupId);
        $this->assertNull($payload->validityDate);
        $this->assertNull($payload->supplierReference);
        $this->assertNull($payload->leadTimeDays);
        $this->assertNull($payload->responseRecordedAt);
        $this->assertNull($payload->sentAt);
        $this->assertNull($payload->closedReason);
    }
}
