<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * REPORT-ONLY census of POS receipts whose journal entries do not carry the
 * output VAT their own sealed breakdown says they collected (W4-9). Writes
 * nothing, ever.
 *
 * Before W4-9 the POS credited the whole gross tender to `70x` and posted no
 * `VatCollected` (4457 on the TN chart) line at all — revenue overstated by
 * exactly the VAT, VAT payable unrecorded, and the trial balance still closing,
 * so there was no symptom. The fix only changes what NEW receipts post;
 * already-posted entries stay wrong until they are corrected, and journal
 * entries are immutable (rule 8), so this command is the deploy check.
 *
 * **It compares AMOUNTS, not the presence of a line** (gate r1, F-3). The POS
 * books ONE ENTRY PER TENDER LEG, so a mid-deploy cutover or a partially
 * replayed multi-leg receipt lands a `4457` line on some legs and not others.
 * An "is there a VAT line?" predicate calls that CLEAN — and that population is
 * precisely what this command exists to find. Per receipt it sums
 * `credit − debit` across every VAT-purpose line on its POS entries and checks
 * the magnitude against the sealed total.
 *
 * The sealed VAT is likewise aggregated in a DERIVED TABLE before it is joined
 * (gate r1, F-2). Joining the rate rows and the entries in one flat product
 * multiplied the sealed VAT by the entry count, so a two-leg receipt reported
 * twice the money it actually owed.
 *
 * On a greenfield tenant the remedy is re-provisioning. On a trading tenant it
 * is a correcting entry per period, decided by the accountant — deliberately
 * NOT automated here: minting corrections from a census is exactly the kind of
 * unjustified GL mutation the document-per-action principle forbids.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `pos_receipts`,
 *   `journal_lines` and `accounts` are all TENANT tables, so post-2026-05-28
 *   (database-per-tenant) each invocation reports on ONLY the tenant database
 *   bound around it. A fleet-wide pass is an external `tenants:run` loop; a bare
 *   run against CENTRAL raises 42P01.
 */
final class PosReceiptVatLegCensusCommand extends Command
{
    /**
     * Both POS source types are covered. A refund reversal that debits revenue
     * gross is the mirror of the same defect — it would leave `4457`
     * overstated once sales start crediting it — so it belongs in the same
     * census, not a second one.
     *
     * @var list<string>
     */
    private const POS_SOURCE_TYPES = ['pos_receipt', 'pos_receipt_refund'];

    /**
     * A cheque/effet-tendered POS refund writes NO `pos_receipt_refund` entry at
     * all: `TreasuryReceiptBridge::handleMaturityRefundLeg()` cancels the paper
     * and returns, and the cancellation lands as
     * `source_type='instrument'`, `source_id=<instrument id>` (gate r2, R2-2).
     * Keyed on the instrument, it is unreachable from `pos_receipts.id`, so the
     * census used to flag the one refund F-1 had just taught to reverse `4457`
     * — and label it "never reached the GL", pointing the operator at
     * re-provisioning a receipt that is booked correctly.
     */
    private const INSTRUMENT_SOURCE_TYPE = 'instrument';

    protected $signature = 'pos:census-vat-legs
        {--tenant= : Optional tenant UUID filter on the current connection}
        {--company= : Optional company UUID filter}
        {--limit=50 : Maximum receipts to list individually}';

    /**
     * Exit code for "the chart of accounts cannot support POS at all", distinct
     * from `FAILURE` (= drift found) so a deploy script can branch on it.
     */
    private const UNPROVISIONED = 2;

    protected $description = 'Report-only: POS receipts whose journal entries do not carry their sealed output VAT. Exit 0 = clean, 1 = drift found, 2 = chart of accounts not provisioned for POS.';

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $companyId = $this->option('company');
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : 50;

        // Accounts are resolved by SYSTEM PURPOSE, never by code: the TN, FR, UK
        // and IT charts all number output VAT and RRR differently, and the codes
        // live in seeded country defaults.
        //
        // Treasury gate I-2 / M-2: scoped by `--company` like the receipt arms
        // below. On a multi-company tenant an unscoped pluck would let a leg
        // booked to company B's `4457` satisfy company A's receipt.
        $vatAccounts = $this->accountsForPurpose(SystemAccountPurpose::VatCollected, $companyId);
        // D-1 gate r1 (treasury) F-1 — the BASE arm needs the revenue accounts.
        $revenueAccounts = $this->accountsForPurpose(SystemAccountPurpose::ProductRevenue, $companyId);

