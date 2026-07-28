<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Domain\CashRoundingCutover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Cash-rounding Phase 1 / Task 9 — `TreasuryReceiptBridge` change netting.
 *
 * **The contract.** The canonical payload carries the TENDERED amount per
 * tender leg. Treasury's `payments.amount` must carry the RETAINED amount —
 * tendered minus the change handed back — because the drawer never kept the
 * change. The bridge therefore runs a netting pre-pass that subtracts
 * `Σ legs − total` from the LAST cash leg, cascading backwards in canonical
 * index order.
 *
 * **The cutover is `event_version >= 3` and nothing else.** A v1/v2 event must
 * produce byte-identical Payments / GL entries / repository movements to what
 * it produces today, on FIRST APPLY **and on REPLAY**. The replay half is the
 * dangerous one: `TreasuryMovementService` throws `IdempotencyConflictException`
 * when an existing movement key is re-recorded with a different amount, so a
 * v2 event that was projected at its tendered amount and is later redelivered
 * into netting logic becomes a permanently-failing (poison) queue job. That is
 * the r1-T1 catastrophe, and `test_v2_replay_over_pre_seeded_tendered_rows_is_not_poisoned`
 * is its regression pin.
 *
 * **PostgreSQL is mandatory for this file.** `repository_movements` carries
 * `CHECK (amount > 0)` (`2026_07_08_100100:55`), which exists only on pgsql —
 * on SQLite a fully-netted leg would silently write a zero movement and the
 * suppression tests would pass vacuously. Run with:
 *
 *   ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Treasury/TreasuryReceiptBridgeNettingTest.php
 *
 * Rule 20: the bridge runs on a Horizon worker with NO `CompanyContext` bound.
 * `setUp()` therefore clears the context it needed for chart seeding, so every
 * `apply()` below reproduces the worker reality instead of masking it.
 */
