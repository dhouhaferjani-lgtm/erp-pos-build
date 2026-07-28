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
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class StatementSuggestionService
{
    /**
     * Hard ceiling on movement candidates hydrated for a single suggestion pass.
     *
     * The value_date ± matching-window SQL bound already keeps the working set
     * small; this is a sanity cap so a mis-configured window or a pathological
     * movement volume can never hydrate unbounded rows. If the cap is reached
     * the service degrades gracefully — suggestions remain best-effort and
     * Tier-2 uniqueness is judged over the hydrated window slice.
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
        $eligible = $this->eligibleMovements(
            $statement,
            $scale,
            $line->value_date,
            $windowDays,
            $remainingLine,
            $line->direction,
        );
        $references = $this->movementReferences($eligible);
        $lineText = $this->normalize(implode(' ', array_filter([
            $line->label,
            $line->reference,
            $line->bank_transaction_id,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '')));
        $suggestions = [];
        $tierOneMovementIds = [];

        foreach ($eligible as $candidate) {
            $movement = $candidate['movement'];
            if ($movement->direction !== $line->direction
                || bccomp($candidate['remaining'], $remainingLine, $scale) !== 0
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

        $amountDate = array_values(array_filter(
            $eligible,
            static fn (array $candidate): bool => $candidate['movement']->direction === $line->direction
                && bccomp($candidate['remaining'], $remainingLine, $scale) === 0
                && $candidate['movement']->occurred_at->toDateString() >= $windowStart
                && $candidate['movement']->occurred_at->toDateString() <= $windowEnd,
        ));
        if (count($amountDate) === 1) {
            $candidate = $amountDate[0];
            $movement = $candidate['movement'];
            if (! isset($tierOneMovementIds[$movement->id])) {
                $suggestions[] = new StatementSuggestion(
                    tier: 2,
                    kind: 'movement',
                    movementIds: [$movement->id],
                    actionType: null,
                    targetType: $movement->source_type->value,
                    targetId: $movement->source_id,
                    amount: $candidate['remaining'],
                    reason: "Unique remaining amount inside ±{$windowDays} days.",
                    reasonCode: 'unique_amount_window',
                    reasonParams: ['days' => $windowDays],
                );
            }
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
     * Movement candidates for Tier 1/2 matching, bounded in SQL and hard-capped.
     *
     * The candidate load is split into two SQL queries so the perf bound never
     * changes suggestion output (the finding is "perf/robustness bound, not a
     * bug fix"):
     *
     *  - In-window candidates (value_date ± matching window) serve Tier-2
     *    "unique amount in window" AND in-window Tier-1 matching. The window is
     *    applied in the query, not after hydration. The PHP window filter in
     *    suggest() remains the semantic authority for Tier-2 date boundaries, so
     *    uniqueness is still judged strictly within the window.
     *  - Out-of-window candidates are loaded ONLY to preserve Tier-1 reference
     *    matching, which is intentionally window-independent (a payment/instrument
     *    reference can clear the bank days or weeks after its movement date — see
     *    StatementSuggestionServiceTest::test_reference_hit...). They can never
     *    surface as Tier-2 because that path re-checks the window in PHP.
     *
     * Both queries share the necessary conditions for ANY match (same direction,
     * gross amount ≥ the line's remaining) — pure narrowing that cannot drop a
     * real candidate — and each is hard-capped at {@see self::MAX_CANDIDATE_MOVEMENTS}
     * so a mature repository or a mis-configured window can never hydrate
     * unbounded rows. If a cap is hit, suggestions remain best-effort.
     *
     * @param  numeric-string  $remainingLine
     * @return list<array{movement: RepositoryMovement, remaining: numeric-string}>
     */
    private function eligibleMovements(
        BankStatement $statement,
        int $scale,
        CarbonImmutable $valueDate,
        int $windowDays,
        string $remainingLine,
        MovementDirection $direction,
    ): array {
        $windowStartAt = $valueDate->subDays($windowDays)->startOfDay();
        $windowEndAt = $valueDate->addDays($windowDays)->endOfDay();
        $inWindow = $this->candidateBaseQuery($statement, $direction, $remainingLine)
            ->whereBetween('occurred_at', [$windowStartAt, $windowEndAt])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATE_MOVEMENTS)
            ->get();
        $outOfWindow = $this->candidateBaseQuery($statement, $direction, $remainingLine)
            ->where(static function (Builder $query) use ($windowStartAt, $windowEndAt): void {
                $query->where('occurred_at', '<', $windowStartAt)
                    ->orWhere('occurred_at', '>', $windowEndAt);
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATE_MOVEMENTS)
            ->get();
        $movements = $inWindow->concat($outOfWindow)->unique('id')->values();
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
            if (bccomp($remaining, '0', $scale) > 0) {
                $eligible[] = ['movement' => $movement, 'remaining' => $remaining];
            }
        }

        return $eligible;
    }

    /**
     * Base candidate query shared by the in-window and out-of-window loads.
     *
     * The direction and gross-amount predicates are necessary conditions for
     * ANY tier match (both tiers require the movement's remaining to equal the
     * line's remaining, and remaining ≤ gross amount), so they narrow the
     * hydrated set without ever dropping a real candidate.
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
