<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LEDGER gate G-3 — **a TRAINING receipt must not move real money.**
 *
 * A training receipt is a rehearsal: the cashier is practising on a live
 * terminal, no goods leave the shop and no cash enters the drawer. The device
 * still authors a fully-signed `SALE_RECEIPT` fiscal event (the hash chain has
 * no "practice" mode) carrying `training_flag = true`, and every downstream
 * consumer is expected to recognise that flag and contain the event to the read
 * model.
 *
 * `TreasuryReceiptBridge` did NOT. Before this fix its tender-leg loop ran with
 * zero training discrimination, so a rehearsal produced:
 *
 *   - a real `payments` row (`origin = Pos`, `status = Completed`),
 *   - a real synchronously-posted `pos_receipt` GL entry (revenue + cash), and
 *   - a real `repository_movements` row moving the drawer balance.
 *
 * The only training gate in the file guarded the §4.6 rounding / tolerance
 * entries — i.e. it suppressed the two-millime side entries while letting the
 * whole sale through. This file pins the containment at the level that matters:
 * **zero Treasury artefacts of any kind**, for sales AND refunds/voids (which
 * ride the same `SALE_RECEIPT` event through the same `apply()`), while the
 * projection itself still completes cleanly so the queue row reaches `applied`
 * and is never retried forever.
 *
 * The control arm is the load-bearing half: an otherwise byte-identical
 * NON-training receipt must still write all three artefacts, so the guard can
 * never be "fixed" by breaking the bridge.
 *
 * **PostgreSQL is mandatory for this file** (same reason as
 * {@see TreasuryReceiptBridgeRoundingGlTest}: the Task-7 partial unique indexes
 * on `journal_entries (source_type, source_id)` and the `pos_receipts_totals`
 * CHECK exist only on pgsql). Run with:
 *
 *   ./vendor/bin/phpunit -c phpunit-pgsql.xml \
 *     tests/Feature/Treasury/TrainingReceiptTreasuryContainmentTest.php
 *
 * Rule 20: the bridge runs on a Horizon worker with NO `CompanyContext` bound.
 * `setUp()` clears the context it needed for chart seeding, so every `apply()`
 * below reproduces the worker reality instead of masking it.
 *
 * The fixture is Tunisian (TND, currency scale 3) to match the rest of the
 * bridge suite.
 */
final class TrainingReceiptTreasuryContainmentTest extends TestCase
{
    use RefreshDatabase;

