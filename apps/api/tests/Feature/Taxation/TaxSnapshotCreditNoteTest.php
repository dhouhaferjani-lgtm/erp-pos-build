<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integration tests for tax snapshot creation on credit note confirmation.
 *
 * REVIVED (2026-08-03 gate V2, docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md):
 * this class used to `markTestSkipped` wholesale and its one live assertion
 * (`tax_base == '-100.00'`) encoded a NEGATIVE-storage convention the real
 * CreditNoteService never implements (it writes positive quantities/unit
 * prices — CreditNoteService::materializeLinesFromAllocation()). The gate's
 * ORCHESTRATOR RULING: credit notes stay POSITIVE in storage (the immutable
 * snapshot convention is preserved — a document_tax_details row always
 * reads as "this much base/tax on this document") and are negated at
 * AGGREGATION time by EloquentVatDataRepository instead. This class now
 * drives the REAL confirm() HTTP endpoint (CreditNoteService's actual
 * materialization path) and verifies both halves of that ruling.
 */
class TaxSnapshotCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        $this->tenant = Tenant::create([
            'name' => 'CN Tax Snapshot Tenant',
            'slug' => 'cn-tax-snapshot-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'CN Tax Snapshot Company',
            'legal_name' => 'CN Tax Snapshot Company SARL',
            'tax_id' => 'TAX999',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TunisiaTaxConfigurationSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'CN Test User',
            'email' => 'cn-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['credit-notes.view', 'credit-notes.create', 'invoices.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'CN Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
    }

    /**
     * V2: an amount-based credit note confirmed through the REAL
     * CreditNoteController::confirm() -> TaxCalculationService ->
     * snapshotTaxDetails() path (the same producer every document type
     * shares) must store POSITIVE tax_base/tax_amount, and
     * EloquentVatDataRepository must be the layer that turns that into a
     * NEGATIVE contribution to the declared OUTPUT base/VAT — exactly the
     * credit note's own amounts, no more, no less. The source invoice is
     * created directly as Posted (never confirmed), so it carries NO
     * document_tax_details rows and cannot itself pollute the aggregation —
     * isolating this test to the credit note's contribution alone.
     */
    public function test_confirmed_credit_note_stores_positive_and_aggregation_negates_it_by_exactly_its_amount(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-CN-9001',
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Widget',
            'quantity' => '1.0000',
            'unit_price' => '99.000',
            'tax_rate' => '19.00',
            'line_total' => '99.000',
        ]);
        $invoice->update([
            'subtotal' => '99.000',
            'tax_amount' => '19.810',
            'total' => '118.810',
            'balance_due' => '118.810',
        ]);

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '50.000',
            'reason' => 'return',
        ]);
        $create->assertCreated();
        $creditNoteId = $create->json('data.id');

        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();

        $details = DocumentTaxDetail::where('document_id', $creditNoteId)
            ->orderBy('sequence_order')
            ->get();
        $this->assertGreaterThan(0, $details->count(), 'Credit note confirm must snapshot tax details');

        $vatDetail = $details->firstWhere('is_stamp_duty', false);
        $this->assertNotNull($vatDetail, 'Credit note must snapshot a non-stamp VAT row');
        /** @var numeric-string $vatBase */
        $vatBase = (string) $vatDetail->tax_base;
        /** @var numeric-string $vatAmount */
        $vatAmount = (string) $vatDetail->tax_amount;
        // POSITIVE storage — the ruling: never sign-overload the immutable
        // snapshot row, negate at aggregation instead.
        $this->assertTrue(bccomp($vatBase, '0', 3) > 0, 'tax_base must be stored POSITIVE on a credit note');
        $this->assertTrue(bccomp($vatAmount, '0', 3) > 0, 'tax_amount must be stored POSITIVE on a credit note');

        $stampDetail = $details->firstWhere('is_stamp_duty', true);
        if ($stampDetail !== null) {
            /** @var numeric-string $stampAmount */
            $stampAmount = (string) $stampDetail->tax_amount;
            $this->assertTrue(bccomp($stampAmount, '0', 3) > 0, 'Stamp duty must also be stored POSITIVE');
        }

        // Aggregation: the invoice carries no document_tax_details rows (it
        // was posted directly, never confirmed), so the ONLY contribution
        // to this rate bucket is the credit note's -- the declared OUTPUT
        // base/VAT for this rate must be exactly NEGATIVE the credit note's
        // own stored (positive) amounts.
        $repository = app(VatDataRepositoryInterface::class);
        $aggregations = $repository->aggregateByRateAndDirection(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        $vatRate = (string) $vatDetail->tax_rate;
        $bucket = collect($aggregations)->first(fn ($a) => $a->direction === 'OUTPUT' && $a->taxRate === $vatRate);
        $this->assertNotNull($bucket, "Expected an OUTPUT bucket at rate {$vatRate}");

        $expectedBase = bcmul('-1', $vatBase, 3);
        $expectedVat = bcmul('-1', $vatAmount, 3);
        $this->assertSame($expectedBase, $bucket->baseAmount, 'Declared OUTPUT base must be reduced by EXACTLY the credit note amount');
        $this->assertSame($expectedVat, $bucket->vatAmount, 'Declared OUTPUT VAT must be reduced by EXACTLY the credit note amount');
    }
}
