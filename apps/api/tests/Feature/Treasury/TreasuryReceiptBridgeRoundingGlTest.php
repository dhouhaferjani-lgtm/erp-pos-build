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
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cash-rounding Phase 1 / Task 10 — the two NEW GL entries the bridge posts
 * for a v3 receipt (spec §4.6 entries 1 + 2).
 *
 * **What the entries are for.** A rounded cash sale collects a different amount
 * than the sale is worth, and a tolerated under-tender collects less than the
 * receipt total. Both gaps must land somewhere or the ledger stops balancing:
 *
 *   1. **Rounding** `R = cash_rounding_adjustment ≠ 0` —
 *      `R > 0` ⇒ `Dr ProductRevenue R / Cr 7580 R`;
 *      `R < 0` ⇒ `Dr 6580 |R| / Cr ProductRevenue |R|`.
 *      A REFUND/VOID posts the SYMMETRIC REVERSAL (legs flipped) under the
 *      distinct `pos_cash_rounding_refund` source type.
 *   2. **Tolerance** `S = max(0, total − Σ TENDERED) > 0` ⇒
 *      `Dr 6580 S / Cr ProductRevenue S`.
 *
 * The net of the three entries is the point: the cash account carries what was
 * actually collected while `ProductRevenue` ends at the EXACT sale value —
 * `test_worked_example_posts_all_three_entries_and_balances` pins the spec's
 * own worked figures to the millime.
 *
 * **Everything here is gated on `event_version >= 3` AND `training_flag`
 * false.** A v1/v2 event and a training receipt post nothing new, ever.
 *
 * **PostgreSQL is mandatory for this file.** The Task-7 partial unique indexes
 * on `journal_entries (source_type, source_id)` are the DB-level backstop for
 * the idempotency probe and exist only on pgsql; `pos_receipts_totals` (which
 * every fixture below satisfies) is likewise a PG CHECK. Run with:
 *
 *   ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php
 *
 * Rule 20: the bridge runs on a Horizon worker with NO `CompanyContext` bound.
 * `setUp()` clears the context it needed for chart seeding, so every `apply()`
 * below reproduces the worker reality instead of masking it.
 *
 * The fixture is Tunisian on purpose — TND currency scale 3, the `0.050`
 * denomination and the `country_payment_settings` row the spec's §4.6 worked
 * example is written against.
 */
