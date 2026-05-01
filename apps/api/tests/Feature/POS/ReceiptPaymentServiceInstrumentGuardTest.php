<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex review B4 (2026-04-30) — defense-in-depth at the writer layer.
 *
 * The HTTP request validator (StoreReceiptPaymentsRequest) rejects voucher /
 * gift-card payment rows with null instrument fields with 422. But programmatic
 * service callers (queue jobs, internal flows, future controllers, tests)
 * bypass FormRequest validation. The writer must raise the same fiscal-hash
 * invariant or a v3 receipt could still be sealed with `method_code = store_voucher`
 * and `instrument_serial = null` — exactly what B4 closes "once and for all."
 *
 * The throw must happen BEFORE any Treasury / GL / ReceiptPayment write so the
 * enclosing DB::transaction() rolls back cleanly with no partial chain state.
 */
final class ReceiptPaymentServiceInstrumentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.000',
        ]);

        // GL accounts (the writer touches GL even on the failure path's
        // pre-check, so they must exist).
        $voucherClearing = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '467',
            'name' => 'Voucher Clearing',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->voucherMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Store Voucher',
            'code' => 'store_voucher',
        ]);
        $this->voucherRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Voucher Clearing Repo',
            'code' => 'VOUCHER-01',
            'type' => RepositoryType::Virtual->value,
            'gl_account_id' => $voucherClearing->id,
        ]);

        $this->actingAs($this->cashier);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_process_receipt_payments_throws_when_store_voucher_lacks_instrument_fields(): void
    {
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $this->expectException(InstrumentRequiredException::class);
        $this->expectExceptionMessage('store_voucher');

        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                // Both instrument fields deliberately omitted — the validator
                // is bypassed because we call the service directly. The writer
                // must still refuse.
            ]],
        );
    }

    public function test_process_receipt_payments_throws_when_store_voucher_has_empty_instrument_serial(): void
    {
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        $this->expectException(InstrumentRequiredException::class);

        $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                'instrument_serial' => '', // empty must count as missing
            ]],
        );
    }

    public function test_process_receipt_payments_succeeds_when_store_voucher_carries_full_instrument_pair(): void
    {
        $receipt = $this->seedPendingSealReceipt('15.000');

        /** @var ReceiptPaymentService $service */
        $service = $this->app->make(ReceiptPaymentService::class);

        // Positive control: the same call shape but WITH the instrument pair
        // populated must succeed. This guards against an over-broad guard
        // refactor that would also reject legitimate voucher tenders.
        $result = $service->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                'instrument_serial' => 'SV-2026-0099',
            ]],
        );

        // Receipt is fiscalized (hash-bearing): the writer accepted the row.
        // Asserting the instrument fields round-trip is the load-bearing
        // positive control — the v3 hash binding is exercised by the
        // dedicated v3 fixture tests; here we just prove the guard does not
        // bite legitimate voucher tenders.
        $this->assertSame('SV-2026-0099', $result['receipt_payments'][0]->instrument_serial);
    }

    private static int $receiptCounter = 0;

    private function seedPendingSealReceipt(string $total): Receipt
    {
        return Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'total' => $total,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'currency' => 'EUR',
            'receipt_number' => sprintf('LOC-POS01-%d-%08d', date('Y'), ++self::$receiptCounter),
        ]);
    }
}
