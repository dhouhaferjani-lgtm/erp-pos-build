<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use InvalidArgumentException;
use Tests\TestCase;

final class CanonicalPayloadReaderDepositReceiptTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function depositReceiptPayload(): array
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
            'notes' => null,
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

    public function test_for_deposit_receipt_builds_typed_view(): void
    {
        $event = new FiscalEvent;
        $event->event_type = FiscalEventType::DEPOSIT_RECEIPT;
        $event->payload = $this->depositReceiptPayload();

        $view = (new CanonicalPayloadReader)->forDepositReceipt($event);

        self::assertSame('44444444-4444-4444-8444-444444444444', $view->payload->depositReceiptUuid);
        self::assertSame('33333333-3333-4333-8333-333333333333', $view->customer->customerId);
        self::assertSame('Walk-in Account Holder', $view->customer->name);
        self::assertSame('100.000', $view->payment->amount);
        self::assertSame('CASH', $view->payment->methodCode);
        self::assertSame('55555555-5555-4555-8555-555555555555', $view->payment->repositoryId);
    }

    public function test_for_deposit_receipt_rejects_wrong_event_type(): void
    {
        $event = new FiscalEvent;
        $event->event_type = FiscalEventType::ACCOUNT_PAYMENT;
        $event->payload = $this->depositReceiptPayload();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/forDepositReceipt called with event_type=ACCOUNT_PAYMENT/');

        (new CanonicalPayloadReader)->forDepositReceipt($event);
    }

    public function test_for_deposit_receipt_rejects_null_payload(): void
    {
        $event = new FiscalEvent;
        $event->event_type = FiscalEventType::DEPOSIT_RECEIPT;
        $event->payload = null;

        $this->expectException(InvalidArgumentException::class);

        (new CanonicalPayloadReader)->forDepositReceipt($event);
    }
}
