<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptResourceNoCanonicalBytesRecursiveTest extends ReceiptReportingTestCase
{
    public function test_forbidden_fiscal_and_tenant_keys_are_absent_at_every_lineage_depth(): void
    {
        $original = $this->createReceipt('SALE');
        $middle = $this->createReturn($original, 'REFUND');
        $this->createReturn($middle, 'VOID');

        $tree = $this->getJson('/api/v1/pos/receipts/'.$middle->id)
            ->assertOk()
            ->json('data');

        $this->assertNotNull($tree['original_receipt']);
        $this->assertNotEmpty($tree['return_receipts']);
        $this->assertForbiddenKeysAbsentRecursively($tree);
    }

    private function createReturn(Receipt $original, string $invoiceTypeCode): Receipt
    {
        return $this->createReceipt($invoiceTypeCode, attributes: [
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::Other,
            'canonical_bytes' => '{"must_not_leak":true}',
            'vat_breakdown_hash' => str_repeat('c', 64),
            'payment_methods_hash' => str_repeat('d', 64),
        ]);
    }

    /** @param array<string, mixed> $node */
    private function assertForbiddenKeysAbsentRecursively(array $node): void
    {
        $forbidden = [
            'canonical_bytes',
            'vat_breakdown_hash',
            'payment_methods_hash',
            'tenant_id',
        ];

        foreach ($node as $key => $value) {
            $this->assertNotContains($key, $forbidden);
            if (is_array($value)) {
                $this->assertForbiddenKeysAbsentRecursively($value);
            }
        }
    }
}
