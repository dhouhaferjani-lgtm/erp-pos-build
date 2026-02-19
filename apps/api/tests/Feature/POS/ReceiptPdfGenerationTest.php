<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test receipt PDF generation
 *
 * Verifies that PDF generation works correctly and includes all required data,
 * especially fiscal hash and chain information.
 */
class ReceiptPdfGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptPdfService $pdfService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdfService = app(ReceiptPdfService::class);
    }

    /** @test */
    public function it_generates_pdf_with_fiscal_hash(): void
    {
        // Arrange
        $receipt = $this->createTestReceipt();

        // Act
        $pdf = $this->pdfService->generate($receipt);
        $content = $pdf->output();

        // Assert
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('%PDF', $content); // PDF header
    }

    /** @test */
    public function it_generates_pdf_content_as_binary_string(): void
    {
        // Arrange
        $receipt = $this->createTestReceipt();

        // Act
        $content = $this->pdfService->generateContent($receipt);

        // Assert
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('%PDF', $content);
    }

    /** @test */
    public function it_generates_correct_filename(): void
    {
        // Arrange
        $receipt = $this->createTestReceipt();

        // Act
        $filename = $this->pdfService->getFilename($receipt);

        // Assert
        $this->assertStringContainsString('receipt-', $filename);
        $this->assertStringContainsString('.pdf', $filename);
        $this->assertStringContainsString($receipt->receipt_number, $filename);
    }

    /**
     * Create a test receipt with all required relationships
     */
    private function createTestReceipt(): Receipt
    {
        // Create tenant and company
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Garage',
            'tax_id' => 'FR12345678901',
            'address_street' => '123 Test Street',
            'address_city' => 'Paris',
            'address_postal_code' => '75001',
            'phone' => '+33 1 23 45 67 89',
        ]);

        // Create location
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Main Location',
            'code' => 'LOC01',
            'type' => 'warehouse', // Required field
            'is_active' => true,
        ]);

        // Create terminal
        $terminal = Terminal::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'code' => 'POS01',
            'name' => 'Terminal 1',
            'device_type' => 'tablet',
            'genesis_seed' => bin2hex(random_bytes(32)), // Required for receipt hash chain
            'is_active' => true,
        ]);

        // Create cashier
        $cashier = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'John Cashier',
        ]);

        // Create payment method
        $paymentMethod = PaymentMethod::factory()->create([
            'company_id' => $company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        // Create receipt
        $receipt = Receipt::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'T001-C042-L01-POS01-2026-00000001',
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'test-receipt-1'),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-breakdown'),
            'payment_methods_hash' => hash('sha256', 'payment-methods'),
            'posted_at' => now(),
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'currency' => 'EUR',
            'is_voided' => false,
        ]);

        // Create receipt line
        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Test Product',
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'line_total' => '100.00',
            'tax_rate' => '19.00',
            'tax_amount' => '19.00',
            'discount_amount' => '0.00',
        ]);

        // Create VAT detail
        ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => '19.00',
            'net_amount' => '100.00',
            'vat_amount' => '19.00',
            'gross_amount' => '119.00',
        ]);

        // Create payment
        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'Cash',
            'amount' => '119.00',
        ]);

        // Reload with relationships
        return $receipt->load([
            'company',
            'location',
            'terminal',
            'cashier',
            'lines',
            'vatDetails',
            'payments.paymentMethod',
        ]);
    }
}
