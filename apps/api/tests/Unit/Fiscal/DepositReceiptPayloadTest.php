<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use PHPUnit\Framework\TestCase;

final class DepositReceiptPayloadTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validPayloadArray(): array
    {
        return [
            'actor_name' => 'Owner On The Go',
            'actor_user_id' => '11111111-1111-4111-8111-111111111111',
            'business_date' => '2026-06-08',
            'company_id' => '22222222-2222-4222-8222-222222222222',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'customer_category' => 'individual',
                'customer_id' => '33333333-3333-4333-8333-333333333333',
                'email' => null,
                'name' => 'Walk-in Account Holder',
                'phone' => '+21620000000',
            ],
            'deposit_receipt_uuid' => '44444444-4444-4444-8444-444444444444',
            'event_time_device' => '2026-06-08T10:15:00.000Z',
            'notes' => 'Paid in cash on the road',
            'partner_id' => '33333333-3333-4333-8333-333333333333',
            'payment' => [
                'amount' => '100.000',
                'method_code' => 'CASH',
                'repository_id' => '55555555-5555-4555-8555-555555555555',
            ],
            'tenant_id' => '66666666-6666-4666-8666-666666666666',
            'terminal_id' => '77777777-7777-4777-8777-777777777777',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];
    }

    public function test_from_array_maps_every_field(): void
    {
        $payload = DepositReceiptPayload::fromArray($this->validPayloadArray());

        $this->assertSame('44444444-4444-4444-8444-444444444444', $payload->depositReceiptUuid);
        $this->assertSame('2026-06-08', $payload->businessDate);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $payload->actorUserId);
        $this->assertSame('Owner On The Go', $payload->actorName);
        $this->assertSame('TND', $payload->currencyCode);
        $this->assertSame(3, $payload->currencyScale);
        $this->assertSame('Paid in cash on the road', $payload->notes);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $payload->partnerId);
        $this->assertSame(false, $payload->trainingFlag);
        $this->assertSame('FIFO', $payload->treasuryAllocationPolicy);
        $this->assertSame('100.000', $payload->payment['amount']);
        $this->assertSame('Walk-in Account Holder', $payload->customer['name']);
    }

    public function test_to_array_round_trips_the_source_payload(): void
    {
        $array = $this->validPayloadArray();

        $payload = DepositReceiptPayload::fromArray($array);

        $this->assertSame($array, $payload->toArray());
    }

    public function test_payload_keys_are_sorted_and_complete(): void
    {
        $keys = DepositReceiptPayload::PAYLOAD_KEYS;
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'PAYLOAD_KEYS must be lexicographically sorted');
        $this->assertSame(array_keys($this->validPayloadArray()), $keys);
    }

    public function test_optional_notes_may_be_null(): void
    {
        $array = $this->validPayloadArray();
        $array['notes'] = null;

        $payload = DepositReceiptPayload::fromArray($array);

        $this->assertNull($payload->notes);
    }
}
