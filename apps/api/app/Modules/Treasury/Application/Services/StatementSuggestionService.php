<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\StatementSuggestion;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Events\ExpenseStatementSuggestionsRequested;
use App\Modules\Treasury\Domain\InstrumentRemittance;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Modules\Treasury\Domain\StatementImportProfile;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class StatementSuggestionService
{
    /**
     * Belt-and-suspenders ceiling on movement rows hydrated for a single
     * suggestion pass.
     *
     * Correctness never depends on this cap: Tier-1 candidates are bounded in
     * SQL by a reference predicate (a reference is a near-unique token, so the
     * set is naturally tiny), and Tier-2 uniqueness is resolved with exact SQL
     * predicates rather than by scanning a truncated hydration. The cap only
     * guards against a pathological reference collision or an implausibly large
     * partially-allocated in-window slice; if it is ever reached the service
     * degrades to NO suggestion (never a wrong one).
     */
    public const MAX_CANDIDATE_MOVEMENTS = 500;

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
        private CardBatchResolver $cardBatches,
    ) {}

    /** @return list<StatementSuggestion> */
    public function suggest(string $lineId): array
    {
        $line = BankStatementLine::query()->find($lineId);
        if (! $line instanceof BankStatementLine) {
            throw new DomainException('Statement line was not found.');
        }
        $statement = BankStatement::query()->find($line->bank_statement_id);
        if (! $statement instanceof BankStatement) {
            throw new DomainException('Statement line parent was not found.');
        }
        if ($line->match_status === StatementLineMatchStatus::Ignored) {
            return [];
        }

        $scale = $this->scaleResolver->getScale($statement->currency);
        $remainingLine = $this->remainingLineAmount($line, $statement, $scale);
        if (bccomp($remainingLine, '0', $scale) <= 0) {
            return [];
        }
        $profile = $statement->parser_profile_id === null
            ? null
            : StatementImportProfile::query()->find($statement->parser_profile_id);
        $windowDays = $profile instanceof StatementImportProfile ? $profile->matching_window_days : 5;
        $windowStart = $line->value_date->subDays($windowDays)->toDateString();
        $windowEnd = $line->value_date->addDays($windowDays)->toDateString();
        $lineText = $this->normalize(implode(' ', array_filter([
            $line->label,
            $line->reference,
            $line->bank_transaction_id,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '')));
        $suggestions = [];
        $tierOneMovementIds = [];

        // Tier 1 — reference + exact remaining amount. Window-INDEPENDENT: a
        // reference can clear the bank long after its movement date, so the
        // candidate set is bounded in SQL by the reference predicate, never by a
        // capped date scan. The exact containment is re-verified here in PHP, so
        // a valid reference match can never be silently dropped by a cap.
        $referenceCandidates = $this->referenceMatchedMovements(
            $statement,
            $line->direction,
            $remainingLine,
            $lineText,
            $scale,
        );
        $references = $this->movementReferences($referenceCandidates);
        foreach ($referenceCandidates as $candidate) {
            $movement = $candidate['movement'];
            if (bccomp($candidate['remaining'], $remainingLine, $scale) !== 0
                || ! $this->referenceMatches($lineText, $references[$movement->id] ?? [])) {
                continue;
            }
            $tierOneMovementIds[$movement->id] = true;
            $suggestions[] = new StatementSuggestion(
                tier: 1,
                kind: 'movement',
                movementIds: [$movement->id],
                actionType: null,
                targetType: $movement->source_type->value,
                targetId: $movement->source_id,
                amount: $candidate['remaining'],
                reason: 'Reference and remaining amount match.',
                reasonCode: 'reference_amount_match',
                referenceMatched: true,
            );
        }

        // Tier 2 — unique remaining amount strictly inside the window. Resolved
        // with exact SQL predicates (never a capped hydration) so the perf bound
        // can neither manufacture a false "unique" nor omit the correct
        // candidate.
        $uniqueCandidate = $this->uniqueWindowCandidate(
            $statement,
            $line->direction,
            $windowStart,
            $windowEnd,
            $remainingLine,
            $scale,
        );
        if ($uniqueCandidate !== null && ! isset($tierOneMovementIds[$uniqueCandidate['id']])) {
            $suggestions[] = new StatementSuggestion(
                tier: 2,
                kind: 'movement',
                movementIds: [$uniqueCandidate['id']],
                actionType: null,
                targetType: $uniqueCandidate['source_type'],
                targetId: $uniqueCandidate['source_id'],
                amount: $remainingLine,
                reason: "Unique remaining amount inside ±{$windowDays} days.",
                reasonCode: 'unique_amount_window',
                reasonParams: ['days' => $windowDays],
            );
        }

        $suggestions = [
            ...$suggestions,
            ...$this->instrumentSuggestions($statement, $line, $remainingLine, $windowStart, $windowEnd),
            ...$this->expenseSuggestions($statement, $remainingLine, $windowStart, $windowEnd, $lineText),
            ...$this->cardBatchSuggestions($statement, $line, $remainingLine, $scale),
        ];
        usort($suggestions, static function (StatementSuggestion $left, StatementSuggestion $right): int {
            return [
                $left->tier,
                $left->referenceMatched ? 0 : 1,
                $left->targetId ?? ($left->movementIds[0] ?? ''),
                json_encode($left->actionParams, JSON_THROW_ON_ERROR),
            ] <=> [
                $right->tier,
                $right->referenceMatched ? 0 : 1,
                $right->targetId ?? ($right->movementIds[0] ?? ''),
                json_encode($right->actionParams, JSON_THROW_ON_ERROR),
            ];
        });

        return $suggestions;
    }

    /** @return numeric-string */
    private function remainingLineAmount(BankStatementLine $line, BankStatement $statement, int $scale): string
    {
        $allocations = BankStatementLineAllocation::query()
            ->where('bank_statement_line_id', $line->id)
            ->orderBy('repository_movement_id')
            ->get();
        $movementIds = $allocations
            ->map(static fn (BankStatementLineAllocation $allocation): string => $allocation->repository_movement_id)
            ->unique()
            ->values()
            ->all();
        $movements = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->whereIn('id', $movementIds)
            ->get()
            ->keyBy('id');
        if ($movements->count() !== count($movementIds)) {
            throw new DomainException('A statement allocation references a movement outside its repository.');
        }
        $matched = CurrencyScale::bcformatStrict('0', $scale);
        foreach ($allocations as $allocation) {
            $movement = $movements->get($allocation->repository_movement_id);
            if (! $movement instanceof RepositoryMovement) {
                throw new DomainException('A statement allocation movement was not found.');
            }
            $matched = $movement->direction === $line->direction
                ? bcadd($matched, $allocation->matched_amount, $scale)
                : bcsub($matched, $allocation->matched_amount, $scale);
        }

        return bcsub($line->amount, $matched, $scale);
    }

    /**
     * Movements that can produce a Tier-1 "reference and remaining amount match".
     *
     * Tier-1 fires only when a movement's payment/instrument/remittance reference
     * (or free-text notes) appears in the line text, so the candidate set is
     * bounded in SQL by that reference predicate — a reference is a near-unique
     * token, so this is naturally tiny — NOT by date. Tier-1 is window-independent
     * because a reference can clear the bank long after its movement date (see
     * StatementSuggestionServiceTest::test_reference_hit...). The exact
     * containment is re-verified in PHP by the caller (referenceMatches), so the
     * SQL predicate only has to avoid false negatives; {@see self::MAX_CANDIDATE_MOVEMENTS}
     * is a belt-and-suspenders ceiling that a realistic line never approaches.
     *
     * @param  numeric-string  $remainingLine
     * @return list<array{movement: RepositoryMovement, remaining: numeric-string}>
     */
    private function referenceMatchedMovements(
        BankStatement $statement,
        MovementDirection $direction,
        string $remainingLine,
        string $lineText,
        int $scale,
    ): array {
        if (trim($lineText) === '') {
            return [];
        }

        $paymentQuery = Payment::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id);
        $this->whereReferenceContains($paymentQuery, 'reference', $lineText);
        $paymentIds = $paymentQuery->pluck('id')->all();

        $fiscalQuery = Payment::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereNotNull('fiscal_event_id');
        $this->whereReferenceContains($fiscalQuery, 'reference', $lineText);
        $fiscalEventIds = $fiscalQuery->pluck('fiscal_event_id')->filter()->values()->all();

        $remittanceQuery = InstrumentRemittance::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id);
        $this->whereReferenceContains($remittanceQuery, 'number', $lineText);
        $remittanceIds = $remittanceQuery->pluck('id')->all();

        $instrumentIds = PaymentInstrument::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where(function (Builder $query) use ($lineText, $remittanceIds): void {
                $this->whereReferenceContains($query, 'reference', $lineText);
                if ($remittanceIds !== []) {
                    $query->orWhereIn('remittance_id', $remittanceIds);
                }
            })
            ->pluck('id')
            ->all();

        $movements = $this->candidateBaseQuery($statement, $direction, $remainingLine)
            ->where(function (Builder $query) use ($lineText, $paymentIds, $fiscalEventIds, $instrumentIds): void {
                $this->whereReferenceContains($query, 'notes', $lineText);
                if ($paymentIds !== []) {
                    $query->orWhere(function (Builder $inner) use ($paymentIds): void {
                        $inner->whereIn('source_type', [
                            MovementSourceType::Payment->value,
                            MovementSourceType::Refund->value,
                        ])->whereIn('source_id', $paymentIds);
                    });
                }
                if ($fiscalEventIds !== []) {
                    $query->orWhere(function (Builder $inner) use ($fiscalEventIds): void {
                        $inner->where('source_type', MovementSourceType::FiscalEvent->value)
                            ->whereIn('source_id', $fiscalEventIds);
                    });
                }
                if ($instrumentIds !== []) {
                    $query->orWhere(function (Builder $inner) use ($instrumentIds): void {
                        $inner->where('source_type', MovementSourceType::Instrument->value)
                            ->whereIn('source_id', $instrumentIds);
                    });
                }
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATE_MOVEMENTS)
            ->get();

        return $this->withRemaining($movements, $scale);
    }

    /**
     * The single movement whose remaining equals the line's remaining and whose
     * date falls inside the matching window — or null when there is none or more
     * than one (uniqueness is the Tier-2 requirement).
     *
     * Resolved exactly and precision-safely WITHOUT hydrating a capped set, so
     * the perf bound can neither manufacture a false "unique" nor omit the
     * correct candidate:
     *  - fully-unallocated movements match iff amount == remainingLine (an exact
     *    equality on the stored decimal column — no float arithmetic in SQL,
     *    honouring the precision contract); a LIMIT 2 tells us 0 / 1 / many;
     *  - partially-allocated movements are few (each must own an allocation row),
     *    so they are hydrated and their remaining compared with bcmath. If that
     *    slice is implausibly large the cap trips and Tier-2 is suppressed rather
     *    than risk a wrong suggestion.
     *
     * @param  numeric-string  $remainingLine
     * @return array{id: string, source_type: string, source_id: string}|null
     */
    private function uniqueWindowCandidate(
        BankStatement $statement,
        MovementDirection $direction,
        string $windowStart,
        string $windowEnd,
        string $remainingLine,
        int $scale,
    ): ?array {
        $allocated = $this->inWindowQuery($statement, $direction, $windowStart, $windowEnd)
            ->where('amount', '>', $remainingLine)
            ->whereHas('statementAllocations')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATE_MOVEMENTS + 1)
            ->get();
        if ($allocated->count() > self::MAX_CANDIDATE_MOVEMENTS) {
            return null;
        }
        $allocatedMatches = $this->withRemaining($allocated, $scale, $remainingLine);

        $unallocated = $this->inWindowQuery($statement, $direction, $windowStart, $windowEnd)
            ->where('amount', $remainingLine)
            ->whereDoesntHave('statementAllocations')
            ->orderBy('id')
            ->limit(2)
            ->get();

        $matches = [
            ...array_map(
                static fn (array $candidate): array => [
                    'id' => $candidate['movement']->id,
                    'source_type' => $candidate['movement']->source_type->value,
                    'source_id' => $candidate['movement']->source_id,
                ],
                $allocatedMatches,
            ),
            ...$unallocated->map(static fn (RepositoryMovement $movement): array => [
                'id' => $movement->id,
                'source_type' => $movement->source_type->value,
                'source_id' => $movement->source_id,
            ])->all(),
        ];

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * In-window, same-direction movements in the statement's repository/currency.
     * The date bound uses whereDate so it matches the PHP `occurred_at->toDateString()`
     * comparison exactly (inclusive calendar-day boundaries).
     *
     * @return Builder<RepositoryMovement>
     */
    private function inWindowQuery(
        BankStatement $statement,
        MovementDirection $direction,
        string $windowStart,
        string $windowEnd,
    ): Builder {
        return RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('currency', $statement->currency)
            ->where('direction', $direction->value)
            ->whereDate('occurred_at', '>=', $windowStart)
            ->whereDate('occurred_at', '<=', $windowEnd);
    }

    /**
     * Base candidate query for the Tier-1 reference load: same repository,
     * currency and direction, with the gross amount ≥ the line's remaining (a
     * necessary condition for any exact-remaining match — pure narrowing that
     * cannot drop a real candidate).
     *
     * @param  numeric-string  $remainingLine
     * @return Builder<RepositoryMovement>
     */
    private function candidateBaseQuery(
        BankStatement $statement,
        MovementDirection $direction,
        string $remainingLine,
    ): Builder {
        return RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('currency', $statement->currency)
            ->where('direction', $direction->value)
            ->where('amount', '>=', $remainingLine);
    }

    /**
     * Add "the trimmed, lower-cased $column value (min length 3) occurs as a
     * substring of the normalized line text" as a boolean predicate. Mirrors the
     * PHP referenceMatches() containment. $column is always a hard-coded literal
     * ('reference' / 'number' / 'notes'), never user input.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function whereReferenceContains(Builder $query, string $column, string $lineText): void
    {
        $query->whereRaw(
            '(length(trim(lower('.$column.'))) >= 3 and ? like '."'%' || trim(lower(".$column.")) || '%')",
            [$lineText],
        );
    }

    /**
     * Compute each movement's remaining capacity (gross − allocated) with bcmath
     * and keep only those still open. When $onlyRemaining is given, keep only the
     * movements whose remaining equals it exactly.
     *
     * @param  EloquentCollection<int, RepositoryMovement>  $movements
     * @param  numeric-string|null  $onlyRemaining
     * @return list<array{movement: RepositoryMovement, remaining: numeric-string}>
     */
    private function withRemaining(EloquentCollection $movements, int $scale, ?string $onlyRemaining = null): array
    {
        $ids = $movements->map(static fn (RepositoryMovement $movement): string => $movement->id)->all();
        $totals = [];
        $allocations = BankStatementLineAllocation::query()
            ->whereIn('repository_movement_id', $ids)
            ->orderBy('repository_movement_id')
            ->orderBy('id')
            ->get();
        foreach ($allocations as $allocation) {
            $totals[$allocation->repository_movement_id] = bcadd(
                $totals[$allocation->repository_movement_id] ?? CurrencyScale::bcformatStrict('0', $scale),
                $allocation->matched_amount,
                $scale,
            );
        }

        $eligible = [];
        foreach ($movements as $movement) {
            $remaining = bcsub(
                $movement->amount,
                $totals[$movement->id] ?? CurrencyScale::bcformatStrict('0', $scale),
                $scale,
            );
            if (bccomp($remaining, '0', $scale) <= 0) {
                continue;
            }
            if ($onlyRemaining !== null && bccomp($remaining, $onlyRemaining, $scale) !== 0) {
                continue;
            }
            $eligible[] = ['movement' => $movement, 'remaining' => $remaining];
        }

        return $eligible;
    }

    /**
     * @param  list<array{movement: RepositoryMovement, remaining: numeric-string}>  $eligible
     * @return array<string, list<string>>
     */
    private function movementReferences(array $eligible): array
    {
        $movements = collect($eligible)->pluck('movement');
        $paymentIds = $movements
            ->filter(static fn (RepositoryMovement $movement): bool => in_array(
                $movement->source_type,
                [MovementSourceType::Payment, MovementSourceType::Refund],
                true,
            ))
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();
        $fiscalEventIds = $movements
            ->filter(static fn (RepositoryMovement $movement): bool => $movement->source_type === MovementSourceType::FiscalEvent)
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();
        $instrumentIds = $movements
            ->filter(static fn (RepositoryMovement $movement): bool => $movement->source_type === MovementSourceType::Instrument)
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();
        $payments = Payment::query()->whereIn('id', $paymentIds)->get()->keyBy('id');
        $fiscalPayments = Payment::query()->whereIn('fiscal_event_id', $fiscalEventIds)->get()->keyBy('fiscal_event_id');
        $instruments = PaymentInstrument::query()->whereIn('id', $instrumentIds)->get()->keyBy('id');
        $remittanceIds = $instruments->pluck('remittance_id')->filter()->unique()->values()->all();
        $remittances = InstrumentRemittance::query()->whereIn('id', $remittanceIds)->get()->keyBy('id');
        $references = [];

        foreach ($movements as $movement) {
            $values = array_filter([$movement->notes], is_string(...));
            if (in_array($movement->source_type, [MovementSourceType::Payment, MovementSourceType::Refund], true)) {
                $payment = $payments->get($movement->source_id);
                if ($payment instanceof Payment && is_string($payment->reference)) {
                    $values[] = $payment->reference;
                }
            } elseif ($movement->source_type === MovementSourceType::FiscalEvent) {
                $payment = $fiscalPayments->get($movement->source_id);
                if ($payment instanceof Payment && is_string($payment->reference)) {
                    $values[] = $payment->reference;
                }
            } elseif ($movement->source_type === MovementSourceType::Instrument) {
                $instrument = $instruments->get($movement->source_id);
                if ($instrument instanceof PaymentInstrument) {
                    $values[] = $instrument->reference;
                    $remittance = $instrument->remittance_id === null
                        ? null
                        : $remittances->get($instrument->remittance_id);
                    if ($remittance instanceof InstrumentRemittance) {
                        $values[] = $remittance->number;
                    }
                }
            }
            $references[$movement->id] = $values;
        }

        return $references;
    }

    /**
     * @param  numeric-string  $amount
     * @return list<StatementSuggestion>
     */
    private function instrumentSuggestions(
        BankStatement $statement,
        BankStatementLine $line,
        string $amount,
        string $windowStart,
        string $windowEnd,
    ): array {
        $direction = $line->direction === MovementDirection::Out
            ? InstrumentDirection::Outbound
            : InstrumentDirection::Inbound;
        $statuses = $direction === InstrumentDirection::Outbound
            ? [InstrumentStatus::Received, InstrumentStatus::Bounced]
            : [InstrumentStatus::Deposited];
        $instruments = PaymentInstrument::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('currency', $statement->currency)
            ->where('amount', $amount)
            ->where('direction', $direction->value)
            ->whereIn('status', array_map(static fn (InstrumentStatus $status): string => $status->value, $statuses))
            ->when(
                $direction === InstrumentDirection::Outbound,
                fn ($query) => $query->where('repository_id', $statement->payment_repository_id),
                fn ($query) => $query->where('deposited_to_id', $statement->payment_repository_id),
            )
            ->orderBy('id')
            ->get();
        $suggestions = [];
        foreach ($instruments as $instrument) {
            $candidateDate = ($instrument->maturity_date ?? $instrument->received_date)->toDateString();
            if ($candidateDate < $windowStart || $candidateDate > $windowEnd) {
                continue;
            }
            $action = $direction === InstrumentDirection::Outbound
                ? MatchActionType::OutboundClear
                : MatchActionType::InboundClear;
            $suggestions[] = new StatementSuggestion(
                tier: 3,
                kind: 'action',
                movementIds: [],
                actionType: $action,
                targetType: 'payment_instrument',
                targetId: $instrument->id,
                amount: $amount,
                reason: $instrument->status === InstrumentStatus::Bounced
                    ? 'Bounced outbound instrument is ready for re-presentation.'
                    : 'Pending instrument amount and maturity date match.',
                reasonCode: $instrument->status === InstrumentStatus::Bounced
                    ? 'bounced_instrument'
                    : 'pending_instrument',
                referenceMatched: $this->referenceMatches($this->normalize($line->label), [$instrument->reference]),
                actionParams: ['instrument_id' => $instrument->id],
            );
        }

        return $suggestions;
    }

    /**
     * @param  numeric-string  $amount
     * @return list<StatementSuggestion>
     */
    private function expenseSuggestions(
        BankStatement $statement,
        string $amount,
        string $windowStart,
        string $windowEnd,
        string $lineText,
    ): array {
        $event = new ExpenseStatementSuggestionsRequested(
            tenantId: $statement->tenant_id,
            companyId: $statement->company_id,
            repositoryId: $statement->payment_repository_id,
            currency: $statement->currency,
            amount: $amount,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            lineText: $lineText,
        );
        event($event);

        return array_map(static fn (array $candidate): StatementSuggestion => new StatementSuggestion(
            tier: 3,
            kind: 'action',
            movementIds: [],
            actionType: MatchActionType::ExpenseSettle,
            targetType: 'expense_document',
            targetId: $candidate['expense_id'],
            amount: $candidate['amount'],
            reason: "Unsettled expense {$candidate['label']} dated {$candidate['date']} matches.",
            reasonCode: 'unsettled_expense',
            reasonParams: ['label' => $candidate['label'], 'date' => substr((string) $candidate['date'], 0, 10)],
            referenceMatched: $candidate['reference_matched'],
            actionParams: ['expense_id' => $candidate['expense_id']],
        ), $event->candidates());
    }

    /**
     * @param  numeric-string  $remainingLine
     * @return list<StatementSuggestion>
     */
    private function cardBatchSuggestions(
        BankStatement $statement,
        BankStatementLine $line,
        string $remainingLine,
        int $scale,
    ): array {
        if ($line->direction !== MovementDirection::In) {
            return [];
        }

        $suggestions = [];
        foreach ($this->cardBatches->groups($statement) as $group) {
            $gross = $group['grossAmount'];
            $fee = bcsub($gross, $remainingLine, $scale);
            if (bccomp($fee, '0', $scale) <= 0 || bccomp($fee, $gross, $scale) >= 0) {
                continue;
            }
            $configuredFee = CurrencyScale::bcformatStrict(
                $group['paymentMethod']->calculateFee($gross, $scale),
                $scale,
            );
            if (bccomp($configuredFee, $fee, $scale) !== 0) {
                continue;
            }

            $suggestions[] = new StatementSuggestion(
                tier: 4,
                kind: 'action',
                movementIds: $group['movementIds'],
                actionType: MatchActionType::AcquirerFee,
                targetType: 'payment_method',
                targetId: $group['paymentMethod']->id,
                amount: $remainingLine,
                reason: "Card batch for {$group['paymentMethod']->name} on {$group['businessDate']} nets after configured fee.",
                reasonCode: 'card_batch_fee',
                reasonParams: ['method' => $group['paymentMethod']->code, 'date' => $group['businessDate']],
                actionParams: [
                    'payment_method_id' => $group['paymentMethod']->id,
                    'business_date' => $group['businessDate'],
                    'gross_movement_ids' => $group['movementIds'],
                    'gross_amount' => $gross,
                    'fee_amount' => $fee,
                ],
            );
        }

        return $suggestions;
    }

    /** @param list<string> $references */
    private function referenceMatches(string $lineText, array $references): bool
    {
        foreach ($references as $reference) {
            $normalized = $this->normalize($reference);
            if (mb_strlen($normalized) >= 3 && str_contains($lineText, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