        // BOTH hard purposes are prechecked, not just the one the census counts
        // (treasury gate I-2). Since W4-9 a POS tender leg resolves up to three
        // purposes — `ProductRevenue`, `VatCollected` and, on a discounted
        // receipt, `SalesDiscount` — and a chart missing any of them fails the
        // projection. A deploy gate that names only the first sends the operator
        // back for a second round trip.
        //
        // D-1 (owner ruling 2026-08-25): a receipt sealed at SALE_RECEIPT
        // `event_version >= 5` books its remise OUT OF THE BASE and resolves no
        // `SalesDiscount` account at all. The precheck is KEPT anyway — every
        // receipt sealed before the cutover still needs it, they are refused by
        // the projection without it, and they keep arriving from devices that
        // have not taken the new build (the cutover is forward-only). It is a
        // provisioning gate, not a per-receipt assertion.
        //
        // The DRIFT arm below is version-agnostic by construction: it compares
        // SEALED VAT against the VAT actually credited, and D-1 changes neither
        // side's meaning — only the number the device seals.
        $missingPurposes = [];
        if ($vatAccounts === []) {
            $missingPurposes[] = SystemAccountPurpose::VatCollected;
        }
        if ($revenueAccounts === []) {
            $missingPurposes[] = SystemAccountPurpose::ProductRevenue;
        }
        if ($this->accountsForPurpose(SystemAccountPurpose::SalesDiscount, $companyId) === []) {
            $missingPurposes[] = SystemAccountPurpose::SalesDiscount;
        }

        if ($missingPurposes !== []) {
            $this->error(sprintf(
                'Chart of accounts is not provisioned for POS: no account carries %s on this tenant database. '
                .'Every POS receipt that needs one is refused by the projection — payment, cash movement and GL '
                .'entry alike — so provision the chart before reading anything else into this census.',
                implode(' or ', array_map(
                    static fn (SystemAccountPurpose $purpose): string => '`'.$purpose->value.'`',
                    $missingPurposes,
                )),
            ));

            // A DISTINCT code (treasury gate M-3): "the chart is not provisioned"
            // and "these receipts have drifted" need different remedies, and a
            // deploy script that branches on the exit code must be able to tell
            // them apart. 0 = clean, 1 = drift, 2 = unprovisioned.
            return self::UNPROVISIONED;
        }

