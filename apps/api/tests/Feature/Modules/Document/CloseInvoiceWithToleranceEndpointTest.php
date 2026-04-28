<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3 / Task 12 — POST /api/v1/invoices/{invoice}/close-with-tolerance.
 *
 * Full HTTP path with real GeneralLedgerService posting (chart of accounts
 * seeded inline). Verifies happy path, structured 422 errors for
 * TOLERANCE_EXCEEDED / ALREADY_PAID, 403 on missing payments.allocate, and
 * that the journal entry balances (Dr 658 == Cr AR).
 */
final class CloseInvoiceWithToleranceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $authorizedUser;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'tenant-close-tolerance',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);
        CountryPaymentSettings::create([
            'country_code' => 'FR',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company SAS',
            'tax_id' => 'FR123',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->authorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Authorized User',
            'email' => 'authorized@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->authorizedUser->givePermissionTo('payments.allocate');
        UserCompanyMembership::create([
            'user_id' => $this->authorizedUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->unauthorizedUser->givePermissionTo('invoices.view');
        UserCompanyMembership::create([
            'user_id' => $this->unauthorizedUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_successful_close_returns_invoice_with_writeoff_meta(): void
    {
        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('meta.tolerance_writeoff.amount', '0.300')
            ->assertJsonStructure([
                'data',
                'meta' => [
                    'tolerance_writeoff' => ['amount', 'gl_entry_id'],
                    'timestamp',
                ],
            ]);

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertSame('0.000', (string) $invoice->balance_due);

        Event::assertDispatched(InvoiceClosedWithTolerance::class);
    }

    public function test_journal_entry_is_balanced_dr_658_cr_ar(): void
    {
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertOk();

        $entry = JournalEntry::where('source_type', 'payment_tolerance')
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        /** @var iterable<JournalLine> $lines */
        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $line) {
            $totalDebit = bcadd($totalDebit, (string) $line->debit, 4);
            $totalCredit = bcadd($totalCredit, (string) $line->credit, 4);
        }
        $this->assertSame(0, bccomp($totalDebit, '0.3000', 4), 'Debit total should equal write-off amount.');
        $this->assertSame(0, bccomp($totalCredit, '0.3000', 4), 'Credit total should equal write-off amount.');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 4), 'Journal entry must balance.');
    }

    public function test_returns_422_when_balance_over_tolerance(): void
    {
        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(total: '105.000', balance: '5.000');

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOLERANCE_EXCEEDED')
            ->assertJsonPath('error.details.remaining_balance', '5.000');

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Posted, $invoice->status);
        $this->assertSame('5.000', (string) $invoice->balance_due);
        Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
    }

    public function test_returns_422_when_invoice_already_paid(): void
    {
        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(
            total: '100.000',
            balance: '0.000',
            status: DocumentStatus::Paid,
        );

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_PAID');

        Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
    }

    public function test_returns_403_when_user_lacks_payments_allocate(): void
    {
        Event::fake([InvoiceClosedWithTolerance::class]);
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $this->actingAs($this->unauthorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(403);

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Posted, $invoice->status);
        $this->assertSame('0.300', (string) $invoice->balance_due);
        Event::assertNotDispatched(InvoiceClosedWithTolerance::class);
    }

    public function test_returns_404_when_invoice_does_not_exist(): void
    {
        $this->actingAs($this->authorizedUser)
            ->postJson('/api/v1/invoices/00000000-0000-0000-0000-000000000000/close-with-tolerance')
            ->assertStatus(404);
    }

    public function test_returns_422_invalid_status_when_invoice_is_confirmed_not_posted(): void
    {
        $invoice = $this->seedPostedInvoice(
            total: '100.300',
            balance: '0.300',
            status: DocumentStatus::Confirmed,
        );

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_STATUS')
            ->assertJsonPath('error.details.status', 'confirmed');

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Confirmed, $invoice->status);
        $this->assertSame('0.300', (string) $invoice->balance_due);
    }

    public function test_emits_audit_event_row_for_close_with_tolerance(): void
    {
        $invoice = $this->seedPostedInvoice(total: '100.300', balance: '0.300');

        $this->actingAs($this->authorizedUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/close-with-tolerance")
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('event_type', 'invoice.closed_with_tolerance')
            ->where('aggregate_id', $invoice->id)
            ->first();

        $this->assertNotNull($audit, 'A compliance audit_events row must be persisted by DomainEventSubscriber.');
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame('Document', $audit->aggregate_type);
        $this->assertSame((string) $this->authorizedUser->id, (string) $audit->user_id);
    }

    private function seedPostedInvoice(
        string $total,
        string $balance,
        DocumentStatus $status = DocumentStatus::Posted,
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => $status,
            'document_number' => 'INV-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $balance,
        ]);
    }
}
