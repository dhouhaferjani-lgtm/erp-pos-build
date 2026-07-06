<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\RefundService;
use App\Modules\Document\Domain\Services\SalesOrderService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DocumentCancelConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Document Cancel Tenant',
            'slug' => 'document-cancel-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Cancel Company',
            'legal_name' => 'Document Cancel Company SARL',
            'tax_id' => 'DC-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Cancel User',
            'email' => 'cancel@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'CANCEL-WH',
            'name' => 'Cancel Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cancel Partner',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_paid_posted_invoice_cancel_endpoint_returns_document_has_payments(): void
    {
        $invoice = $this->postedInvoice();
        $this->allocatePayment($invoice, '25.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer request',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS');
    }

    public function test_credit_note_allocated_posted_invoice_cancel_endpoint_returns_document_has_payments(): void
    {
        $invoice = $this->postedInvoice();
        $creditNote = $this->document(DocumentType::CreditNote, DocumentStatus::Posted);
        CreditNoteAllocation::create([
            'credit_note_id' => $creditNote->id,
            'invoice_id' => $invoice->id,
            'amount' => '10.000',
            'allocated_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Credit note already allocated',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS');
    }

    public function test_tolerance_writeoff_closed_invoice_cancel_endpoint_returns_document_has_payments(): void
    {
        $invoice = $this->postedInvoice();
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $invoice->id,
            'amount' => '0.300',
            'tolerance_writeoff' => '0.3000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Tolerance writeoff already closed',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS');
    }

    public function test_check_cancellable_returns_true_for_unallocated_posted_invoice_and_false_for_allocated(): void
    {
        $unallocated = $this->postedInvoice();
        $allocated = $this->postedInvoice();
        $this->allocatePayment($allocated, '25.000');

        $trueResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$unallocated->id}/can-cancel");
        $falseResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$allocated->id}/can-cancel");

        $trueResponse->assertOk()
            ->assertJsonPath('data.can_cancel', true);
        $falseResponse->assertOk()
            ->assertJsonPath('data.can_cancel', false);
    }

    public function test_cancel_invoice_endpoint_stamps_cancelled_by_user(): void
    {
        $invoice = $this->postedInvoice();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Duplicate',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documents', [
            'id' => $invoice->id,
            'cancelled_by' => $this->user->id,
        ]);
    }

    public function test_unpaid_posted_invoice_cancel_pin_voids_fiscal_status(): void
    {
        $invoice = $this->postedInvoice();

        $cancelled = app(DocumentPostingService::class)->cancel($invoice);

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame(FiscalStatus::Voided, $cancelled->fiscal_status);
    }

    public function test_sales_order_cancel_pin_releases_reservations(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $salesOrder = $this->document(DocumentType::SalesOrder, DocumentStatus::Confirmed);
        $line = DocumentLine::create([
            'document_id' => $salesOrder->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '2.0000',
            'unit_price' => '10.000',
            'line_total' => '20.000',
            'allocated_costs' => '0.000000',
        ]);
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '5.0000',
            'reserved' => '2.0000',
        ]);
        $reservation = StockReservation::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '2.0000',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => $salesOrder->id,
            'source_line_id' => $line->id,
            'priority' => 0,
        ]);

        $cancelled = app(SalesOrderService::class)->cancel($salesOrder, 'Customer cancelled', $this->user->id);

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($reservation->fresh()?->released_at);
        $this->assertSame(ReleaseReason::Cancelled, $reservation->fresh()?->release_reason);
        $this->assertSame('0.0000', (string) StockLevel::query()->firstOrFail()->reserved);
    }

    public function test_refund_service_cancel_invoice_pin_keeps_current_payload_behavior(): void
    {
        $invoice = $this->document(DocumentType::Invoice, DocumentStatus::Confirmed);

        $cancelled = app(RefundService::class)->cancelInvoice($invoice, 'Duplicate invoice');

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame('Duplicate invoice', $cancelled->payload['cancellation_reason'] ?? null);
        $this->assertNotEmpty($cancelled->payload['cancelled_at'] ?? null);
    }

    public function test_refund_service_cancel_credit_note_pin_keeps_current_payload_behavior(): void
    {
        $creditNote = $this->document(DocumentType::CreditNote, DocumentStatus::Confirmed);

        $cancelled = app(RefundService::class)->cancelCreditNote($creditNote, 'Issued by mistake');

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame('Issued by mistake', $cancelled->payload['cancellation_reason'] ?? null);
        $this->assertNotEmpty($cancelled->payload['cancelled_at'] ?? null);
    }

    private function postedInvoice(): Document
    {
        Event::fake([InvoicePosted::class, InvoiceCancelled::class]);
        $invoice = $this->document(DocumentType::Invoice, DocumentStatus::Confirmed);

        return app(DocumentPostingService::class)->post($invoice);
    }

    private function document(DocumentType $type, DocumentStatus $status): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => $status,
            'document_number' => strtoupper($type->value).'-CANCEL-'.uniqid(),
            'document_date' => now()->toDateString(),
            'confirmed_at' => $status === DocumentStatus::Confirmed ? now() : null,
            'confirmed_by' => $status === DocumentStatus::Confirmed ? $this->user->id : null,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
    }

    private function allocatePayment(Document $document, string $amount): void
    {
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-CANCEL-001',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $document->id,
            'amount' => $amount,
        ]);
    }
}