        $query = DB::table('pos_receipts')
            // Mirror the declaration's own predicate
            // (`EloquentVatDataRepository`): a TRAINING receipt is a rehearsal —
            // it seals its `pos_receipt_vat_details` rows and, by design, writes
            // NO journal entry, and a VOIDED one is likewise excluded from the
            // filing. Counting either as drift (treasury gate I-1) makes this
            // command return 1 forever on any tenant that trains its cashiers
            // during onboarding — which tenant #1 does — and an operator who
            // learns to ignore the deploy gate will ignore the real drift too.
            ->where('pos_receipts.is_training', false)
            ->where('pos_receipts.is_voided', false)
            // Aggregate FIRST, join second: one row per receipt on both sides,
            // so neither the rate rows nor the per-leg entries can multiply the
            // other (F-2).
            ->joinSub(
                DB::table('pos_receipt_vat_details')
                    ->select(
                        'receipt_id',
                        DB::raw('SUM(vat_amount) AS sealed_vat'),
                        // D-1 gate r1 (treasury) F-1 — the sealed BASE, the
                        // figure the declaration reports as `base_amount` and
                        // the one D-1's own defect class gets wrong.
                        DB::raw('SUM(net_amount) AS sealed_net'),
                    )
                    ->groupBy('receipt_id'),
                'sealed',
                'sealed.receipt_id',
                '=',
                'pos_receipts.id',
            )
            ->leftJoinSub(
                DB::table('journal_entries')
                    ->whereIn('source_type', self::POS_SOURCE_TYPES)
                    ->select('source_id', DB::raw('COUNT(*) AS entry_count'))
                    ->groupBy('source_id'),
                'entries',
                'entries.source_id',
                '=',
                'pos_receipts.id',
            )
            ->leftJoinSub(
                DB::table('journal_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereIn('journal_entries.source_type', self::POS_SOURCE_TYPES)
                    ->whereIn('journal_lines.account_id', $vatAccounts)
                    ->select(
                        'journal_entries.source_id',
                        DB::raw('SUM(journal_lines.credit) AS vat_credit'),
                        DB::raw('SUM(journal_lines.debit) AS vat_debit'),
                    )
                    ->groupBy('journal_entries.source_id'),
                'ledger',
                'ledger.source_id',
                '=',
                'pos_receipts.id',
            )
            // ── D-1 gate r1 (treasury) F-1 — the BASE arm ────────────────────
            // The VAT arm above is era-agnostic, and that is exactly why it is
            // not enough: D-1's defect class is a wrong revenue BASE with a
            // CORRECT VAT. The P0 this lane closed booked 574.547 against a
            // sealed base of 524.547 while the VAT matched to the millime — and
            // this census would have exited 0 on it. Same for the failure the
            // lane's own `SealedBaseEraAmbiguous` refusal guards against.
            //
            // The identity is era-agnostic and exact on BOTH sides:
            //   Σ pos_receipt_vat_details.net_amount == Σ (credit − debit) on
            //   the ProductRevenue account over the receipt's POS entries.
            // v<=4: 569.000 == 569.000 (the `70x` line ALONE, NOT `70x − 709` —
            // the contra is a separate account); v5: 524.547 == 524.547. The
            // §4.6 rounding entry moves the cash-rounding adjustment between
            // `70x` and 6580/7580 and lands revenue back on net, so the identity
            // survives rounding.
            ->leftJoinSub(
                DB::table('journal_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->whereIn('journal_entries.source_type', self::POS_SOURCE_TYPES)
                    ->whereIn('journal_lines.account_id', $revenueAccounts)
                    ->select(
                        'journal_entries.source_id',
                        DB::raw('SUM(journal_lines.credit) AS revenue_credit'),
                        DB::raw('SUM(journal_lines.debit) AS revenue_debit'),
                    )
                    ->groupBy('journal_entries.source_id'),
                'revenue_ledger',
                'revenue_ledger.source_id',
                '=',
                'pos_receipts.id',
            )
            // The instrument-cancellation arm, reached the only way it can be:
            // the refund receipt names its original, the original's POS payments
            // carry the paper, and the cancellation entry is keyed on that
            // instrument. Attributed to the REFUND receipt (not the sale) —
            // the sale keeps its own credit, the refund gets the matching debit,
            // and both reconcile against their own sealed rows.
            ->leftJoin('pos_receipts as original', 'original.id', '=', 'pos_receipts.original_receipt_id')
            ->leftJoinSub(
                DB::table('payments')
                    ->join('payment_instruments', 'payment_instruments.payment_id', '=', 'payments.id')
                    ->join('journal_entries', function (JoinClause $join): void {
                        $join->on('journal_entries.source_id', '=', 'payment_instruments.id')
                            ->where('journal_entries.source_type', '=', self::INSTRUMENT_SOURCE_TYPE);
                    })
                    ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->whereIn('journal_lines.account_id', $vatAccounts)
                    ->where('payments.origin', PaymentOrigin::Pos->value)
                    ->whereNotNull('payments.fiscal_event_id')
                    ->select(
                        'payments.fiscal_event_id',
                        DB::raw('SUM(journal_lines.credit) AS vat_credit'),
                        DB::raw('SUM(journal_lines.debit) AS vat_debit'),
                        DB::raw('COUNT(DISTINCT journal_entries.id) AS entry_count'),
                    )
                    ->groupBy('payments.fiscal_event_id'),
                'instrument_ledger',
                'instrument_ledger.fiscal_event_id',
                '=',
                'original.fiscal_event_id',
            )
            // The BASE arm needs the same instrument sibling the VAT arm has
            // (W4-9 gate r2 R2-2, one account over): a cheque/effet-tendered
            // POS refund reverses its REVENUE on the instrument-cancellation
            // entry too, so a base arm that only looked at `pos_receipt*`
            // entries reported a correctly booked refund as `ledger_base=0.00`.
            ->leftJoinSub(
                DB::table('payments')
                    ->join('payment_instruments', 'payment_instruments.payment_id', '=', 'payments.id')
                    ->join('journal_entries', function (JoinClause $join): void {
                        $join->on('journal_entries.source_id', '=', 'payment_instruments.id')
                            ->where('journal_entries.source_type', '=', self::INSTRUMENT_SOURCE_TYPE);
                    })
                    ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->whereIn('journal_lines.account_id', $revenueAccounts)
                    ->where('payments.origin', PaymentOrigin::Pos->value)
                    ->whereNotNull('payments.fiscal_event_id')
                    ->select(
                        'payments.fiscal_event_id',
                        DB::raw('SUM(journal_lines.credit) AS revenue_credit'),
                        DB::raw('SUM(journal_lines.debit) AS revenue_debit'),
                    )
                    ->groupBy('payments.fiscal_event_id'),
                'instrument_revenue_ledger',
                'instrument_revenue_ledger.fiscal_event_id',
                '=',
                'original.fiscal_event_id',
            )
            ->select([
                'pos_receipts.id',
                'pos_receipts.receipt_number',
                'pos_receipts.posted_at',
                'pos_receipts.currency',
                'sealed.sealed_vat',
                'sealed.sealed_net',
                'entries.entry_count',
                'ledger.vat_credit',
                'ledger.vat_debit',
                'revenue_ledger.revenue_credit',
                'revenue_ledger.revenue_debit',
                'instrument_revenue_ledger.revenue_credit as instrument_revenue_credit',
                'instrument_revenue_ledger.revenue_debit as instrument_revenue_debit',
                'instrument_ledger.vat_credit as instrument_vat_credit',
                'instrument_ledger.vat_debit as instrument_vat_debit',
                'instrument_ledger.entry_count as instrument_entry_count',
            ])
            ->orderBy('pos_receipts.posted_at')
            ->orderBy('pos_receipts.id');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('pos_receipts.tenant_id', $tenantId);
        }
        if (is_string($companyId) && $companyId !== '') {
            $query->where('pos_receipts.company_id', $companyId);
        }

        [$drift, $neverPosted, $listed] = $this->report($query, $limit);

        if ($drift === 0) {
            $this->info('POS output-VAT leg census: none — every POS receipt carries its sealed output VAT in the ledger.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'POS output-VAT leg census: %d receipt(s) whose ledger `%s` does not match their sealed VAT.',
            $drift,
            SystemAccountPurpose::VatCollected->value,
        ));
        foreach ($listed as $line) {
            $this->line($line);
        }
        $listedCount = count($listed);
        if ($drift > $listedCount) {
            $this->line(sprintf('… and %d more (raise --limit to list them).', $drift - $listedCount));
        }

        // `entry_count = 0` and `entry_count > 0` are different problems: the
        // first is a receipt that never reached the GL at all, the second is a
        // receipt booked by the pre-W4-9 writer or booked on only some of its
        // legs. Separating them keeps an operator from chasing the wrong remedy.
        $this->line(sprintf(
            'Breakdown: %d never reached the GL (no pos_receipt entry at all), %d were booked with a wrong or partial VAT leg.',
            $neverPosted,
            $drift - $neverPosted,
        ));

        return self::FAILURE;
    }