    /** TND — every money assertion in this file is at the company currency scale. */
    private const SCALE = 3;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Training Cashier']);
        $this->operatorId = $user->id;
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_cash_tender' => true,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->accountIdForCode('53'),
            'currency' => 'TND',
            'balance' => '0.000',
        ]);

        $this->seedTunisianPaymentPolicy();

        // Rule 20 — the projector runs with NO CompanyContext on a worker.
        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // The gate — a training receipt writes NOTHING to Treasury
    // =================================================================

    public function test_training_sale_writes_no_payment_no_gl_and_no_repository_movement(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 3,
            training: true,
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: true);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertNoTreasuryArtefacts($event, $receipt);
    }

    /**
     * Refunds/voids ride the SAME `SALE_RECEIPT` event (there is no separate
     * REFUND event type — `invoice_type_code` is the discriminator), so they
     * reach the SAME `apply()` and must be contained by the SAME guard.
     * A training refund is the more dangerous half: its tender legs pay cash
     * OUT of the drawer and post a revenue REVERSAL.
     */
    public function test_training_refund_writes_no_payment_no_gl_and_no_repository_movement(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 3,
            training: true,
            invoiceTypeCode: 'REFUND',
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: true);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertNoTreasuryArtefacts($event, $receipt);
    }

    /**
     * A v1 training receipt predates the cash-rounding cutover entirely, so the
     * only training gate that existed before this fix (the §4.6 one) was not
     * even reachable. Containment must not depend on `event_version`.
     */
    public function test_training_containment_is_not_gated_on_the_cash_rounding_cutover(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 1,
            training: true,
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: true);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertNoTreasuryArtefacts($event, $receipt);
    }

    /**
     * Returning early must NOT look like a failure to the projection runner:
     * the `fiscal_event_projections` row still has to reach `applied`, or
     * Horizon would retry the rehearsal forever and the operator would see a
     * permanently-stuck projection.
     */
    public function test_training_projection_row_still_reaches_applied(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 3,
            training: true,
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: true);

        $row = FiscalEventProjectionRow::query()->create([
            'id' => Str::uuid()->toString(),
            'fiscal_event_id' => $event->id,
            'projector_name' => 'treasury_receipt_bridge',
        ]);

        (new ApplyFiscalEventProjectionJob($row->id))
            ->handle(DB::connection(), app(FiscalEventProjectionRegistry::class));

        $this->assertSame(ProjectionStatus::Applied, $row->refresh()->projection_status);
        $this->assertNotNull($row->applied_at);
        $this->assertNoTreasuryArtefacts($event, $receipt);
    }

    // =================================================================
    // Control — the identical NON-training receipt still moves money
    // =================================================================

    public function test_control_non_training_sale_writes_payment_gl_and_repository_movement(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 3,
            training: false,
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: false);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'pos_receipt',
            'source_id' => $receipt->id,
        ]);
        $this->assertSame(1, $this->cashRepositoryMovementCount());
        // String comparison — never a float on money.
        $this->assertSame(0, bccomp($this->cashRepositoryBalance(), '10.000', self::SCALE));
    }

    public function test_control_non_training_refund_writes_payment_gl_and_repository_movement(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            eventVersion: 3,
            training: false,
            invoiceTypeCode: 'REFUND',
        );
        $receipt = $this->seedPosReceiptRowFor($event, isTraining: false);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        // A refund books the revenue REVERSAL under its own source type.
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'pos_receipt_refund',
            'source_id' => $receipt->id,
        ]);
        $this->assertSame(1, $this->cashRepositoryMovementCount());
        // A refund pays OUT of the drawer.
        $this->assertSame(0, bccomp($this->cashRepositoryBalance(), '-10.000', self::SCALE));
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Every Treasury write surface the bridge owns, asserted absent in one
     * place so a new artefact cannot be added without being covered here.
     */
    private function assertNoTreasuryArtefacts(FiscalEvent $event, Receipt $receipt): void
    {
        $this->assertSame(
            0,
            Payment::query()->where('fiscal_event_id', $event->id)->count(),
            'A training receipt must not write a Treasury payment.',
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $receipt->id)->count(),
            'A training receipt must not post to the general ledger.',
        );
        $this->assertSame(
            0,
            $this->cashRepositoryMovementCount(),
            'A training receipt must not move the drawer balance.',
        );
        $this->assertSame(
            0,
            PaymentInstrument::query()->where('company_id', $this->companyId)->count(),
            'A training receipt must not create a payment instrument.',
        );
        // The drawer figure is the operator-visible half of the containment.
        $this->assertSame(0, bccomp($this->cashRepositoryBalance(), '0.000', self::SCALE));
    }

    private function accountIdForCode(string $code): string
    {
        return (string) Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    /**
     * The drawer figure as a numeric-string — never a float on money.
     *
     * @return numeric-string
     */
    private function cashRepositoryBalance(): string
    {
        return PaymentRepository::query()
            ->where('company_id', $this->companyId)
            ->firstOrFail()
            ->balance;
    }

    private function cashRepositoryMovementCount(): int
    {
        $repositoryId = PaymentRepository::query()
            ->where('company_id', $this->companyId)
            ->firstOrFail()
            ->id;

        return DB::table('repository_movements')
            ->where('payment_repository_id', $repositoryId)
            ->count();
    }

    /**
     * TN policy row — the live EFFECTIVE config the shortfall ceiling reads
     * through `PosPaymentPolicyResolver`.
     */
    private function seedTunisianPaymentPolicy(): void
    {
        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
        ]);

        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();
        DB::table('country_payment_settings')->insert([
            'id' => (string) Str::uuid(),
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'pos_tolerance_enabled' => true,
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0500',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Build the minimal `pos_receipts` row the bridge depends on (normally
     * written by `PosCoreReceiptProjection`, which is already training-aware).
     */
    private function seedPosReceiptRowFor(FiscalEvent $event, bool $isTraining): Receipt
    {
        $terminal = Terminal::query()->findOrFail($this->terminalId);

        return Receipt::factory()
            ->withTotal('10.000', '0.000')
            ->create([
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'location_id' => $terminal->location_id,
                'terminal_id' => $event->terminal_id,
                'cashier_id' => $event->operator_id,
                'currency' => 'TND',
                'is_training' => $isTraining,
                'training_flag' => $isTraining,
                'fiscal_event_id' => $event->id,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'chain_sequence' => $event->sequence_number,
            ]);
    }

    /**
     * Fiscal-event fixture builder — a single 10.000 TND cash leg, exactly
     * balanced (no rounding, no tolerance shortfall), so the ONLY thing that
     * differs between the gate arms and the control arms is `training_flag`.
     */
    private function storeSaleReceiptFiscalEvent(
        int $eventVersion = 3,
        bool $training = false,
        string $invoiceTypeCode = 'SALE',
        int $sequenceNumber = 1,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $isRefund = $invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID';

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Training Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '10.000',
                'line_vat' => '0.000',
                'name' => 'Training item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-training',
                'quantity' => '1.0000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => '10.000',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $isRefund ? [
                'fiscal_event_id' => '77777777-7777-4777-8777-777777777777',
                'original_business_date' => $businessDate->toDateString(),
                'original_receipt_uuid' => '88888888-8888-4888-8888-888888888888',
                'refund_reason' => 'Training return',
            ] : null,
            'payments' => [[
                'amount' => '10.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 avenue Habib Bourguiba'],
                'name' => 'Training Seller SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AAM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.000',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.000',
            'training_flag' => $training,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.000',
                'net_amount' => '10.000',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        if ($eventVersion >= 3) {
            $payload['cash_rounding_adjustment'] = '0.000';
            $payload['cash_rounding_denomination'] = '0.000';
        }

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
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
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
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

    /**
     * Spec §4 JCS canonical encoding (test-local) — sorts keys at every depth.
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
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
