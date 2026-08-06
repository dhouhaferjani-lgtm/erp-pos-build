<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\Enums\CashMovementDirection;
use App\Modules\Accounting\Application\Enums\CashMovementSourceType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Cash movements across the two sources that can move cash: completed payments
 * and posted journal lines on a cash GL account, unioned and de-duplicated.
 *
 * ── "BRANCH" MEANS TWO DIFFERENT THINGS ON THE TWO LEGS ────────────────────
 * There is no single location column behind this report, so `location_ids[]`
 * resolves against a different dimension per leg. Read this before changing
 * either predicate.
 *
 *  - PAYMENTS leg → `payments.location_id`, i.e. the **document's** branch:
 *    the terminal for a POS receipt (TreasuryReceiptBridge), otherwise the
 *    location of the first allocated document (PaymentController.php:596-607).
 *    NULL for a pure advance, by design.
 *  - JOURNAL-LINES leg → `payment_repositories.location_id`, i.e. the
 *    **register's** branch. `journal_entries.location_id` exists in the schema
 *    but is never written (the column is absent from the JournalEntry model),
 *    so it is reserved, not authoritative — see the P2 ticket to populate it at
 *    the posting sites and drop this indirection.
 *
 * For one physical cash move the two answers can differ (cash paid against a
 * Shop-B document into a Shop-A register). That is safe because the legs never
 * both emit the same move: the scope-INDEPENDENT de-duplication below drops a
 * journal line whenever a payment row already represents it. So a move is
 * counted at most once overall, under the document's branch when it is
 * payment-backed and under the register's branch when it is not.
 *
 * Ambiguity is resolved FAIL-CLOSED rather than by duplication: cash that no
 * single branch owns — a NULL location, or a GL account shared by registers in
 * several branches — is withheld from a strict branch scope and shown only on
 * the unrestricted read. Σ over the branch scopes is therefore a sub-total of
 * the unscoped figure, never a multiple of it.
 */