final class TreasuryReceiptBridgeRoundingGlTest extends TestCase
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

        // `tunisia()` pins country TN + currency TND, which selects the
        // Tunisian chart (707 / 6580 / 7580 all carry their system purposes)
        // and the `country_payment_settings` row the tolerance ceiling reads.
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

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Rounding Cashier']);
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

        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // Entry 1 — the rounding difference
    // =================================================================

    public function test_round_down_posts_dr_6580_cr_revenue(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($this->accountIdForCode('6580'), $lines[0]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[0]->debit), '0.023', self::SCALE));
        $this->assertSame($this->accountIdForCode('707'), $lines[1]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[1]->credit), '0.023', self::SCALE));
    }

    public function test_round_up_posts_dr_revenue_cr_7580(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.027',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        $this->assertSame($this->accountIdForCode('707'), $lines[0]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[0]->debit), '0.027', self::SCALE));
        $this->assertSame($this->accountIdForCode('7580'), $lines[1]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[1]->credit), '0.027', self::SCALE));
    }

    public function test_exact_multiple_posts_no_rounding_entry(): void
    {
        // A total that already sits on the denomination signs a canonical zero
        // adjustment. Zero is not a difference — posting a 0/0 entry would be
        // ledger noise and would burn an entry number per receipt.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '10.000',
            cashRoundingAdjustment: '0.000',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'pos_cash_rounding',
            'source_id' => $receipt->id,
        ]);
    }

    public function test_refund_posts_the_symmetric_reversal_under_a_distinct_source_type(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            invoiceTypeCode: 'REFUND',
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding_refund')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        // The sale posted Dr 6580 / Cr 707; the refund is the exact inverse.
        // Direction therefore CANNOT be derived from the sign of R alone —
        // that would replay the sale's own legs onto the refund and double the
        // expense instead of clearing it.
        $this->assertSame($this->accountIdForCode('707'), $lines[0]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[0]->debit), '0.023', self::SCALE));
        $this->assertSame($this->accountIdForCode('6580'), $lines[1]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[1]->credit), '0.023', self::SCALE));

        // And the sale literal must stay unused, or the partial unique index
        // would collide the refund with its own original.
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'pos_cash_rounding',
            'source_id' => $receipt->id,
        ]);
    }

    // =================================================================
    // Entry 2 — the tender-tolerance write-off
    // =================================================================

    public function test_shortfall_posts_dr_6580_cr_revenue(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $entry = JournalEntry::query()
            ->where('source_type', 'pos_tolerance_bridge')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $lines = $entry->lines()->orderBy('line_order')->get();
        $this->assertSame($this->accountIdForCode('6580'), $lines[0]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[0]->debit), '0.050', self::SCALE));
        $this->assertSame($this->accountIdForCode('707'), $lines[1]->account_id);
        $this->assertSame(0, bccomp($this->numeric($lines[1]->credit), '0.050', self::SCALE));
    }

    public function test_exact_tender_posts_no_tolerance_entry(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'pos_tolerance_bridge',
            'source_id' => $receipt->id,
        ]);
    }

    public function test_over_tender_posts_no_tolerance_entry_and_never_double_counts_the_netted_change(): void
    {
        // Tendered 10.000 against a 9.950 total: the 0.050 change is netted off
        // the cash leg by Task 9, so the ONLY additional GL for this receipt is
        // the rounding entry. A tolerance entry here would book a shortfall that
        // does not exist, and re-booking the netted change would double-count it.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'pos_tolerance_bridge',
            'source_id' => $receipt->id,
        ]);
        // Cash carries the RETAINED 9.950; revenue still ends at the exact 9.973.
        $this->assertSame(0, bccomp($this->netDebitForCode('53'), '9.950', self::SCALE));
        $this->assertSame(0, bccomp($this->netCreditForCode('707'), '9.973', self::SCALE));
    }

    // =================================================================
    // The spec's worked example (§4.6)
    // =================================================================

    public function test_worked_example_posts_all_three_entries_and_balances(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_receipt', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);

        // Cash carries what was actually handed over.
        $this->assertSame(0, bccomp($this->netDebitForCode('53'), '9.900', self::SCALE));
        // Revenue ends at the EXACT sale value 9.973 — the whole point.
        $this->assertSame(0, bccomp($this->netCreditForCode('707'), '9.973', self::SCALE));
        // 6580 carries the rounding 0.023 + the tolerance 0.050.
        $this->assertSame(0, bccomp($this->netDebitForCode('6580'), '0.073', self::SCALE));

        // Every entry balances on its own.
        foreach (JournalEntry::query()->where('source_id', $receipt->id)->get() as $entry) {
            $debit = '0.000';
            $credit = '0.000';
            foreach ($entry->lines()->get() as $line) {
                $debit = bcadd($debit, $this->numeric($line->debit), self::SCALE);
                $credit = bcadd($credit, $this->numeric($line->credit), self::SCALE);
            }
            $this->assertSame(0, bccomp($debit, $credit, self::SCALE), "Entry {$entry->source_type} does not balance.");
        }
    }

    public function test_both_new_source_types_book_to_the_misc_od_journal(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        // `JournalCode::fromSourceType` has no arm for either literal, so both
        // fall through to Misc/OD. That is the EXPLICIT spec decision (no enum
        // change) — pinned here so a future arm cannot be added silently.
        $this->assertSame('OD', DB::table('journal_entries')
            ->where('source_type', 'pos_cash_rounding')->where('source_id', $receipt->id)->value('journal_code'));
        $this->assertSame('OD', DB::table('journal_entries')
            ->where('source_type', 'pos_tolerance_bridge')->where('source_id', $receipt->id)->value('journal_code'));
    }

    // =================================================================
    // The gates: v3 + non-training
    // =================================================================

    public function test_v2_event_posts_no_rounding_or_tolerance_entry(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '10.000',
            eventVersion: 2,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        // A v2 event under-tenders by 0.100 here; below the cutover that gap is
        // NOT the bridge's business, exactly as it was before this feature.
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_receipt', 'source_id' => $receipt->id]);
    }

    public function test_training_receipt_posts_no_rounding_or_tolerance_entry(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            training: true,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
    }

    // =================================================================
    // Idempotency
    // =================================================================

    public function test_replay_posts_each_entry_exactly_once(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        $bridge = app(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        // The (source_type, source_id) probe runs BEFORE the create: the Task-7
        // partial unique indexes would otherwise raise a 23505 on the second
        // apply and poison the queue job for good.
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'pos_cash_rounding')->where('source_id', $receipt->id)->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'pos_tolerance_bridge')->where('source_id', $receipt->id)->count());
        // …and the ledger totals did not move either.
        $this->assertSame(0, bccomp($this->netCreditForCode('707'), '9.973', self::SCALE));
        $this->assertSame(0, bccomp($this->netDebitForCode('6580'), '0.073', self::SCALE));
    }

    // =================================================================
    // Purpose precheck
    // =================================================================

    public function test_missing_purpose_account_skips_the_entry_alerts_and_still_posts_the_legs(): void
    {
        DB::table('accounts')
            ->where('company_id', $this->companyId)
            ->where('code', '6580')
            ->delete();

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        // Revenue recognition is NOT hostage to a missing write-off account.
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_receipt', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.gl.tolerance_purpose_missing',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
    }

    public function test_missing_expense_purpose_skips_only_the_entry_that_needs_it(): void
    {
        // 6580 gone, 7580 intact: a round-UP receipt needs only 7580, so its
        // rounding entry MUST still post while the tolerance write-off (which
        // needs 6580) is the only casualty.
        DB::table('accounts')
            ->where('company_id', $this->companyId)
            ->where('code', '6580')
            ->delete();

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '10.000',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.027',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_cash_rounding', 'source_id' => $receipt->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.gl.tolerance_purpose_missing',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_purpose_missing_alert_does_not_stack_across_replays(): void
    {
        DB::table('accounts')
            ->where('company_id', $this->companyId)
            ->where('code', '6580')
            ->delete();

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = app(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos.gl.tolerance_purpose_missing')
            ->where('aggregate_id', $event->id)
            ->count());
    }

    // =================================================================
    // Beyond-config shortfall
    // =================================================================

    public function test_shortfall_beyond_config_still_posts_and_alerts(): void
    {
        // TN: pct cap 0.5% of 9.950 = 0.049 (truncated), max 0.100, denomination
        // floor 0.050 ⇒ effective max 0.050. A 5.000 shortfall is far beyond it.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '4.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        // The money moved either way — the write-off POSTS and the alert is the
        // signal, never a gate.
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.tolerance.shortfall_exceeds_config',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_shortfall_inside_the_denomination_floor_raises_no_alert(): void
    {
        // The spec's own worked example: S = 0.050 against a 0.5% pct cap of
        // 0.049. Without the §8.1 denomination floor every legitimately
        // auto-accepted full-D shortfall would alert — an alert storm on day one.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos.tolerance.shortfall_exceeds_config')
            ->count());
    }

    public function test_beyond_config_shortfall_with_supervisor_approval_raises_no_alert(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '4.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            approvalScope: 'tender_tolerance_override',
        );
        $receipt = $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('journal_entries', ['source_type' => 'pos_tolerance_bridge', 'source_id' => $receipt->id]);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos.tolerance.shortfall_exceeds_config')
            ->count());
    }

    public function test_a_foreign_scope_approval_is_not_tolerance_evidence(): void
    {
        // A discount override rode the same array. It approves a DISCOUNT, not a
        // tender gap — reading "any approval" as evidence would let one
        // supervisor tap launder an arbitrary till shortage.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '4.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            approvalScope: 'discount_limit_override',
        );
        $this->seedPosReceiptRowFor($event);

        app(TreasuryReceiptBridge::class)->apply($event);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.tolerance.shortfall_exceeds_config',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_shortfall_alert_does_not_stack_across_replays(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '4.950', 'method_code' => 'CASH']],
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );
        $this->seedPosReceiptRowFor($event);

        $bridge = app(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos.tolerance.shortfall_exceeds_config')
            ->where('aggregate_id', $event->id)
            ->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function accountIdForCode(string $code): string
    {
        return (string) Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    /**
     * Σ credit − Σ debit across every journal line touching the account.
     *
     * @return numeric-string
     */
    private function netCreditForCode(string $code): string
    {
        $accountId = $this->accountIdForCode($code);

        /** @var numeric-string $net */
        $net = '0.000';
        foreach (DB::table('journal_lines')->where('account_id', $accountId)->get() as $line) {
            $net = bcadd($net, $this->numeric($line->credit ?? null), self::SCALE);
            $net = bcsub($net, $this->numeric($line->debit ?? null), self::SCALE);
        }

        return $net;
    }

    /**
     * @return numeric-string
     */
    private function netDebitForCode(string $code): string
    {
        /** @var numeric-string $negated */
        $negated = bcmul($this->netCreditForCode($code), '-1', self::SCALE);

        return $negated;
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
     * TN policy row — the live EFFECTIVE config the shortfall ceiling reads
     * through `PosPaymentPolicyResolver`. Mirrors migration A2's intended
     * end-state (0.5% / 0.100 max / 0.0500 denomination, rounding enabled).
     */
    private function seedTunisianPaymentPolicy(): void
    {
        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            // NOT the column default (2). The resolver reads the company
            // currency scale from HERE; at scale 2 the 0.050 denomination fails
            // its round-trip check and rounding reports DISABLED, which would
            // silently drop the denomination floor out of every assertion below.
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
     * Build a minimal `pos_receipts` row pointing at the given fiscal event,
     * bypassing `PosCoreReceiptProjection`. The bridge reads only the receipt's
     * currency / partner / posted_at / cashier / receipt_number, so the row's
     * own totals are deliberately irrelevant to the entries under test.
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
                'currency' => 'TND',
                'fiscal_event_id' => $event->id,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'chain_sequence' => $event->sequence_number,
            ]);
    }

    /**
     * Fiscal-event fixture builder — the Task 9 netting-test shape re-pointed at
     * TND / `currency_scale` 3 (the spec's §4.6 worked example is Tunisian) and
     * extended with `$training`, `$invoiceTypeCode` and `$approvalScope`.
     *
     * The two `cash_rounding_*` keys are emitted only at v3+, so a v1/v2 payload
     * stays key-for-key identical to what devices sign today.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     */
    private function storeSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        string $total = '10.000',
        string $subtotal = '10.000',
        string $discountTotal = '0.000',
        string $taxTotal = '0.000',
        int $sequenceNumber = 1,
        int $eventVersion = 1,
        ?string $cashRoundingAdjustment = '0.000',
        ?string $cashRoundingDenomination = '0.000',
        bool $training = false,
        string $invoiceTypeCode = 'SALE',
        ?string $approvalScope = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['amount' => '10.000', 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'] ?? '10.000',
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
            ];
        }

        $approvalReferences = [];
        if ($approvalScope !== null) {
            $approvalReferences[] = [
                'approval_event_id' => '44444444-4444-4444-8444-444444444444',
                'approval_id' => '55555555-5555-4555-8555-555555555555',
                'approval_scope' => $approvalScope,
                'override_event_id' => '66666666-6666-4666-8666-666666666666',
                'policy_version' => 'v1',
                'supervisor_user_id' => $this->operatorId,
                'target_reference_id' => 'receipt',
            ];
        }

        $isRefund = $invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID';

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => $approvalReferences,
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $subtotal,
                'line_vat' => $taxTotal,
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.0000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $subtotal,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $isRefund ? [
                'fiscal_event_id' => '77777777-7777-4777-8777-777777777777',
                'original_business_date' => $businessDate->toDateString(),
                'original_receipt_uuid' => '88888888-8888-4888-8888-888888888888',
                'refund_reason' => 'Customer return',
            ] : null,
            'payments' => $payments,
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 avenue Habib Bourguiba'],
                'name' => 'Default Seller SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AAM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => $training,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $subtotal,
                'net_amount' => $subtotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
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
