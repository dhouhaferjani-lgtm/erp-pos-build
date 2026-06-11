<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use RuntimeException;
use Tests\TestCase;

/**
 * Constraint coverage for the server-authored DEPOSIT_RECEIPT payload.
 *
 * Shape-only (no DB); mirrors FiscalPayloadConstraintValidatorTest.
 */
final class DepositReceiptPayloadConstraintTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FiscalPayloadConstraintValidator;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
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

    public function test_valid_payload_is_accepted(): void
    {
        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::DEPOSIT_RECEIPT, $this->validPayload()));

        $this->validator->validatePerEventConstraints(FiscalEventType::DEPOSIT_RECEIPT, $this->validPayload());
        $this->addToAssertionCount(1);
    }

    public function test_missing_required_key_is_reported(): void
    {
        $payload = $this->validPayload();
        unset($payload['deposit_receipt_uuid']);

        $result = $this->validator->validatePayloadKeySet(FiscalEventType::DEPOSIT_RECEIPT, $payload);

        self::assertSame('payload_missing_required:deposit_receipt_uuid', $result);
    }

    public function test_extra_key_is_reported(): void
    {
        $payload = $this->validPayload();
        $payload['unexpected'] = 'x';

        $result = $this->validator->validatePayloadKeySet(FiscalEventType::DEPOSIT_RECEIPT, $payload);

        self::assertSame('payload_extra_field:unexpected', $result);
    }

    public function test_unsupported_currency_scale_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['currency_scale'] = 4;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_currency_scale_unsupported/');

        $this->validator->validatePerEventConstraints(FiscalEventType::DEPOSIT_RECEIPT, $payload);
    }

    public function test_zero_amount_is_rejected_when_not_training(): void
    {
        $payload = $this->validPayload();
        $payload['payment']['amount'] = '0.000';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payload_deposit_receipt_amount_zero/');

        $this->validator->validatePerEventConstraints(FiscalEventType::DEPOSIT_RECEIPT, $payload);
    }

    public function test_non_fifo_allocation_policy_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['treasury_allocation_policy'] = 'LIFO';

        $this->expectException(RuntimeException::class);

        $this->validator->validatePerEventConstraints(FiscalEventType::DEPOSIT_RECEIPT, $payload);
    }

    public function test_customer_with_extra_key_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['customer']['tax_number'] = 'X';

        $this->expectException(RuntimeException::class);

        $this->validator->validatePerEventConstraints(FiscalEventType::DEPOSIT_RECEIPT, $payload);
    }
}
