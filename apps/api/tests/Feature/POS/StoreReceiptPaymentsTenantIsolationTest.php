<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant-isolation regression for POS payments.
 *
 * Reproduces the exploit chain documented in
 * `memory/project_payment_method_tenant_isolation.md`: tenant B authenticated
 * to its own POS endpoint can submit tenant A's `payment_method_id`,
 * `repository_id`, or `customer_id` because the validator's bare `exists:`
 * rules and the service-layer `findOrFail()` calls are unscoped.
 *
 * Each test must reject the cross-tenant submission with a 4xx response and
 * leave the receipt unfiscalized with no payment row written.
 */
final class StoreReceiptPaymentsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private static int $receiptCounter = 0;

    private Tenant $tenantA;

    private Company $companyA;

    private PaymentMethod $methodA;

    private PaymentRepository $repoA;

    private Partner $partnerA;

    private Tenant $tenantB;

    private Company $companyB;

    private Location $locationB;

    private Terminal $terminalB;

    private PaymentMethod $methodB;

    private PaymentRepository $repoB;

    private User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);

        // ---- Tenant A: the "victim" tenant whose IDs we must NOT accept ----
        $this->tenantA = Tenant::factory()->create();
        $this->companyA = Company::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($this->companyA);
        $cashGlA = Account::query()
            ->where('company_id', $this->companyA->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();

        $this->methodA = PaymentMethod::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Cash A',
            'code' => 'cash',
        ]);
        $this->repoA = PaymentRepository::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Cash Drawer A',
            'code' => 'CASH-A',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGlA->id,
        ]);
        $this->partnerA = Partner::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
        ]);

        // ---- Tenant B: the "attacker" tenant authenticated against the API ----
        $this->tenantB = Tenant::factory()->create();
        $this->companyB = Company::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->locationB = Location::factory()->create(['company_id' => $this->companyB->id]);
        $this->terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'location_id' => $this->locationB->id,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->companyB);
        $cashGlB = Account::query()
            ->where('company_id', $this->companyB->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();

        $this->methodB = PaymentMethod::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Cash B',
            'code' => 'cash',
        ]);
        $this->repoB = PaymentRepository::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Cash Drawer B',
            'code' => 'CASH-B',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGlB->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashierB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashierB->id,
            'company_id' => $this->companyB->id,
            'role' => 'cashier',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->cashierB->givePermissionTo('pos.operate_terminal');

        Shift::create([
            'terminal_id' => $this->terminalB->id,
            'cashier_id' => $this->cashierB->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.000',
        ]);
    }

    public function test_cross_tenant_payment_method_id_is_rejected(): void
    {
        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                // Tenant A's payment_method_id submitted by tenant B's cashier.
                'payment_method_id' => $this->methodA->id,
                'repository_id' => $this->repoB->id,
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [404, 422],
            'Cross-tenant payment_method_id must be rejected; got '.$response->status().': '.$response->getContent(),
        );

        $receipt->refresh();
        $this->assertSame(
            FiscalStatus::PendingSeal,
            $receipt->fiscal_status,
            'Receipt must NOT advance to fiscalized when a cross-tenant payment_method_id was submitted',
        );
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
            'No ReceiptPayment row may be written for a cross-tenant submission',
        );
    }

    public function test_cross_tenant_repository_id_is_rejected(): void
    {
        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                // Tenant A's repository_id submitted by tenant B's cashier.
                'repository_id' => $this->repoA->id,
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [404, 422],
            'Cross-tenant repository_id must be rejected; got '.$response->status().': '.$response->getContent(),
        );

        $receipt->refresh();
        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
        );
    }

    public function test_cross_tenant_customer_id_is_rejected(): void
    {
        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                'repository_id' => $this->repoB->id,
            ]],
            // Tenant A's partner_id submitted as the customer for tenant B's sale.
            'customer_id' => $this->partnerA->id,
        ]);

        $this->assertContains(
            $response->status(),
            [404, 422],
            'Cross-tenant customer_id must be rejected; got '.$response->status().': '.$response->getContent(),
        );

        $receipt->refresh();
        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
        );
    }

    public function test_same_tenant_payment_succeeds_as_control(): void
    {
        // Positive control: the legitimate same-tenant shape MUST still succeed.
        // If this fails, the tenant-scoping fix is too aggressive.
        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                'repository_id' => $this->repoB->id,
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [200, 201],
            'Same-tenant payment must succeed; got '.$response->status().': '.$response->getContent(),
        );

        $this->assertSame(
            1,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
            'Exactly one ReceiptPayment row expected for the legitimate single-tender path',
        );
    }

    private function seedReceiptForTenantB(string $total): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'location_id' => $this->locationB->id,
            'terminal_id' => $this->terminalB->id,
            'receipt_number' => sprintf('T002-C001-L01-POS01-2026-%08d', ++self::$receiptCounter),
            'chain_sequence' => null,
            'receipt_year' => 2026,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.uniqid()),
            'payment_methods_hash' => hash('sha256', 'pay-'.uniqid()),
            'posted_at' => Carbon::now(),
            'cashier_id' => $this->cashierB->id,
            'cashier_name' => $this->cashierB->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
