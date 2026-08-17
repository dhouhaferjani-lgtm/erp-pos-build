<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;

final class RefundReportingFieldsTest extends ReceiptReportingTestCase
{
    public function test_index_uses_canonical_refund_fields_and_legacy_fallback_in_one_event_query(): void
    {
        $original = $this->createReceipt('SALE');
        $payload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $canonicalEvent = $this->createFiscalEvent($payload);
        $canonical = $this->createRefund(
            invoiceTypeCode: 'REFUND',
            original: $original,
            reason: ReturnReason::Other,
            fiscalEvent: $canonicalEvent,
        );
        $legacy = $this->createRefund(
            invoiceTypeCode: 'REFUND',
            original: $original,
            reason: ReturnReason::Defective,
        );
        $void = $this->createRefund(
            invoiceTypeCode: 'VOID',
            original: $original,
            reason: ReturnReason::WrongItem,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson(
            '/api/v1/pos/receipts?invoice_type_codes[]=REFUND&invoice_type_codes[]=VOID',
        )->assertOk();

        $rows = collect($response->json('data.data'))->keyBy('id');
        $this->assertSame($original->receipt_number, $rows[$canonical->id]['original_receipt_number']);
        $this->assertSame('customer return', $rows[$canonical->id]['refund_reason']);
        $this->assertSame('canonical', $rows[$canonical->id]['refund_reason_source']);
        $this->assertSame('cash', $rows[$canonical->id]['refund_destination']);
        $this->assertSame([], $rows[$canonical->id]['refund_policy_alerts']);

        $this->assertSame('Defective Product', $rows[$legacy->id]['refund_reason']);
        $this->assertSame('legacy_enum', $rows[$legacy->id]['refund_reason_source']);
        $this->assertNull($rows[$legacy->id]['refund_destination']);
        $this->assertSame('Wrong Item', $rows[$void->id]['refund_reason']);
        $this->assertSame('legacy_enum', $rows[$void->id]['refund_reason_source']);
        $this->assertNull($rows[$void->id]['refund_destination']);

        $fiscalEventQueries = collect(DB::getQueryLog())->filter(
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "fiscal_events"'),
        );
        $this->assertCount(1, $fiscalEventQueries);
    }

    public function test_null_and_deeply_malformed_payloads_degrade_to_legacy_fields_and_log_warnings(): void
    {
        Log::spy();

        $original = $this->createReceipt('SALE');
        $validPayload = GoldenFixtureBuilder::all()['F-16-refund-v4-cash-eur'];
        $missingRequiredKey = $validPayload;
        unset($missingRequiredKey['line_items'][0]['name']);
        $scalarLineItem = $validPayload;
        $scalarLineItem['line_items'][0] = 'not-an-object';

        $events = [
            $this->createFiscalEvent(null),
            $this->createFiscalEvent($missingRequiredKey),
            $this->createFiscalEvent($scalarLineItem),
        ];
        $refunds = collect($events)->map(fn (FiscalEvent $event): Receipt => $this->createRefund(
            invoiceTypeCode: 'REFUND',
            original: $original,
            reason: ReturnReason::WrongItem,
            fiscalEvent: $event,
        ));

        $response = $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=REFUND')
            ->assertOk();

        $rows = collect($response->json('data.data'))->keyBy('id');
        foreach ($refunds as $refund) {
            $this->assertSame('Wrong Item', $rows[$refund->id]['refund_reason']);
            $this->assertSame('legacy_enum', $rows[$refund->id]['refund_reason_source']);
            $this->assertNull($rows[$refund->id]['refund_destination']);
        }

        $eventIds = collect($events)->pluck('id')->all();
        Log::shouldHaveReceived('warning')->times(3)->with(
            'Refund reporting payload degraded to the legacy receipt fallback.',
            \Mockery::on(static fn (array $context): bool => in_array($context['fiscal_event_id'], $eventIds, true)
                && is_string($context['exception'])),
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function createFiscalEvent(?array $payload): FiscalEvent
    {
        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'server_received_at' => now(),
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => $payload === null ? IntegrityStatus::Quarantined : IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => $payload === null ? PayloadParseStatus::Failed : PayloadParseStatus::Parsed,
        ]);
    }

    private function createRefund(
        string $invoiceTypeCode,
        Receipt $original,
        ReturnReason $reason,
        ?FiscalEvent $fiscalEvent = null,
    ): Receipt {
        $receipt = $this->createReceipt($invoiceTypeCode);
        $receipt->forceFill([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => $reason,
            'fiscal_event_id' => $fiscalEvent?->id,
            'refund_policy_alerts' => null,
        ])->saveQuietly();

        return $receipt;
    }
}