    /**
     * Walk the candidates and decide drift with bcmath at the receipt's own
     * currency scale — never in SQL. `SUM()` over a decimal column comes back
     * as a float on SQLite and as `numeric` on PostgreSQL; comparing those in
     * the database would make the verdict driver-dependent, on a check whose
     * whole job is to be trusted at deploy time.
     *
     * @return array{0: int, 1: int, 2: list<string>} [driftCount, neverPostedCount, listedLines]
     */
    private function report(Builder $query, int $limit): array
    {
        $drift = 0;
        $neverPosted = 0;
        $listed = [];

        foreach ($query->cursor() as $row) {
            $scale = $this->scaleResolver->getScaleSafe((string) ($row->currency ?? 'TND'), 3);

            $sealed = $this->money($row->sealed_vat, $scale);
            $sealedNet = $this->money($row->sealed_net ?? '0', $scale);
            // A receipt with neither sealed VAT nor a sealed base has nothing to
            // reconcile. Previously this skipped on VAT alone, which also skipped
            // the BASE check on a fully-exempt sale — a sale whose whole taxable
            // base is real even though its VAT is zero.
            if (bccomp($sealed, '0', $scale) <= 0 && bccomp($sealedNet, '0', $scale) <= 0) {
                continue;
            }

            // A sale credits 4457, its refund debits it; the sealed rows are
            // non-negative either way (table CHECK). Compare magnitudes.
            //
            // The instrument-cancellation arm is summed in alongside the
            // `pos_receipt*` entries: for a cheque/effet refund it IS the whole
            // ledger side.
            $credit = bcadd(
                $this->money($row->vat_credit ?? '0', $scale),
                $this->money($row->instrument_vat_credit ?? '0', $scale),
                $scale,
            );
            $debit = bcadd(
                $this->money($row->vat_debit ?? '0', $scale),
                $this->money($row->instrument_vat_debit ?? '0', $scale),
                $scale,
            );
            $ledger = bcsub($credit, $debit, $scale);
            $ledgerMagnitude = bccomp($ledger, '0', $scale) < 0
                ? bcmul($ledger, '-1', $scale)
                : $ledger;

            // ── D-1 gate r1 (treasury) F-1 — the BASE arm. ──────────────────
            // Same magnitude comparison, on the revenue account. Skipped
            // entirely when the receipt has no POS entry at all, so a
            // never-posted receipt is still reported once (by the VAT arm)
            // rather than twice.
            $revenueLedger = bcsub(
                bcadd(
                    $this->money($row->revenue_credit ?? '0', $scale),
                    $this->money($row->instrument_revenue_credit ?? '0', $scale),
                    $scale,
                ),
                bcadd(
                    $this->money($row->revenue_debit ?? '0', $scale),
                    $this->money($row->instrument_revenue_debit ?? '0', $scale),
                    $scale,
                ),
                $scale,
            );
            $revenueMagnitude = bccomp($revenueLedger, '0', $scale) < 0
                ? bcmul($revenueLedger, '-1', $scale)
                : $revenueLedger;
            $entryCount = (int) ($row->entry_count ?? 0) + (int) ($row->instrument_entry_count ?? 0);

            $vatOk = bccomp($ledgerMagnitude, $sealed, $scale) === 0;
            // The base is only checkable once the receipt HAS a POS entry; with
            // none, the VAT arm's `never reached the GL` verdict already says
            // everything there is to say.
            $baseOk = $entryCount === 0
                || bccomp($revenueMagnitude, $sealedNet, $scale) === 0;

            if ($vatOk && $baseOk) {
                continue;
            }

            $drift++;
            if ($entryCount === 0) {
                $neverPosted++;
            }

            if (count($listed) < $limit) {
                $listed[] = sprintf(
                    '%s  posted=%s  sealed_vat=%s  ledger_vat=%s  sealed_base=%s  ledger_base=%s %s  pos_entries=%d  receipt_id=%s',
                    (string) ($row->receipt_number ?? '(no number)'),
                    (string) ($row->posted_at ?? '(unposted)'),
                    $sealed,
                    $ledgerMagnitude,
                    $sealedNet,
                    $revenueMagnitude,
                    (string) ($row->currency ?? ''),
                    $entryCount,
                    (string) $row->id,
                );
            }
        }

        return [$drift, $neverPosted, $listed];
    }

