<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PaymentMethodRepositoryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    private PaymentRepository $fallbackRepository;

    private PaymentRepository $cardRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'EUR',
        ]);
        $this->companyId = $company->id;
        app(CompanyContext::class)->setCompanyId($company->id);

        $location = Location::factory()->create(['company_id' => $company->id]);
        $this->terminalId = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ])->id;
        $this->operatorId = User::factory()->create(['tenant_id' => $tenant->id])->id;

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            // N-12 gate r1 finding 4 — `payment_methods.is_cash_tender` is NOT
            // NULL DEFAULT false, so a fixture that omits it declares a method
            // called CASH to be a non-cash tender. Every country's
            // `PaymentMethodSeeder` sets this true for CASH; the fixture now
            // matches production, which is what makes the cash-desk assertions
            // below mean what they say.
            'is_cash_tender' => true,
        ]);
        $this->cardMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CARD',
            'name' => 'Card',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $cashAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash);

        $this->fallbackRepository = PaymentRepository::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000101',
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH-DESK',
            'name' => 'Cash desk',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
        $this->cardRepository = PaymentRepository::factory()->create([
            'id' => 'ffffffff-ffff-4fff-8fff-fffffffff102',
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CARD-SETTLEMENT',
            'name' => 'Card settlement',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $cashAccount->id,
        ]);

        app(CompanyContext::class)->clear();
    }

    public function test_mapped_card_routes_to_its_repository_while_cash_uses_fallback_without_company_context(): void
    {
        $this->setDefaultRepository($this->cardMethod, $this->cardRepository);
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CARD'],
        ]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payments = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->get()
            ->keyBy('payment_method_id');

        $this->assertSame($this->fallbackRepository->id, $payments->get($this->cashMethod->id)?->repository_id);
        $this->assertSame($this->cardRepository->id, $payments->get($this->cardMethod->id)?->repository_id);
    }

    public function test_unmapped_method_uses_first_gl_linked_repository_ordered_by_id(): void
    {
        // Driven with the CASH tender: this case pins the type-preference +
        // stable-UUID ordering of the fallback, and after N-12 gate r1 finding 4
        // a CARD tender no longer exercises that ordering at all (it prefers the
        // settlement repository — see the case below).
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CASH'],
        ]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($this->fallbackRepository->id, $payment->repository_id);
    }

    /**
     * N-12 gate r1 finding 4 — an UNMAPPED non-cash tender settles in the bank.
     *
     * This is the behaviour change the finding asked for, at the layer that
     * ships it: before, an unmapped CARD leg took the type-preferred fallback
     * and landed in `CASH-DESK`, so card money was counted at the drawer and the
     * shift could not reconcile. `CARD-SETTLEMENT` sorts AFTER `CASH-DESK` by
     * UUID, so this cannot pass on the old ordering by accident.
     */
    public function test_unmapped_non_cash_method_settles_in_the_bank_not_the_cash_desk(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CARD'],
        ]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($this->cardRepository->id, $payment->repository_id);
        $this->assertNotSame($this->fallbackRepository->id, $payment->repository_id);
    }

    public function test_inactive_or_non_gl_mapped_repository_falls_back_safely(): void
    {
        $this->cardRepository->update(['is_active' => false]);
        $this->setDefaultRepository($this->cardMethod, $this->cardRepository);
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CARD'],
        ]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($this->fallbackRepository->id, $payment->repository_id);
    }

    public function test_replay_after_mapping_change_keeps_original_payment_repository(): void
    {
        $this->setDefaultRepository($this->cardMethod, $this->cardRepository);
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CARD'],
        ]);
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);

        $this->setDefaultRepository($this->cardMethod, $this->fallbackRepository);
        $bridge->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($this->cardRepository->id, $payment->repository_id);
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $movementRepositoryId = DB::table('repository_movements')
            ->where('source_id', $event->id)
            ->value('payment_repository_id');
        $this->assertSame($this->cardRepository->id, $movementRepositoryId);
    }

    public function test_configure_method_routing_command_is_dry_run_safe_and_idempotent(): void
    {
        $this->artisan('treasury:configure-method-routing', [
            '--card-to' => 'CARD-SETTLEMENT',
            '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertNull($this->cardMethod->refresh()->getAttribute('default_repository_id'));

        $this->artisan('treasury:configure-method-routing', [
            '--card-to' => 'CARD-SETTLEMENT',
        ])->assertSuccessful();
        $this->assertSame($this->cardRepository->id, $this->cardMethod->refresh()->getAttribute('default_repository_id'));

        $this->artisan('treasury:configure-method-routing', [
            '--card-to' => 'CARD-SETTLEMENT',
        ])->assertSuccessful();
        $this->assertSame($this->cardRepository->id, $this->cardMethod->refresh()->getAttribute('default_repository_id'));
    }

    public function test_configure_command_resolves_the_target_independently_for_each_company(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'currency' => 'EUR',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'code' => 'CARD',
            'name' => 'Card',
        ]);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $cashAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'code' => 'CARD-SETTLEMENT',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $cashAccount->id,
        ]);

        $this->artisan('treasury:configure-method-routing', [
            '--card-to' => 'CARD-SETTLEMENT',
        ])->assertSuccessful();

        $this->assertSame($this->cardRepository->id, $this->cardMethod->refresh()->default_repository_id);
        $this->assertSame($repository->id, $method->refresh()->default_repository_id);
    }

    public function test_deleting_a_mapped_repository_nulls_the_mapping(): void
    {
        $this->setDefaultRepository($this->cardMethod, $this->cardRepository);

        $this->cardRepository->delete();

        $this->assertNull($this->cardMethod->refresh()->default_repository_id);
    }

    private function setDefaultRepository(PaymentMethod $method, PaymentRepository $repository): void
    {
        DB::table('payment_methods')
            ->where('id', $method->id)
            ->update(['default_repository_id' => $repository->id]);
        $method->refresh();
    }

    /**
     * @param  list<array{amount: string, method_code: string}>  $paymentLines
     */
    private function projectedSaleReceipt(array $paymentLines): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);
        $total = '0.00';
        $payments = [];

        foreach ($paymentLines as $line) {
            $total = bcadd($total, $line['amount'], 2);
            $payments[] = [
                'amount' => $line['amount'],
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $line['method_code'],
            ];
        }

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => $total,
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $total,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $total,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $total,
                'net_amount' => $total,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        $event = $this->persistEvent($payload, $businessDate, $eventTime, $previousHash);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistEvent(
        array $payload,
        CarbonInterface $businessDate,
        CarbonInterface $eventTime,
        string $previousHash,
    ): FiscalEvent {
        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];
        $canonicalBytes = $this->canonicalEncode($canonicalArray);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /** @param array<string, mixed> $value */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Canonical encode failed.');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
    }
}
