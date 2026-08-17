<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptLineageResourceTest extends ReceiptReportingTestCase
{
    public function test_detail_emits_allowlisted_bidirectional_lineage_with_magnitude_totals(): void
    {
        $original = $this->createReceipt('SALE');
        $original->forceFill(['currency' => 'TND'])->saveQuietly();
        $middle = $this->createRelatedReceipt('REFUND', $original, '-5.250', ReturnReason::Defective);
        $child = $this->createRelatedReceipt('VOID', $middle, '2.000', ReturnReason::WrongItem);

        $data = $this->getJson('/api/v1/pos/receipts/'.$middle->id)
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'id', 'receipt_number', 'posted_at', 'invoice_type_code', 'total', 'currency',
            'refund_reason', 'refund_reason_source', 'refund_destination',
        ], array_keys($data['return_receipts'][0]));
        $this->assertSame($child->id, $data['return_receipts'][0]['id']);
        $this->assertSame('2.000', $data['return_receipts'][0]['total']);
        $this->assertSame('Wrong Item', $data['return_receipts'][0]['refund_reason']);
        $this->assertSame('legacy_enum', $data['return_receipts'][0]['refund_reason_source']);
        $this->assertNull($data['return_receipts'][0]['refund_destination']);

        $this->assertSame(
            ['id', 'receipt_number', 'posted_at', 'total', 'currency'],
            array_keys($data['original_receipt']),
        );
        $this->assertSame($original->id, $data['original_receipt']['id']);
        $this->assertSame($original->receipt_number, $data['original_receipt']['receipt_number']);
        $this->assertSame($this->moneyMagnitude((string) $original->total), $data['original_receipt']['total']);

        $this->assertForbiddenKeysAbsentRecursively($data);
    }

    public function test_detail_omits_related_receipts_outside_the_users_location_scope(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);
        $original = $this->createReceipt('SALE', location: $otherLocation);
        $refund = $this->createRelatedReceipt('REFUND', $original, '-3.000', ReturnReason::Other);
        $hiddenChild = $this->createReceipt('VOID', location: $otherLocation);
        $hiddenChild->forceFill([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $refund->id,
            'return_reason' => ReturnReason::WrongItem,
        ])->saveQuietly();

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => [$this->location->id]]);

        $this->getJson('/api/v1/pos/receipts/'.$refund->id)
            ->assertOk()
            ->assertJsonPath('data.original_receipt_id', $original->id)
            ->assertJsonPath('data.original_receipt', null)
            ->assertJsonCount(0, 'data.return_receipts');
    }

    private function createRelatedReceipt(
        string $invoiceTypeCode,
        Receipt $original,
        string $total,
        ReturnReason $reason,
    ): Receipt {
        $receipt = $this->createReceipt($invoiceTypeCode);
        $receipt->forceFill([
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => $reason,
            'total' => $total,
            'currency' => 'TND',
        ])->saveQuietly();

        return $receipt;
    }

    /** @param array<string, mixed> $node */
    private function assertForbiddenKeysAbsentRecursively(array $node): void
    {
        $forbidden = [
            'canonical_bytes', 'tenant_id', 'company_id', 'payload', 'signature',
            'password', 'remember_token', 'deleted_at', 'updated_at',
        ];

        foreach ($node as $key => $value) {
            $this->assertNotContains($key, $forbidden);
            if (is_array($value)) {
                $this->assertForbiddenKeysAbsentRecursively($value);
            }
        }
    }

    private function moneyMagnitude(string $value): string
    {
        return bccomp($value, '0', 3) < 0 ? bcsub('0', $value, 3) : bcadd($value, '0', 3);
    }
}