    /**
     * Account ids carrying a system purpose, scoped to `--company` when given
     * (treasury gate M-2). The receipt arms honour `--company`; an unscoped
     * pluck would let a leg booked to company B's `4457` satisfy company A's
     * receipt on a multi-company tenant.
     *
     * @return list<string>
     */
    private function accountsForPurpose(SystemAccountPurpose $purpose, mixed $companyId): array
    {
        $query = DB::table('accounts')->where('system_purpose', $purpose->value);

        if (is_string($companyId) && $companyId !== '') {
            $query->where('company_id', $companyId);
        }

        /** @var list<string> $ids */
        $ids = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $query->pluck('id')->all(),
        ));

        return $ids;
    }

    /**
     * A driver-returned aggregate, normalised to the currency scale.
     *
     * ROUNDED, not truncated: SQLite has no decimal type, so `SUM()` over a
     * `decimal(12,3)` column comes back as a float. Truncating `6.9999999` to
     * `6.999` would invent drift on a receipt that is perfectly booked.
     *
     * @return numeric-string
     */
    private function money(mixed $value, int $scale): string
    {
        $raw = is_scalar($value) ? (string) $value : '0';

        return is_numeric($raw) ? CurrencyScale::bcround($raw, $scale) : bcadd('0', '0', $scale);
    }
}