final class TreasuryReceiptBridgeNettingTest extends TestCase
{
    use RefreshDatabase;

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

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
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

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Netting Cashier']);
        $this->operatorId = $user->id;
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        // `is_cash_tender` (Task 1) is the ONLY cash-ness predicate the netting
        // pre-pass consults — never the method code, never the display name.
        // The column defaults to FALSE, so every non-CASH method below is
        // fail-closed non-cash without saying so explicitly.
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_cash_tender' => true,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CARD',
            'name' => 'Card',
            'is_cash_tender' => false,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        // A voucher IS a payment leg (`vouchers_redeemed` is derived from
        // `payments[]`) but it is NOT a cash tender — nobody hands change back
        // out of a voucher.
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'VOUCHER',
            'name' => 'Store voucher',
            'is_cash_tender' => false,
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CHECK',
            'name' => 'Check',
            'is_cash_tender' => false,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $cashAccount = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '53')
            ->firstOrFail();
        // The bridge resolves this by tenant+company lookup, not by id — the
        // fixture only has to make exactly one GL-linked repository exist.
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
            'balance' => '0.000',
        ]);

        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // The cutover discriminator
    // =================================================================

    public function test_cutover_version_is_shared_with_the_pos_core_projection(): void
    {
        // The read model and the ledger MUST gate on the same integer. If they
        // ever diverge, one side nets a receipt the other does not and the two
        // tables disagree about the same fiscal event forever.
        $posCoreConstant = (new ReflectionClass(PosCoreReceiptProjection::class))
            ->getConstant('CASH_ROUNDING_EVENT_VERSION');

        $this->assertSame(3, CashRoundingCutover::EVENT_VERSION);
        $this->assertSame(CashRoundingCutover::EVENT_VERSION, $posCoreConstant);
    }

    // =================================================================
    // v1/v2 — byte-identical, first apply AND replay
    // =================================================================

    public function test_v2_event_is_untouched_by_netting(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 2,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '12.00', 2), 'v1/v2 must post the TENDERED amount, unchanged.');
    }

    public function test_v1_event_is_untouched_by_netting(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 1,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '12.00', 2));
    }

    public function test_v2_replay_over_pre_seeded_tendered_rows_is_not_poisoned(): void
    {
        // THE r1-T1 SCENARIO. The first apply writes the Payment + the movement
        // at the TENDERED amount (12.00). The redelivery then re-enters the
        // bridge with the netting code in place. If the v3 gate leaked, the
        // second apply would re-record movement key
        // `fiscal_event:{id}:payment:0` at 10.00 against a stored 12.00, and
        // `TreasuryMovementService` would throw `IdempotencyConflictException`
        // — a permanently-failing queue job on historical traffic.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 2,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, DB::table('repository_movements')
            ->where('idempotency_key', $this->legKey($event, 0))
            ->count());

        // Replay — must not throw.
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '12.00', 2));
        $movementAmount = DB::table('repository_movements')
            ->where('idempotency_key', $this->legKey($event, 0))
            ->value('amount');
        $this->assertSame(0, bccomp($this->numeric($movementAmount), '12.00', 2));
    }

    // =================================================================
    // v3 netting
    // =================================================================

    public function test_v3_single_cash_leg_is_netted_down_by_the_change(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '10.00', 2));

        // The GL post and the drawer movement follow the RETAINED amount too —
        // netting only the `payments` row would leave the ledger claiming the
        // business banked the customer's change.
        $movementAmount = DB::table('repository_movements')
            ->where('idempotency_key', $this->legKey($event, 0))
            ->value('amount');
        $this->assertSame(0, bccomp($this->numeric($movementAmount), '10.00', 2));
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'line_order' => 0,
            'debit' => '10.000',
        ]);
    }

    public function test_v3_exact_tender_is_not_netted_at_all(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '10.00', 2));
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->count());
    }

    public function test_v3_under_tender_shortfall_never_inflates_a_leg(): void
    {
        // Σ legs < total is a tolerance shortfall (Task 10 books the write-off).
        // `change = max(0, Σ legs − total)` must clamp at zero — a naive
        // `bcsub` would ADD 2.00 to the cash leg and invent money.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '8.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '8.00', 2));
    }

    public function test_v3_change_cascades_backwards_across_cash_legs(): void
    {
        // Legs: CARD 4.00, CASH 3.00, CASH 5.00 ; total 10.00 ; change 2.00.
        // Cascade: last CASH 5.00 -> 3.00 ; earlier legs untouched.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '4.00', 'method_code' => 'CARD'],
                ['amount' => '3.00', 'method_code' => 'CASH'],
                ['amount' => '5.00', 'method_code' => 'CASH'],
            ],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = $this->paymentAmountsByLeg($event);

        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 0)] ?? null), '4.00', 2));
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 1)] ?? null), '3.00', 2));
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 2)] ?? null), '3.00', 2));
    }

    public function test_v3_change_spanning_two_cash_legs_drains_the_last_one_first(): void
    {
        // Legs: CASH 3.00, CASH 4.00 ; total 2.00 ; change 5.00.
        // Cascade: leg 1 (4.00) drains to 0.00 and is SUPPRESSED, then the
        // remaining 1.00 comes off leg 0 (3.00 -> 2.00).
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '3.00', 'method_code' => 'CASH'],
                ['amount' => '4.00', 'method_code' => 'CASH'],
            ],
            total: '2.00',
            subtotal: '2.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = $this->paymentAmountsByLeg($event);
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 0)] ?? null), '2.00', 2));
        $this->assertArrayNotHasKey($this->legKey($event, 1), $byLeg);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->count());
    }

    public function test_v3_fully_netted_leg_writes_no_payment_gl_or_movement(): void
    {
        // Legs: CASH 4.00, CASH 8.00 ; total 4.00 ; change 8.00 kills leg 1.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '4.00', 'method_code' => 'CASH'],
                ['amount' => '8.00', 'method_code' => 'CASH'],
            ],
            total: '4.00',
            subtotal: '4.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('payments', [
            'idempotency_key' => $this->legKey($event, 1),
        ]);
        $this->assertDatabaseMissing('repository_movements', [
            'idempotency_key' => $this->legKey($event, 1),
        ]);
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        // Exactly ONE GL entry: the suppressed leg posted nothing either.
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt')
            ->count());

        // The surviving leg keeps its ORIGINAL ordinal (0). The ordinal hole at
        // 1 is legal because `payments_idempotency_key_uniq` is a PARTIAL index
        // (`2026_07_08_150000:44-46`); re-indexing the survivors would break
        // replay idempotency against a prior partial write.
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame($this->legKey($event, 0), $payment->idempotency_key);
    }

    public function test_v3_fully_netted_leg_suppression_survives_a_replay(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '4.00', 'method_code' => 'CASH'],
                ['amount' => '8.00', 'method_code' => 'CASH'],
            ],
            total: '4.00',
            subtotal: '4.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        // Task 20's per-leg completion path must NOT read the ordinal hole as
        // "a missing leg to complete" and mint the suppressed Payment on replay.
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertDatabaseMissing('payments', ['idempotency_key' => $this->legKey($event, 1)]);
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'pos_receipt')->count());
    }

    public function test_v3_replay_with_a_pre_seeded_tendered_payment_and_movement_is_idempotent(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, DB::table('repository_movements')
            ->where('idempotency_key', $this->legKey($event, 0))
            ->count());

        // The movement port throws IdempotencyConflictException on an amount
        // mismatch for an existing key — a second apply() proves the netted
        // amount is a deterministic pure function of the sealed payload.
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '10.00', 2));
    }

    // =================================================================
    // Non-cash legs
    // =================================================================

    public function test_v3_change_exceeding_all_cash_legs_alerts_and_nets_what_it_can(): void
    {
        // CARD 20.00 only ; total 10.00 ; change 10.00 but no cash to net.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '20.00', 'method_code' => 'CARD']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '20.00', 2), 'Non-cash legs are never netted.');
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.change.exceeds_cash_legs',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_v3_partial_cash_cover_nets_what_it_can_and_alerts_for_the_remainder(): void
    {
        // CARD 10.00 + CASH 2.00 ; total 5.00 ; change 7.00. The cash leg can
        // only absorb 2.00, so it is suppressed and 5.00 stays un-netted.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '10.00', 'method_code' => 'CARD'],
                ['amount' => '2.00', 'method_code' => 'CASH'],
            ],
            total: '5.00',
            subtotal: '5.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = $this->paymentAmountsByLeg($event);
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 0)] ?? null), '10.00', 2));
        $this->assertArrayNotHasKey($this->legKey($event, 1), $byLeg);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.change.exceeds_cash_legs',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_v3_change_exceeds_cash_legs_alert_does_not_stack_across_replays(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '20.00', 'method_code' => 'CARD']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->where('aggregate_id', $event->id)
            ->count());
    }

    public function test_v2_change_exceeding_cash_legs_raises_no_alert(): void
    {
        // The alert is part of the netting pre-pass, which does not exist below
        // the cutover. A historical over-tendered v2 receipt must stay silent.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '20.00', 'method_code' => 'CARD']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 2,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->count());
    }

    public function test_voucher_leg_is_never_netted_because_it_is_not_a_cash_tender(): void
    {
        // VOUCHER 6.00 + CASH 6.00 ; total 10.00 ; change 2.00.
        // Classification is by `is_cash_tender`, NOT by "is it a payment leg" —
        // a voucher is a payment leg that can never fund change.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '6.00', 'method_code' => 'VOUCHER', 'instrument_type' => 'store_voucher', 'instrument_serial' => 'V-1'],
                ['amount' => '6.00', 'method_code' => 'CASH'],
            ],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = $this->paymentAmountsByLeg($event);
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 0)] ?? null), '6.00', 2));
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 1)] ?? null), '4.00', 2));
    }

    public function test_maturity_leg_keeps_its_tendered_amount(): void
    {
        // CHECK is non-cash and has maturity: it must never be netted, so
        // `handleMaturityRefundLeg()`'s instrument match on the raw tendered
        // amount (`TreasuryReceiptBridge:795`) keeps working, and the minted
        // instrument carries the face value of the paper the customer handed
        // over — a netted cheque would be a forgery.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '10.00', 'method_code' => 'CHECK', 'instrument_serial' => 'CHK-1', 'instrument_type' => 'cheque'],
                ['amount' => '4.00', 'method_code' => 'CASH'],
            ],
            total: '12.00',
            subtotal: '12.00',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $byLeg = $this->paymentAmountsByLeg($event);
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 0)] ?? null), '10.00', 2));
        $this->assertSame(0, bccomp($this->numeric($byLeg[$this->legKey($event, 1)] ?? null), '2.00', 2));

        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', sprintf('fiscal_event:%s:instrument:0', $event->id))
            ->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($instrument->amount), '10.00', 2));
    }

    // =================================================================
    // Two-semantics rule, end to end
    // =================================================================

    public function test_pos_read_model_keeps_tendered_while_treasury_keeps_retained(): void
    {
        // The production sequence: PosCoreReceiptProjection first, then the
        // bridge. `pos_receipt_payments.amount` (TENDERED) and `payments.amount`
        // (RETAINED) are DELIBERATELY different numbers for the same leg. Any
        // future reconciliation that equates them is wrong by construction.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '12.00', 'method_code' => 'CASH']],
            total: '10.00',
            subtotal: '10.00',
            eventVersion: 3,
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->sole();
        $tendered = DB::table('pos_receipt_payments')->where('receipt_id', $receipt->id)->value('amount');
        $this->assertSame(0, bccomp($this->numeric($tendered), '12.00', 2), 'pos_receipt_payments.amount is TENDERED.');
        $this->assertSame(0, bccomp($this->numeric($receipt->change_due), '2.00', 2));

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($payment->amount), '10.00', 2), 'payments.amount is RETAINED.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function legKey(FiscalEvent $event, int $index): string
    {
        return sprintf('fiscal_event:%s:payment:%d', $event->id, $index);
    }

    /**
     * Treasury payment amounts for the event, keyed by per-leg idempotency key.
     *
     * @return array<string, string|null>
     */
    private function paymentAmountsByLeg(FiscalEvent $event): array
    {
        /** @var array<string, string|null> $byLeg */
        $byLeg = DB::table('payments')
            ->where('fiscal_event_id', $event->id)
            ->pluck('amount', 'idempotency_key')
            ->map(static fn (mixed $amount): ?string => is_scalar($amount) ? (string) $amount : null)
            ->all();

        return $byLeg;
    }

    /**
     * Read a decimal as a numeric-string, failing loudly (and narrowing the
     * type honestly via `fail(): never`) on NULL / non-numeric.
     *
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            $this->fail(sprintf('Expected a numeric value, got %s.', var_export($value, true)));
        }

        return (string) $value;
    }

    /**
     * Build a minimal `pos_receipts` row pointing at the given fiscal event,
     * bypassing `PosCoreReceiptProjection`. The bridge reads only the
     * receipt's currency / partner / posted_at / cashier / receipt_number, so
     * the row's own totals are deliberately irrelevant to the netting under
     * test — which is exactly why most cases here skip the POS-core projector
     * and drive the bridge in isolation.
     */
    private function seedPosReceiptRowFor(FiscalEvent $event): Receipt
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
                'currency' => 'EUR',
                'fiscal_event_id' => $event->id,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'chain_sequence' => $event->sequence_number,
            ]);
    }

    /**
     * Fiscal-event fixture builder — the `TreasuryReceiptBridgeTest` shape
     * (EUR / `currency_scale` 2) extended with `$eventVersion` plus the two v3
     * cash-rounding payload keys, exactly as Task 8's builder does.
     * `$eventVersion` stamps BOTH `fiscal_events.event_version` and the
     * canonical envelope, and the two rounding keys are emitted only at v3+ so
     * a v1/v2 payload stays key-for-key identical to what devices sign today.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     */
    private function storeSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        int $sequenceNumber = 1,
        int $eventVersion = 1,
        ?string $cashRoundingAdjustment = '0.00',
        ?string $cashRoundingDenomination = '0.00',
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['amount' => '10.00', 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'] ?? '10.00',
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
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
                'line_subtotal' => $subtotal,
                'line_vat' => $taxTotal,
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $subtotal,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $subtotal,
                'net_amount' => $subtotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => $taxTotal,
            'vouchers_redeemed' => [],
        ];

        if ($eventVersion >= 3) {
            $payload['cash_rounding_adjustment'] = $cashRoundingAdjustment;
            $payload['cash_rounding_denomination'] = $cashRoundingDenomination;
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
