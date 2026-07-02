<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class DemoPharmacySeederSalesInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private const MONEY_SCALE = 3;

    public function test_seed_tunisia_sales_invoices_posts_invoice_and_payment_gl_entries(): void
    {
        [$company, $shops] = $this->seedSalesInvoiceFixture();

        $this->invokeSeedTunisiaSalesInvoices($company, $shops);

        $invoices = Document::query()
            ->where('company_id', $company->id)
            ->where('type', DocumentType::Invoice)
            ->where('document_number', 'like', 'DEMO-INV-%')
            ->get();

        $this->assertCount(10, $invoices);

        foreach ($invoices as $invoice) {
            $entry = JournalEntry::query()
                ->where('company_id', $company->id)
                ->where('source_type', 'invoice')
                ->where('source_id', $invoice->id)
                ->with('lines')
                ->first();

            $this->assertNotNull($entry, "Missing invoice GL entry for {$invoice->document_number}.");
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);
            $this->assertJournalEntryBalances($entry);
        }

        $payments = Payment::query()
            ->where('company_id', $company->id)
            ->where('reference', 'like', 'DEMO-PAY-%')
            ->get();

        $this->assertNotEmpty($payments);

        foreach ($payments as $payment) {
            $entry = JournalEntry::query()
                ->where('company_id', $company->id)
                ->where('source_type', 'customer_payment')
                ->where('source_id', $payment->id)
                ->with('lines')
                ->first();

            $this->assertNotNull($entry, "Missing payment GL entry for {$payment->reference}.");
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);
            $this->assertJournalEntryBalances($entry);
        }

        $postedLines = JournalLine::query()
            ->whereHas('journalEntry', fn ($query) => $query
                ->whereRaw('company_id = ?', [$company->id])
                ->whereRaw('status = ?', [JournalEntryStatus::Posted->value]))
            ->get();

        $debits = '0.000';
        $credits = '0.000';
        foreach ($postedLines as $line) {
            $debits = bcadd($debits, $line->debit, self::MONEY_SCALE);
            $credits = bcadd($credits, $line->credit, self::MONEY_SCALE);
        }

        $this->assertSame($debits, $credits);
    }

    /**
     * @return array{Company, array<int, Location>}
     */
    private function seedSalesInvoiceFixture(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'demo-pharmacy-tn',
        ]);
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@pharmabio.tn',
        ]);

        $cashAccount = $this->createAccount($tenant, $company, '54', AccountType::Asset, SystemAccountPurpose::Cash);
        $this->createAccount($tenant, $company, '532', AccountType::Asset, SystemAccountPurpose::Bank);
        $this->createAccount($tenant, $company, '411', AccountType::Asset, SystemAccountPurpose::CustomerReceivable);
        $this->createAccount($tenant, $company, '707', AccountType::Revenue, SystemAccountPurpose::ProductRevenue);
        $this->createAccount($tenant, $company, '4367', AccountType::Liability, SystemAccountPurpose::VatCollected);

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'balance' => '5000.000',
        ]);

        $shops = [];
        foreach (['STORE-TUN1', 'STORE-TUN2', 'STORE-SOU', 'STORE-SFA'] as $code) {
            $shops[] = Location::factory()->create([
                'company_id' => $company->id,
                'code' => $code,
                'type' => LocationType::Shop,
                'address_country' => 'TN',
            ]);
        }

        for ($i = 1; $i <= 24; $i++) {
            Product::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'name' => 'Demo product '.$i,
                'sku' => 'DEMO-SKU-'.$i,
                'is_physical' => true,
                'sale_price' => '12.000',
                'requires_batch_tracking' => false,
            ]);
        }

        $this->app->make(CompanyContext::class)->setCompanyId($company->id);

        return [$company, $shops];
    }

    private function createAccount(
        Tenant $tenant,
        Company $company,
        string $code,
        AccountType $type,
        ?SystemAccountPurpose $purpose,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => $code,
            'type' => $type,
            'system_purpose' => $purpose,
        ]);
    }

    /**
     * @param  array<int, Location>  $shops
     */
    private function invokeSeedTunisiaSalesInvoices(Company $company, array $shops): void
    {
        /** @var DemoPharmacySeeder $seeder */
        $seeder = $this->app->make(DemoPharmacySeeder::class);
        $command = new class extends Command
        {
            protected $signature = 'test:demo-pharmacy-seeder-sales-invoices';
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        $seeder->setContainer($this->app);
        $seeder->setCommand($command);

        $method = new ReflectionMethod($seeder, 'seedTunisiaSalesInvoices');
        $method->setAccessible(true);
        $method->invoke($seeder, $company, $shops);
    }

    private function assertJournalEntryBalances(JournalEntry $entry): void
    {
        $debits = '0.000';
        $credits = '0.000';
        foreach ($entry->lines as $line) {
            $debits = bcadd($debits, $line->debit, self::MONEY_SCALE);
            $credits = bcadd($credits, $line->credit, self::MONEY_SCALE);
        }

        $this->assertSame($debits, $credits);
    }
}
