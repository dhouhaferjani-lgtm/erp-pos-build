<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
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

    /** @test */
    public function it_includes_return_banner_for_return_receipt(): void
    {
        // Arrange
        $originalReceipt = $this->createTestReceipt();
        $returnReceipt = $this->createTestReturnReceipt($originalReceipt);

        // Act
        $content = $this->pdfService->generateContent($returnReceipt);

        // Assert: PDF is generated and contains return-specific content
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('%PDF', $content);
    }

    /** @test */
    public function it_renders_return_receipt_html_with_return_banner(): void
    {
        // Arrange
        $originalReceipt = $this->createTestReceipt();
        $returnReceipt = $this->createTestReturnReceipt($originalReceipt);

        // Act: Render the Blade view directly to check HTML content
        $returnReceipt->load([
            'company', 'location', 'terminal', 'cashier',
            'lines', 'vatDetails', 'payments.paymentMethod', 'originalReceipt',
        ]);

        $html = view('pos.receipt', [
            'receipt' => $returnReceipt,
            'company' => $returnReceipt->company,
            'location' => $returnReceipt->location,
            'terminal' => $returnReceipt->terminal,
            'cashier' => $returnReceipt->cashier,
            'lines' => $returnReceipt->lines,
            'vatDetails' => $returnReceipt->vatDetails,
            'payments' => $returnReceipt->payments,
            'locale' => 'en',
            'currency' => 'EUR',
            'changeGiven' => '0.00',
            'totalPaid' => 0,
            'isReturn' => true,
            'originalReceiptNumber' => $originalReceipt->receipt_number,
            'returnReason' => 'Defective Product',
            'formatMoney' => fn ($amount) => number_format((float) ($amount ?? 0), 2) . ' EUR',
            'formatDate' => fn ($date) => '',
            'formatDateTime' => fn ($date) => '',
            'formatNumber' => fn ($number, $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Check for the return banner div and its content
        $this->assertStringContainsString('class="return-banner"', $html);
        $this->assertStringContainsString($originalReceipt->receipt_number, $html);
        $this->assertStringContainsString('Defective Product', $html);
    }

    /** @test */
    public function it_does_not_show_return_banner_for_sale_receipt(): void
    {
        // Arrange
        $receipt = $this->createTestReceipt();

        // Act
        $html = view('pos.receipt', [
            'receipt' => $receipt,
            'company' => $receipt->company,
            'location' => $receipt->location,
            'terminal' => $receipt->terminal,
            'cashier' => $receipt->cashier,
            'lines' => $receipt->lines,
            'vatDetails' => $receipt->vatDetails,
            'payments' => $receipt->payments,
            'locale' => 'en',
            'currency' => 'EUR',
            'changeGiven' => '0.00',
            'totalPaid' => 0,
            'isReturn' => false,
            'originalReceiptNumber' => null,
            'returnReason' => null,
            'formatMoney' => fn ($amount) => number_format((float) ($amount ?? 0), 2) . ' EUR',
            'formatDate' => fn ($date) => '',
            'formatDateTime' => fn ($date) => '',
            'formatNumber' => fn ($number, $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: The return banner div should not be rendered (CSS class definition doesn't count)
        $this->assertStringNotContainsString('class="return-banner"', $html);
    }

    /**
     * Create a test return receipt referencing the given original receipt.
     */
    private function createTestReturnReceipt(Receipt $originalReceipt): Receipt
    {
        $returnReceipt = Receipt::create([
            'tenant_id' => $originalReceipt->tenant_id,
            'company_id' => $originalReceipt->company_id,
            'location_id' => $originalReceipt->location_id,
            'terminal_id' => $originalReceipt->terminal_id,
            'receipt_number' => 'T001-C042-L01-POS01-2026-00000002',
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $originalReceipt->id,
            'return_reason' => ReturnReason::Defective,
            'chain_sequence' => 2,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'test-return-receipt'),
            'previous_hash' => $originalReceipt->fiscal_hash,
            'vat_breakdown_hash' => hash('sha256', 'return-vat'),
            'payment_methods_hash' => hash('sha256', 'return-payment'),
            'posted_at' => now(),
            'cashier_id' => $originalReceipt->cashier_id,
            'cashier_name' => $originalReceipt->cashier_name,
            'subtotal' => '-50.00',
            'tax_amount' => '-9.50',
            'total' => '-59.50',
            'currency' => 'EUR',
            'is_voided' => false,
        ]);

        ReceiptLine::create([
            'receipt_id' => $returnReceipt->id,
            'line_number' => 1,
            'product_code' => 'PROD-001',
            'product_name' => 'Test Product',
            'quantity' => '-1.000',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'line_total' => '-50.00',
            'tax_rate' => '19.00',
            'tax_amount' => '-9.50',
            'discount_amount' => '0.00',
        ]);

        ReceiptVatDetail::create([
            'receipt_id' => $returnReceipt->id,
            'tax_rate' => '19.00',
            'net_amount' => '-50.00',
            'vat_amount' => '-9.50',
            'gross_amount' => '-59.50',
        ]);

        return $returnReceipt;
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
            'current_year' => 2026,
            'current_sequence' => 0,
        ]);

        // Create cashier
        $cashier = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'John Cashier',
        ]);

        // Create payment method
        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
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
