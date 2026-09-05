<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * F-STG-4 (owner-confirmed paid-invoice scope) — a fully-PAID invoice is a
 * posted invoice that has since been settled (its `DocumentStatus` flips from
 * `Posted` to `Paid`). Returning goods against it is a real-world need: the
 * credit note becomes a customer credit / refund. Previously the source picker
 * excluded paid invoices AND `CreditNoteService` hard-rejected any invoice whose
 * status was not exactly `Posted`, so even a hand-crafted request 422'd.
 *
 * These tests pin the two backend halves of the fix:
 *   1. `createCreditNote` / `createLineBasedCreditNote` accept a Paid source.
 *   2. `GET /invoices?creditable=true` surfaces Posted AND Paid invoices (the
 *      picker's new query) while still excluding drafts.
 */
class CreditNotePaidInvoiceSourceTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    private function makeInvoice(DocumentStatus $status, string $number, string $balanceDue): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => $status,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '1000.00',
            'tax_amount' => '200.00',
            'total' => '1200.00',
            'balance_due' => $balanceDue,
            'currency' => 'EUR',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '10',
            'unit_price' => '100.00',
            'tax_rate' => '20',
            'line_total' => '1000.00',
        ]);

        return $invoice->load('lines');
    }

    public function test_amount_based_credit_note_can_be_created_from_a_paid_invoice(): void
    {
        // Fully paid → status Paid, zero balance.
        $invoice = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID-1', '0.00');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1200.00',
                'reason' => 'return',
                'notes' => 'Return after full payment',
            ]);

        $response->assertCreated();
    }

    public function test_line_based_credit_note_can_be_created_from_a_paid_invoice(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID-2', '0.00');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'lines' => [
                    ['line_id' => $invoice->lines->first()->id, 'quantity' => '10'],
                ],
                'reason' => 'return',
            ]);

        $response->assertCreated();
    }

    public function test_credit_note_still_rejects_a_draft_source_invoice(): void
    {
        $invoice = $this->makeInvoice(DocumentStatus::Draft, 'INV-DRAFT-1', '1200.00');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1200.00',
                'reason' => 'return',
            ]);

        $response->assertUnprocessable();
    }

    public function test_creditable_filter_returns_posted_and_paid_but_not_draft_invoices(): void
    {
        $posted = $this->makeInvoice(DocumentStatus::Posted, 'INV-POSTED', '1200.00');
        $paid = $this->makeInvoice(DocumentStatus::Paid, 'INV-PAID', '0.00');
        $draft = $this->makeInvoice(DocumentStatus::Draft, 'INV-DRAFT', '1200.00');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/invoices?creditable=true&per_page=50');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($posted->id, $ids);
        $this->assertContains($paid->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }
}
