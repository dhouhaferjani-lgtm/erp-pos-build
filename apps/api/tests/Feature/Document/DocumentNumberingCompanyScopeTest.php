<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

#[UsesFrozenSeederFixture]
final class DocumentNumberingCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Partner $partnerA;

    private Partner $partnerB;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Document Number Scope Tenant',
            'slug' => 'document-number-scope',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->companyA = $this->createCompany('A');
        $this->companyB = $this->createCompany('B');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Number Operator',
            'email' => 'document-number-operator@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.post',
        ]);

        foreach ([$this->companyA, $this->companyB] as $company) {
            UserCompanyMembership::create([
                'user_id' => $this->user->id,
                'company_id' => $company->id,
                'role' => 'admin',
            ]);

            (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        }

        $this->partnerA = $this->createPartner($this->companyA, 'A');
        $this->partnerB = $this->createPartner($this->companyB, 'B');
    }

    public function test_each_company_posts_its_first_invoice_without_burning_a_number(): void
    {
        $invoiceA = $this->createConfirmAndPostInvoice($this->companyA, $this->partnerA);
        $invoiceB = $this->createConfirmAndPostInvoice($this->companyB, $this->partnerB);
        $expected = sprintf('INV-%d-0001', (int) date('Y'));

        $this->assertSame($expected, (string) $invoiceA->refresh()->document_number);
        $this->assertSame($expected, (string) $invoiceB->refresh()->document_number);
        $this->assertSame(DocumentStatus::Posted, $invoiceA->status);
        $this->assertSame(DocumentStatus::Posted, $invoiceB->status);

        foreach ([$this->companyA, $this->companyB] as $company) {
            $this->assertSame(
                1,
                (int) DocumentSequence::query()
                    ->where('company_id', $company->id)
                    ->where('type', DocumentType::Invoice->value)
                    ->where('year', (int) date('Y'))
                    ->value('last_number'),
                'Each legal entity must consume exactly its own first invoice number.',
            );
        }
    }

    private function createCompany(string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Number Scope Company '.$suffix,
            'legal_name' => 'Number Scope Company '.$suffix.' LLC',
            'tax_id' => 'NUMBER-SCOPE-'.$suffix,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createPartner(Company $company, string $suffix): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Number Scope Customer '.$suffix,
            'type' => PartnerType::Customer,
        ]);
    }

    private function createConfirmAndPostInvoice(Company $company, Partner $partner): Document
    {
        app(CompanyContext::class)->setCompanyId($company->id);

        $create = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/invoices', [
                'partner_id' => $partner->id,
                'document_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'EUR',
                'lines' => [[
                    'description' => 'Company-scoped numbering service',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                    'tax_rate' => '20.00',
                ]],
            ]);
        $create->assertCreated();

        $invoiceId = $create->json('data.id');
        $this->assertIsString($invoiceId);

        $this->withHeader('X-Company-Id', $company->id)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm")
            ->assertOk();

        $this->withHeader('X-Company-Id', $company->id)
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertOk();

        return Document::query()->findOrFail($invoiceId);
    }
}
