<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Pre-deploy data audit (spec §15) — verifies that the
 * `tolerance:audit-discounts` command correctly flags sub-tolerance
 * discounts on existing Sales-Order / Invoice rows without modifying any
 * data. The command's exit code is the contract: non-zero on violations
 * (so CI pipelines can gate), zero with --dry-run regardless.
 */
final class AuditDiscountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        CountryPaymentSettings::firstOrCreate(
            ['country_code' => 'FR'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0050',
                'max_payment_tolerance_amount' => '0.500',
            ],
        );

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_command_succeeds_on_clean_dataset(): void
    {
        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('tolerance:audit-discounts');
        $cmd->expectsOutputToContain('no violations')->assertExitCode(0);
    }

    public function test_command_flags_sub_tolerance_line_discount_with_failure_exit(): void
    {
        $invoice = $this->seedInvoice();
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Service line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            // €0.20 below €0.50 margin
            'discount_amount' => '0.20',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('tolerance:audit-discounts');
        $cmd->expectsOutputToContain('LINE')->assertExitCode(1);
    }

    public function test_dry_run_returns_zero_even_with_violations(): void
    {
        $invoice = $this->seedInvoice();
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Service line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'discount_amount' => '0.20',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('tolerance:audit-discounts', ['--dry-run' => true]);
        $cmd->expectsOutputToContain('LINE')->assertExitCode(0);
    }

    public function test_command_flags_header_only_discount_violation(): void
    {
        $invoice = $this->seedInvoice();
        $invoice->update(['discount_amount' => '0.20']);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('tolerance:audit-discounts');
        $cmd->expectsOutputToContain('HEADER')->assertExitCode(1);
    }

    public function test_command_ignores_quote_documents(): void
    {
        // Quote-typed documents are deliberately out of scope: the rule
        // doesn't fire at quote create-time and the conversion path
        // strips at promotion time.
        /** @phpstan-ignore-next-line argument.type */
        $quote = Document::create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Quote),
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-AUDIT-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'discount_amount' => '0.10', // sub-tolerance, but quotes are out of scope
        ]);

        DocumentLine::create([
            'document_id' => $quote->id,
            'line_number' => 1,
            'description' => 'Service line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'discount_amount' => '0.10',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('tolerance:audit-discounts');
        $cmd->expectsOutputToContain('no violations')->assertExitCode(0);
    }

    private function seedInvoice(): Document
    {
        /** @phpstan-ignore-next-line argument.type */
        $doc = Document::create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-AUDIT-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        return $doc;
    }
}
