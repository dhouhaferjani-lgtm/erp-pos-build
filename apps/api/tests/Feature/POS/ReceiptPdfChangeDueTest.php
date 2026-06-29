<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies ReceiptPdfService renders the persisted change_due value (BG6).
 */
final class ReceiptPdfChangeDueTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_uses_stored_change_due_when_present(): void
    {
        $receipt = $this->makeReceipt(total: '20.000', tendered: '25.000', changeDue: '5.000');
        $paymentMethod = $this->makePaymentMethod($receipt);
        $receipt->payments()->create([
            'id' => Str::uuid()->toString(),
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'CASH',
            'amount' => '20.000',
        ]);

        $changeGiven = $this->invokePrepareData(
            $receipt->fresh(['payments', 'company', 'location', 'terminal', 'cashier', 'lines', 'vatDetails'])
        );

        // Stored value wins over computation (payments=20 != total=20, but stored=5).
        $this->assertSame('5.00', $changeGiven);
    }

    public function test_pdf_falls_back_to_computed_change_when_stored_is_null(): void
    {
        // Legacy row: change_due is null, but payments exceed total.
        $receipt = $this->makeReceipt(total: '20.000', tendered: '25.000', changeDue: null);
        $paymentMethod = $this->makePaymentMethod($receipt);
        $receipt->payments()->create([
            'id' => Str::uuid()->toString(),
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'CASH',
            'amount' => '25.000',
        ]);

        $changeGiven = $this->invokePrepareData(
            $receipt->fresh(['payments', 'company', 'location', 'terminal', 'cashier', 'lines', 'vatDetails'])
        );

        // Computed change = 25 - 20 = 5.000
        $this->assertSame('5.00', $changeGiven);
    }

    /**
     * When total_paid == total exactly (no stored change_due), change must be
     * '0.00' — the bccomp guard must NOT trigger bcsub and return a spurious value.
     */
    public function test_pdf_computes_zero_change_when_total_paid_equals_total(): void
    {
        $receipt = $this->makeReceipt(total: '20.000', tendered: '20.000', changeDue: null);
        $paymentMethod = $this->makePaymentMethod($receipt);
        $receipt->payments()->create([
            'id' => Str::uuid()->toString(),
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'CASH',
            'amount' => '20.000',
        ]);

        $changeGiven = $this->invokePrepareData(
            $receipt->fresh(['payments', 'company', 'location', 'terminal', 'cashier', 'lines', 'vatDetails'])
        );

        $this->assertSame('0.00', $changeGiven);
    }

    public function test_pdf_renders_content_without_error_for_stored_change_due(): void
    {
        // Sanity: generate the actual PDF bytes end-to-end to ensure the new
        // CurrencyScale::bcformat code path doesn't blow up during render.
        $receipt = $this->makeReceipt(total: '20.000', tendered: '25.000', changeDue: '5.000');
        $paymentMethod = $this->makePaymentMethod($receipt);
        $receipt->payments()->create([
            'id' => Str::uuid()->toString(),
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'CASH',
            'amount' => '20.000',
        ]);

        /** @var ReceiptPdfService $pdfService */
        $pdfService = app(ReceiptPdfService::class);
        $content = $pdfService->generateContent(
            $receipt->fresh(['payments', 'company', 'location', 'terminal', 'cashier', 'lines', 'vatDetails'])
        );

        // PDFs start with %PDF- magic header.
        $this->assertSame('%PDF-', substr($content, 0, 5));
        $this->assertGreaterThan(1000, strlen($content), 'Rendered PDF should be non-trivial');
    }

    /**
     * Use reflection to invoke the private prepareData() method and return changeGiven.
     */
    private function invokePrepareData(Receipt $receipt): string
    {
        /** @var ReceiptPdfService $pdfService */
        $pdfService = app(ReceiptPdfService::class);
        $method = new ReflectionMethod($pdfService, 'prepareData');
        /** @var array<string, mixed> $data */
        $data = $method->invoke($pdfService, $receipt, $receipt->company, 1);

        return (string) $data['changeGiven'];
    }

    private function makeReceipt(string $total, string $tendered, ?string $changeDue): Receipt
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $this->app->make(CompanyContext::class)->setCompanyId($company->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        return Receipt::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => 'TEST-'.uniqid(),
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => 1,
            'receipt_year' => (int) now()->format('Y'),
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'test'),
            'posted_at' => now(),
            'cashier_id' => $user->id,
            'cashier_name' => $user->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => $total,
            'currency' => $company->currency ?? 'TND',
            'change_due' => $changeDue,
            'fiscal_status' => 'fiscalized',
            'is_voided' => false,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pm'),
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    private function makePaymentMethod(Receipt $receipt): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'company_id' => $receipt->company_id,
            'tenant_id' => $receipt->tenant_id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);
    }
}
