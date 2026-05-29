<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Exceptions\GiftCardNotYetSupportedException;
use App\Modules\POS\Domain\Exceptions\RestaurantVoucherNotYetSupportedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 21 Phase 1 guard tests.
 *
 * Verifies that VoucherIssuanceService and VoucherRedemptionService explicitly
 * reject restaurant_voucher and gift_card instrument kinds. Only StoreVoucher
 * and None are wired in Phase 1.
 *
 * Also verifies the legacy path (writing pos_receipt_payments with instrument_serial
 * only, no service layer) still works — the migration preserves that path.
 *
 * Spec §3.2.1, §3.3.
 */
final class RestaurantVoucherRejectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-restaurant-voucher-rejection',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->issuer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Cashier',
            'email' => 'cashier-rejection@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
    }

    // -------------------------------------------------------------------------
    // VoucherIssuanceService — restaurant_voucher rejection
    // -------------------------------------------------------------------------

    public function test_issuance_service_rejects_restaurant_voucher_kind(): void
    {
        $this->expectException(RestaurantVoucherNotYetSupportedException::class);

        $request = new VoucherIssuanceRequest(
            amount: '50.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->issuer->id,
            instrumentKind: PaymentInstrumentKind::RestaurantVoucher,
        );

        app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    public function test_issuance_service_rejects_restaurant_voucher_from_exchange_surplus(): void
    {
        $this->expectException(RestaurantVoucherNotYetSupportedException::class);

        $request = new VoucherIssuanceRequest(
            amount: '25.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->issuer->id,
            instrumentKind: PaymentInstrumentKind::RestaurantVoucher,
        );

        app(VoucherIssuanceService::class)->issueFromExchangeSurplus($request);
    }

    public function test_issuance_service_rejects_gift_card_kind(): void
    {
        $this->expectException(GiftCardNotYetSupportedException::class);

        $request = new VoucherIssuanceRequest(
            amount: '100.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->issuer->id,
            instrumentKind: PaymentInstrumentKind::GiftCard,
        );

        app(VoucherIssuanceService::class)->issueFromRefund($request);
    }

    // -------------------------------------------------------------------------
    // VoucherRedemptionService — restaurant_voucher rejection
    // -------------------------------------------------------------------------

    public function test_redemption_service_rejects_restaurant_voucher_kind(): void
    {
        $this->expectException(RestaurantVoucherNotYetSupportedException::class);

        $request = new VoucherRedemptionRequest(
            voucherCode: 'POSC-1234-5678',
            appliedAmount: '10.00',
            currency: 'EUR',
            receiptId: '00000000-0000-0000-0000-000000000001',
            cashierId: $this->issuer->id,
            terminalId: '00000000-0000-0000-0000-000000000002',
            instrumentKind: PaymentInstrumentKind::RestaurantVoucher,
        );

        app(VoucherRedemptionService::class)->redeem($request);
    }

    public function test_redemption_service_rejects_gift_card_kind(): void
    {
        $this->expectException(GiftCardNotYetSupportedException::class);

        $request = new VoucherRedemptionRequest(
            voucherCode: 'POSC-1234-5679',
            appliedAmount: '20.00',
            currency: 'EUR',
            receiptId: '00000000-0000-0000-0000-000000000003',
            cashierId: $this->issuer->id,
            terminalId: '00000000-0000-0000-0000-000000000004',
            instrumentKind: PaymentInstrumentKind::GiftCard,
        );

        app(VoucherRedemptionService::class)->redeem($request);
    }

    // -------------------------------------------------------------------------
    // Legacy path: writing to pos_receipt_payments directly still works
    // (instrument_type = restaurant_voucher is still a valid DB value from
    // the migration backfill; it's only the service layer that rejects it)
    // -------------------------------------------------------------------------

    public function test_legacy_write_to_pos_receipt_payments_with_instrument_serial_succeeds(): void
    {
        // Scaffold a minimal FK chain so the receipt_id FK is satisfied.
        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->issuer->id,
            'receipt_type' => ReceiptType::Sale,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'previous_hash' => null,
            'chain_sequence' => null,
        ]);

        $pm = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Simulate the legacy path: writing a restaurant-voucher serial via the model (no service call).
        $payment = ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'RestaurantVoucher',
            'amount' => '8.500',
            'instrument_serial' => 'TICKET-REST-001',
            'instrument_type' => PaymentInstrumentKind::RestaurantVoucher,
        ]);

        $payment->refresh();
        $this->assertSame('TICKET-REST-001', $payment->instrument_serial);
        $this->assertSame(PaymentInstrumentKind::RestaurantVoucher, $payment->instrument_type);
    }

    // -------------------------------------------------------------------------
    // Store voucher issuance — ensure default instrument kind still works
    // -------------------------------------------------------------------------

    public function test_issuance_service_accepts_store_voucher_kind(): void
    {
        $request = new VoucherIssuanceRequest(
            amount: '30.00000',
            currency: 'EUR',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            issuedByUserId: $this->issuer->id,
            instrumentKind: PaymentInstrumentKind::StoreVoucher,
            redemptionMode: RedemptionMode::Bearer,
        );

        $voucher = app(VoucherIssuanceService::class)->issueFromRefund($request);

        $this->assertNotNull($voucher->id);
        $this->assertEquals('30.00000', $voucher->current_balance);
    }
}
