<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReceiptPrintType;
use App\Modules\POS\Domain\ReceiptPrint;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptPdfPrintAuditEndpointTest extends ReceiptReportingTestCase
{
    public function test_stream_and_download_each_create_an_immutable_sequential_print_record(): void
    {
        $receipt = $this->createReceipt();

        $this->get('/api/v1/pos/receipts/'.$receipt->id.'/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/pos/receipts/'.$receipt->id.'/pdf/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $prints = ReceiptPrint::query()
            ->where('receipt_id', $receipt->id)
            ->orderBy('copy_number')
            ->get();

        $this->assertCount(2, $prints);
        $this->assertSame(ReceiptPrintType::Original, $prints[0]->print_type);
        $this->assertSame(1, $prints[0]->copy_number);
        $this->assertSame(PrintMethod::Pdf, $prints[0]->print_method);
        $this->assertSame(ReceiptPrintType::Duplicate, $prints[1]->print_type);
        $this->assertSame(2, $prints[1]->copy_number);
        $this->assertSame(PrintMethod::Pdf, $prints[1]->print_method);
        $this->assertNull($prints[0]->updated_at);
        $this->assertNull($prints[1]->updated_at);
    }

    public function test_denied_and_missing_receipt_pdf_requests_create_no_print_record(): void
    {
        $receipt = $this->createReceipt();
        $this->user->revokePermissionTo('pos.view_receipts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get('/api/v1/pos/receipts/'.$receipt->id.'/pdf')->assertForbidden();
        $this->get('/api/v1/pos/receipts/'.$receipt->id.'/pdf/download')->assertForbidden();
        $this->assertDatabaseCount('pos_receipt_prints', 0);

        $this->user->givePermissionTo('pos.view_receipts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $missingId = Str::uuid()->toString();
        $this->get('/api/v1/pos/receipts/'.$missingId.'/pdf')->assertNotFound();
        $this->get('/api/v1/pos/receipts/'.$missingId.'/pdf/download')->assertNotFound();
        $this->assertDatabaseCount('pos_receipt_prints', 0);
    }
}
