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
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}
     * }
     */
    public function generate(
        string $companyId,
        string $companyCurrency,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
        int $page,
        int $perPage,
    ): array {
        $union = $this->paymentsQuery($companyId, $from, $to, $repositoryId)
            ->unionAll($this->journalLinesQuery($companyId, $companyCurrency, $from, $to, $repositoryId));

        $base = DB::query()->fromSub($union, 'cash_movements');
        $total = (clone $base)->count();
        $lastPage = $total === 0 ? 1 : (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

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
            ],
        ];
    }

    private function paymentsQuery(
        string $companyId,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
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

        return $query;
    }

    private function journalLinesQuery(
        string $companyId,
        string $companyCurrency,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $repositoryId,
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
            ->whereExists(function (Builder $query) use ($companyId, $repositoryId): void {
                $query->selectRaw('1')
                    ->from('payment_repositories')
                    ->whereColumn('payment_repositories.gl_account_id', 'journal_lines.account_id')
                    ->where('payment_repositories.company_id', $companyId)
                    ->whereIn('payment_repositories.type', self::CASH_REPOSITORY_TYPES);

                if ($repositoryId !== null) {
                    $query->where('payment_repositories.id', $repositoryId);
                }
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
                    ->where('journal_entries.source_type', CashMovementSourceType::PosReceipt->value)
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