final readonly class CashMovementsReportService
{
    /**
     * @var list<string>
     */
    private const INCOMING_PAYMENT_TYPES = [
        PaymentType::DocumentPayment->value,
        PaymentType::Advance->value,
        PaymentType::POS->value,
    ];

    /**
     * @var list<string>
     */
    private const OUTGOING_PAYMENT_TYPES = [
        PaymentType::Refund->value,
        PaymentType::SupplierPayment->value,
    ];

    /**
     * @var list<string>
     */
    private const PAYMENT_BACKED_SOURCE_TYPES = [
        CashMovementSourceType::Payment->value,
        CashMovementSourceType::CustomerPayment->value,
        CashMovementSourceType::SupplierPayment->value,
        'advance',
        'supplier_advance_refund',
    ];

    /**
     * @var list<string>
     */
    private const CASH_REPOSITORY_TYPES = [
        RepositoryType::CashRegister->value,
        RepositoryType::Safe->value,
        RepositoryType::BankAccount->value,
    ];

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  list<string>  $locationIds  Effective location scope resolved by
     *                                     ReportsController::reportLocationScope().
     *                                     An EMPTY list means "unrestricted" and
     *                                     applies no location predicate at all,
     *                                     so company-level (NULL-location) cash
     *                                     stays visible — the same convention the
     *                                     aged-* reports use.
     * @return array{
     *     data: list<array{
     *         date: string,
     *         direction: string,
     *         amount: string,
     *         currency: string,
     *         source_type: string,
     *         source_id: string,
     *         counterparty: string|null,
     *         gl_account: string
     *     }>,
     *     meta: array{
     *         current_page: int,
     *         per_page: int,
     *         total: int,
     *         last_page: int,
     *         from: int|null,
     *         to: int|null,
     *         totals: array<string, array{in: string, out: string, net: string}>
     *     }
     * }
     */
    public function generate(
        string $companyId,
        string $companyCurrency,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
        ?string $direction,
        array $locationIds,
        int $page,
        int $perPage,
    ): array {
        $union = $this->paymentsQuery($companyId, $from, $to, $repositoryId, $locationIds)
            ->unionAll($this->journalLinesQuery($companyId, $companyCurrency, $from, $to, $repositoryId, $locationIds));

        $base = DB::query()->fromSub($union, 'cash_movements');

        if ($direction !== null) {
            $base->where('direction', $direction);
        }

        $total = (clone $base)->count();
        $lastPage = $total === 0 ? 1 : (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $totalsRows = (clone $base)
            ->selectRaw('currency, direction, SUM(CAST(amount AS NUMERIC)) AS total')
            ->groupBy('currency', 'direction')
            ->get();

        $totals = [];
        foreach ($totalsRows as $row) {
            $currency = (string) $row->currency;
            $scale = $this->scaleResolver->getScale($currency);
            $totals[$currency] ??= [
                'in' => CurrencyScale::bcformatStrict('0', $scale),
                'out' => CurrencyScale::bcformatStrict('0', $scale),
                'net' => CurrencyScale::bcformatStrict('0', $scale),
            ];

            $rowDirection = (string) $row->direction;
            if ($rowDirection === CashMovementDirection::In->value) {
                $totals[$currency]['in'] = CurrencyScale::bcformatStrict((string) $row->total, $scale);
            } elseif ($rowDirection === CashMovementDirection::Out->value) {
                $totals[$currency]['out'] = CurrencyScale::bcformatStrict((string) $row->total, $scale);
            }
        }

        foreach ($totals as $currency => &$currencyTotals) {
            $scale = $this->scaleResolver->getScale($currency);
            $currencyTotals['net'] = CurrencyScale::bcformatStrict(
                bcsub($currencyTotals['in'], $currencyTotals['out'], $scale + 1),
                $scale,
            );
        }
        unset($currencyTotals);

        $rows = $base
            ->orderByDesc('date')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->formatRow($row);
        }

        $firstItem = $total === 0 ? null : $offset + 1;
        $lastItem = $total === 0 ? null : min($offset + $perPage, $total);

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $firstItem,
                'to' => $lastItem,
                'totals' => $totals,
            ],
        ];
    }

    /**
     * A payment carries its OWN location dimension (`payments.location_id`,
     * written by the POS terminal bridge and by the document-attribution path),
     * so it is scoped on that column — the same shape the aged-* reports use on
     * `documents.location_id` / `payment_instruments.location_id`.
     *
     * @param  list<string>  $locationIds
     */
    private function paymentsQuery(
        string $companyId,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
        array $locationIds = [],
    ): Builder {
        $query = DB::table('payments')
            ->join('payment_repositories', 'payment_repositories.id', '=', 'payments.repository_id')
            ->join('accounts', 'accounts.id', '=', 'payment_repositories.gl_account_id')
            ->leftJoin('partners', function (JoinClause $join) use ($companyId): void {
                $join->on('partners.id', '=', 'payments.partner_id')
                    ->where('partners.company_id', $companyId);
            })
            ->where('payments.company_id', $companyId)
            ->where('payment_repositories.company_id', $companyId)
            ->where('accounts.company_id', $companyId)
            ->where('payments.status', PaymentStatus::Completed->value)
            ->whereIn('payments.payment_type', $this->movingPaymentTypes())
            ->whereIn('payment_repositories.type', self::CASH_REPOSITORY_TYPES)
            ->whereNotNull('payment_repositories.gl_account_id')
            ->selectRaw('payments.payment_date as date')
            ->selectRaw(
                'CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM journal_entries as direction_entries
                        JOIN journal_lines as direction_lines
                            ON direction_lines.journal_entry_id = direction_entries.id
                        WHERE direction_entries.company_id = payments.company_id
                            AND direction_entries.status = ?
                            AND direction_entries.source_id = payments.id
                            AND direction_entries.source_type IN (?, ?, ?, ?, ?)
                            AND direction_lines.account_id = payment_repositories.gl_account_id
                            AND direction_lines.debit > 0
                    ) THEN ?
                    WHEN EXISTS (
                        SELECT 1
                        FROM journal_entries as direction_entries
                        JOIN journal_lines as direction_lines
                            ON direction_lines.journal_entry_id = direction_entries.id
                        WHERE direction_entries.company_id = payments.company_id
                            AND direction_entries.status = ?
                            AND direction_entries.source_id = payments.id
                            AND direction_entries.source_type IN (?, ?, ?, ?, ?)
                            AND direction_lines.account_id = payment_repositories.gl_account_id
                            AND direction_lines.credit > 0
                    ) THEN ?
                    WHEN EXISTS (
                        SELECT 1
                        FROM journal_entries as refund_entries
                        WHERE refund_entries.id = payments.journal_entry_id
                            AND refund_entries.company_id = payments.company_id
                            AND refund_entries.status = ?
                            AND refund_entries.source_type = ?
                    ) THEN ?
                    WHEN payments.payment_type IN (?, ?) THEN ?
                    ELSE ?
                END as direction',
                [
                    JournalEntryStatus::Posted->value,
                    ...self::PAYMENT_BACKED_SOURCE_TYPES,
                    CashMovementDirection::In->value,
                    JournalEntryStatus::Posted->value,
                    ...self::PAYMENT_BACKED_SOURCE_TYPES,
                    CashMovementDirection::Out->value,
                    // A POS refund leg keeps payment_type=POS (indistinguishable
                    // from a sale on that column), so it would fall to ELSE=In
                    // and report a phantom inflow. It is linked via
                    // journal_entry_id to its `pos_receipt_refund` reversal
                    // entry — resolve that leg to Out.
                    JournalEntryStatus::Posted->value,
                    CashMovementSourceType::PosReceiptRefund->value,
                    CashMovementDirection::Out->value,
                    PaymentType::Refund->value,
                    PaymentType::SupplierPayment->value,
                    CashMovementDirection::Out->value,
                    CashMovementDirection::In->value,
                ],
            )
            ->selectRaw('CAST(payments.amount AS TEXT) as amount')
            ->selectRaw('payments.currency as currency')
            ->selectRaw('? as source_type', [CashMovementSourceType::Payment->value])
            ->selectRaw('payments.id as source_id')
            ->selectRaw('partners.name as counterparty')
            ->selectRaw('accounts.code as gl_account');

        $this->applyPaymentFilters($query, $from, $to, $repositoryId, 'payments');

        if ($locationIds !== []) {
            $query->whereIn('payments.location_id', $locationIds);
        }

        return $query;
    }

    /**
     * `journal_entries.location_id` exists in the schema but is never written
     * (the column is not on the JournalEntry model at all), so scoping this leg
     * on it would erase every non-payment cash line under any branch scope.
     * The leg is therefore scoped exactly the way its `repository_id` filter
     * already works — through the `payment_repositories` row that owns the GL
     * account — which is also how CashPositionController attributes cash to a
     * branch (`payment_repositories.location_id`).
     *
     * The de-duplication `whereNotExists` clauses below are deliberately NOT
     * given the location predicate: they answer "is this GL line already
     * represented by a payment row?", a scope-independent question. Passing the
     * scope there would let a payment excluded from the payments leg reappear as
     * its GL twin under a different branch.
     *
     * @param  list<string>  $locationIds
     */
    private function journalLinesQuery(
        string $companyId,
        string $companyCurrency,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
        array $locationIds = [],
    ): Builder {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->leftJoin('partners', function (JoinClause $join) use ($companyId): void {
                $join->on('partners.id', '=', 'journal_lines.partner_id')
                    ->where('partners.company_id', $companyId);
            })
            ->where('journal_entries.company_id', $companyId)
            ->where('accounts.company_id', $companyId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where(function (Builder $query): void {
                $query->where('journal_lines.debit', '>', '0')
                    ->orWhere('journal_lines.credit', '>', '0');
            })
            ->whereExists(function (Builder $query) use ($companyId, $repositoryId, $locationIds): void {
                $query->selectRaw('1')
                    ->from('payment_repositories')
                    ->whereColumn('payment_repositories.gl_account_id', 'journal_lines.account_id')
                    ->where('payment_repositories.company_id', $companyId)
                    ->whereIn('payment_repositories.type', self::CASH_REPOSITORY_TYPES);

                if ($repositoryId !== null) {
                    $query->where('payment_repositories.id', $repositoryId);
                }

                if ($locationIds !== []) {
                    // A deactivated register is not a branch owner: it must not
                    // keep granting its location visibility of the GL account.
                    // Mirrors CashPositionController.php:87.
                    $query->where('payment_repositories.is_active', true)
                        ->whereIn('payment_repositories.location_id', $locationIds);
                }
            })
            // FAIL-CLOSED on an ambiguous cash GL account (authz gate
            // 2026-08-06, CRITICAL). `payment_repositories.gl_account_id` is
            // many-to-one — indexed, never unique — and both provisioning paths
            // (the `2026_03_02_400000` backfill and `PaymentRepositorySeeder`)
            // point EVERY cash_register/safe at the single company-wide
            // `SystemAccountPurpose::Cash` account. Without this clause the
            // EXISTS above matches some in-scope register for every branch, so
            // one company-level cash line (a petty-cash `expense_settlement`,
            // which no payment row backs) was emitted IN FULL under all four of
            // tenant #1's branches: a 4x overstatement that also broke the
            // disjointness and Σ ≤ All invariants MTP-MLC-08 asserts.
            //
            // A journal line is admitted under a strict scope only when EVERY
            // active cash register owning its GL account is inside that scope.
            // Shared/ambiguous cash therefore behaves exactly like NULL-location
            // cash — hidden under a branch scope, visible unscoped — instead of
            // being replicated per branch.
            ->whereNotExists(function (Builder $query) use ($companyId, $locationIds): void {
                if ($locationIds === []) {
                    // Unrestricted read: no location predicate at all, so there
                    // is nothing to be ambiguous about. The emitted SQL is not
                    // byte-identical to the pre-lane query — it gains
                    // `and not exists (select 1 where 1 = 0)` — but it is
                    // SEMANTICALLY unchanged: a constant-false no-op that PG
                    // folds to a `One-Time Filter: false` evaluated once at zero
                    // cost (verified on PG 16.10 by the authz gate, NOTE-1).
                    //
                    // It must stay a real, always-empty subquery rather than an
                    // omitted clause: writing it as a correlated NOT EXISTS that
                    // happens to match nothing would re-open the NULL trap that
                    // `NOT IN` has, and dropping the clause entirely here would
                    // put the two branches on different query shapes.
                    $query->selectRaw('1')->whereRaw('1 = 0');

                    return;
                }

                $query->selectRaw('1')
                    ->from('payment_repositories as competing_repositories')
                    ->whereColumn('competing_repositories.gl_account_id', 'journal_lines.account_id')
                    ->where('competing_repositories.company_id', $companyId)
                    ->where('competing_repositories.is_active', true)
                    ->whereIn('competing_repositories.type', self::CASH_REPOSITORY_TYPES)
                    ->where(function (Builder $query) use ($locationIds): void {
                        $query->whereNull('competing_repositories.location_id')
                            ->orWhereNotIn('competing_repositories.location_id', $locationIds);
                    });
            })
            ->whereNotExists(function (Builder $query) use ($companyId, $from, $to, $repositoryId): void {
                $query->selectRaw('1')
                    ->from('payments as represented_payments')
                    ->whereColumn('represented_payments.id', 'journal_entries.source_id')
                    ->whereIn('journal_entries.source_type', self::PAYMENT_BACKED_SOURCE_TYPES)
                    ->where('represented_payments.company_id', $companyId)
                    ->where('represented_payments.status', PaymentStatus::Completed->value)
                    ->whereIn('represented_payments.payment_type', $this->movingPaymentTypes());

                $this->applyPaymentFilters($query, $from, $to, $repositoryId, 'represented_payments');
            })
            ->whereNotExists(function (Builder $query) use ($companyId, $from, $to, $repositoryId): void {
                $query->selectRaw('1')
                    ->from('pos_receipts')
                    ->join('payments as represented_pos_payments', 'represented_pos_payments.fiscal_event_id', '=', 'pos_receipts.fiscal_event_id')
                    ->whereColumn('pos_receipts.id', 'journal_entries.source_id')
                    // Suppress BOTH the sale GL line (`pos_receipt`) and the
                    // refund reversal GL line (`pos_receipt_refund`): each is
                    // already represented by its POS Payment leg on the
                    // payments side, so emitting the GL line too would
                    // double-count the cash move.
                    ->whereIn('journal_entries.source_type', [
                        CashMovementSourceType::PosReceipt->value,
                        CashMovementSourceType::PosReceiptRefund->value,
                    ])
                    ->whereNotNull('pos_receipts.fiscal_event_id')
                    ->where('pos_receipts.company_id', $companyId)
                    ->where('represented_pos_payments.company_id', $companyId)
                    ->where('represented_pos_payments.status', PaymentStatus::Completed->value)
                    ->whereIn('represented_pos_payments.payment_type', $this->movingPaymentTypes());

                $this->applyPaymentFilters($query, $from, $to, $repositoryId, 'represented_pos_payments');
            })
            ->selectRaw('journal_entries.entry_date as date')
            ->selectRaw(
                'CASE WHEN journal_lines.debit > 0 THEN ? ELSE ? END as direction',
                [CashMovementDirection::In->value, CashMovementDirection::Out->value],
            )
            ->selectRaw('CAST(CASE WHEN journal_lines.debit > 0 THEN journal_lines.debit ELSE journal_lines.credit END AS TEXT) as amount')
            ->selectRaw('? as currency', [$companyCurrency])
            ->selectRaw("COALESCE(journal_entries.source_type, 'journal_entry') as source_type")
            ->selectRaw('COALESCE(journal_entries.source_id, journal_entries.id) as source_id')
            ->selectRaw('COALESCE(partners.name, journal_lines.description, journal_entries.description) as counterparty')
            ->selectRaw('accounts.code as gl_account');

        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $to->toDateString());
        }

        return $query;
    }

    private function applyPaymentFilters(
        Builder $query,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
        string $table,
    ): void {
        if ($from !== null) {
            $query->whereDate("{$table}.payment_date", '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->whereDate("{$table}.payment_date", '<=', $to->toDateString());
        }

        if ($repositoryId !== null) {
            $query->where("{$table}.repository_id", $repositoryId);
        }
    }

    /**
     * @return list<string>
     */
    private function movingPaymentTypes(): array
    {
        return [
            ...self::INCOMING_PAYMENT_TYPES,
            ...self::OUTGOING_PAYMENT_TYPES,
        ];
    }

    /**
     * @return array{
     *     date: string,
     *     direction: string,
     *     amount: string,
     *     currency: string,
     *     source_type: string,
     *     source_id: string,
     *     counterparty: string|null,
     *     gl_account: string
     * }
     */
    private function formatRow(stdClass $row): array
    {
        $currency = (string) $row->currency;
        $scale = $this->scaleResolver->getScale($currency);

        return [
            'date' => CarbonImmutable::parse((string) $row->date)->toDateString(),
            'direction' => (string) $row->direction,
            'amount' => CurrencyScale::bcformatStrict((string) $row->amount, $scale),
            'currency' => $currency,
            'source_type' => (string) $row->source_type,
            'source_id' => (string) $row->source_id,
            'counterparty' => $row->counterparty === null ? null : (string) $row->counterparty,
            'gl_account' => (string) $row->gl_account,
        ];
    }
}
