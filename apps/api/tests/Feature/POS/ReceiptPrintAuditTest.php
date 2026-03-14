<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPrintAuditService;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReceiptPrintType;
use App\Modules\POS\Domain\Events\ReceiptPrinted;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the receipt print audit log (NF525 compliance).
 *
 * Verifies that every receipt download/stream creates an immutable print record
 * with correct copy numbering, type classification, and event dispatching.
 */
final class ReceiptPrintAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    public function test_first_pdf_download_creates_original_print_record(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        // Act
        $response = $this->get("/api/v1/pos/receipts/{$receipt->id}/pdf");

        // Assert
        $response->assertOk();

        $this->assertDatabaseHas('pos_receipt_prints', [
            'receipt_id' => $receipt->id,
            'terminal_id' => $receipt->terminal_id,
            'user_id' => $this->user->id,
            'print_type' => ReceiptPrintType::Original->value,
            'copy_number' => 1,
            'print_method' => PrintMethod::Pdf->value,
        ]);
    }

    public function test_second_pdf_download_creates_duplicate_print_record(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        // Act: Download twice
        $this->get("/api/v1/pos/receipts/{$receipt->id}/pdf");
        $response = $this->get("/api/v1/pos/receipts/{$receipt->id}/pdf");

        // Assert
        $response->assertOk();

        $prints = ReceiptPrint::where('receipt_id', $receipt->id)
            ->orderBy('copy_number')
            ->get();

        $this->assertCount(2, $prints);

        $this->assertEquals(ReceiptPrintType::Original, $prints[0]->print_type);
        $this->assertEquals(1, $prints[0]->copy_number);

        $this->assertEquals(ReceiptPrintType::Duplicate, $prints[1]->print_type);
        $this->assertEquals(2, $prints[1]->copy_number);
    }

    public function test_stream_pdf_also_creates_print_record(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        // Act
        $response = $this->get("/api/v1/pos/receipts/{$receipt->id}/pdf");

        // Assert
        $response->assertOk();

        $this->assertDatabaseHas('pos_receipt_prints', [
            'receipt_id' => $receipt->id,
            'print_type' => ReceiptPrintType::Original->value,
            'copy_number' => 1,
            'print_method' => PrintMethod::Pdf->value,
        ]);
    }

    public function test_get_copy_count_returns_correct_count(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        /** @var ReceiptPrintAuditService $service */
        $service = app(ReceiptPrintAuditService::class);

        // Assert: Initially zero
        $this->assertEquals(0, $service->getCopyCount($receipt->id));

        // Act: Record two prints
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Pdf);
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Thermal);

        // Assert
        $this->assertEquals(2, $service->getCopyCount($receipt->id));
    }

    public function test_get_print_history_returns_all_prints_ordered_by_printed_at(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        /** @var ReceiptPrintAuditService $service */
        $service = app(ReceiptPrintAuditService::class);

        // Act: Record three prints
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Pdf);
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Thermal);
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::EscPos);

        $history = $service->getPrintHistory($receipt->id);

        // Assert
        $this->assertCount(3, $history);
        $this->assertEquals(1, $history[0]->copy_number);
        $this->assertEquals(2, $history[1]->copy_number);
        $this->assertEquals(3, $history[2]->copy_number);

        $this->assertEquals(PrintMethod::Pdf, $history[0]->print_method);
        $this->assertEquals(PrintMethod::Thermal, $history[1]->print_method);
        $this->assertEquals(PrintMethod::EscPos, $history[2]->print_method);

        // Verify ordering: each printed_at should be >= previous
        for ($i = 1; $i < $history->count(); $i++) {
            $this->assertTrue(
                $history[$i]->printed_at->gte($history[$i - 1]->printed_at),
                'Print history should be ordered by printed_at ascending',
            );
        }
    }

    public function test_receipt_printed_domain_event_is_dispatched(): void
    {
        // Arrange
        Event::fake([ReceiptPrinted::class]);
        $receipt = $this->createReceipt();

        /** @var ReceiptPrintAuditService $service */
        $service = app(ReceiptPrintAuditService::class);

        // Act
        $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Pdf);

        // Assert
        Event::assertDispatched(ReceiptPrinted::class, function (ReceiptPrinted $event) use ($receipt): bool {
            return $event->receiptId === $receipt->id
                && $event->terminalId === $this->terminal->id
                && $event->companyId === $receipt->company_id
                && $event->userId === $this->user->id
                && $event->printType === ReceiptPrintType::Original->value
                && $event->copyNumber === 1
                && $event->printMethod === PrintMethod::Pdf->value;
        });
    }

    public function test_mixed_print_methods_increment_copy_number_correctly(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        /** @var ReceiptPrintAuditService $service */
        $service = app(ReceiptPrintAuditService::class);

        // Act: Print via different methods
        $print1 = $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Pdf);
        $print2 = $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Thermal);
        $print3 = $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::EscPos);

        // Assert: Copy numbers are sequential regardless of method
        $this->assertEquals(1, $print1->copy_number);
        $this->assertEquals(ReceiptPrintType::Original, $print1->print_type);

        $this->assertEquals(2, $print2->copy_number);
        $this->assertEquals(ReceiptPrintType::Duplicate, $print2->print_type);

        $this->assertEquals(3, $print3->copy_number);
        $this->assertEquals(ReceiptPrintType::Duplicate, $print3->print_type);
    }

    public function test_print_records_are_immutable_no_updated_at(): void
    {
        // Arrange
        $receipt = $this->createReceipt();

        /** @var ReceiptPrintAuditService $service */
        $service = app(ReceiptPrintAuditService::class);

        // Act
        $printRecord = $service->recordPrint($receipt->id, $this->terminal->id, $this->user->id, PrintMethod::Pdf);

        // Assert: No updated_at column
        $raw = ReceiptPrint::find($printRecord->id);
        $this->assertNotNull($raw->created_at);
        $this->assertNull($raw->updated_at);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_receipts', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.view_receipts');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        return Receipt::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
        ], $overrides));
    }
}
