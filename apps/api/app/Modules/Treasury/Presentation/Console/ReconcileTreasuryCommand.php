<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan treasury:reconcile {--tenant=}`
 *
 * The empirical validation of the Treasury Money-Movement Spine (Task 24,
 * spec §9.2). Per repository, it re-derives truth from the append-only
 * `repository_movements` ledger and asserts three invariants:
 *
 *   1. Cached `balance` == Σ signed movements, AND `balance_after`/`ordinal`
 *      continuity (each movement's balance_after equals the running signed sum
 *      through its ordinal; ordinals are dense 1..N).
 *   2. Every NON-EXEMPT movement carries a `journal_entry_id` that EXISTS
 *      (a Posted `journal_entries` row) with a matching amount. Exemptions:
 *      `opening_balance` legs and same-GL-account transfer legs.
 *   3. Every `transfer_group_id` nets to zero across its legs.
 *
 * On ANY drift the repository is FROZEN (Task 13 `freeze()`) with a reason
 * naming the failed check, and an alert is raised: an `error`-level log line
 * AND an `audit_events` row (`treasury.reconcile.drift`). It NEVER repairs —
 * the operator investigates, then clears the freeze. A clean repository is
 * left untouched and reported OK.
 *
 * Rule 20 / master plan §14: runs in console context with NO CompanyContext.
 * It iterates tenants explicitly ({@see TenantScopedCommand::forEachTenant()})
 * and resolves scale from each repository's own currency — never the no-arg
 * `getScale()`, which would throw here.
 *
 * Scheduled DAILY in routes/console.php.
 */
final class ReconcileTreasuryCommand extends TenantScopedCommand
{
    private const DRIFT_EVENT_TYPE = 'treasury.reconcile.drift';

    /** @var string */
    protected $signature = 'treasury:reconcile
        {--tenant= : restrict reconciliation to one tenant id}';

    /** @var string */
    protected $description = 'Reconcile every payment repository against its append-only movement ledger; freeze + alert on drift, never repair.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly AuditService $auditService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $tenantFilter = $this->stringOption('tenant');

        $checked = 0;
        $frozen = 0;

        $this->forEachTenant(function (Tenant $tenant) use ($tenantFilter, &$checked, &$frozen): int {
            if ($tenantFilter !== null && $tenant->id !== $tenantFilter) {
                return self::SUCCESS;
            }

            $repositories = PaymentRepository::query()
                ->where('tenant_id', $tenant->id)
                ->get();

            foreach ($repositories as $repository) {
                $checked++;

                // Already-frozen repositories stay frozen and are not re-alerted
                // (the operator has an open drift on them already).
                if ($repository->frozen_at !== null) {
                    continue;
                }

                $reason = $this->detectDrift($repository);
                if ($reason !== null) {
                    $this->freezeAndAlert($repository, $reason);
                    $frozen++;
                }
            }

            return self::SUCCESS;
        });

        $this->info(sprintf(
            'treasury:reconcile — checked %d repository(ies); froze %d on drift.',
            $checked,
            $frozen,
        ));

        // A fresh freeze this run surfaces a non-zero exit so ops/CI notice.
        // Subsequent runs skip the now-frozen repo and return SUCCESS.
        return $frozen > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Return a descriptive drift reason (naming the failed check) for the first
     * violated invariant, or null when the repository reconciles cleanly.
     */
    private function detectDrift(PaymentRepository $repository): ?string
    {
        // Rule 20: explicit currency — never the no-arg getScale() in console.
        $scale = $this->scaleResolver->getScaleSafe($repository->currency, 3);

        /** @var Collection<int, RepositoryMovement> $movements */
        $movements = RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->orderBy('ordinal')
            ->get();

        // ── Check 1: balance == Σ signed movements + continuity + gapless ──────
        $running = '0';
        $expectedOrdinal = 1;
        foreach ($movements as $movement) {
            if ($movement->ordinal !== $expectedOrdinal) {
                return sprintf(
                    'reconcile drift [check 1: ordinal gap] repository %s: expected ordinal %d, found %d.',
                    $repository->id,
                    $expectedOrdinal,
                    $movement->ordinal,
                );
            }

            $running = $movement->direction === MovementDirection::In
                ? bcadd($running, $movement->amount, $scale)
                : bcsub($running, $movement->amount, $scale);

            if (bccomp($running, $movement->balance_after, $scale) !== 0) {
                return sprintf(
                    'reconcile drift [check 1: balance_after continuity] repository %s ordinal %d: running signed sum %s != stored balance_after %s.',
                    $repository->id,
                    $movement->ordinal,
                    $running,
                    $movement->balance_after,
                );
            }

            $expectedOrdinal++;
        }

        $cachedBalance = $repository->balance ?? '0';
        if (bccomp($running, $cachedBalance, $scale) !== 0) {
            return sprintf(
                'reconcile drift [check 1: balance mismatch] repository %s: cached balance %s != Σ signed movements %s.',
                $repository->id,
                $cachedBalance,
                $running,
            );
        }

        // ── Check 2: JE existence + Posted + matching amount (non-exempt) ──────
        foreach ($movements as $movement) {
            if ($movement->journal_entry_id === null) {
                if ($this->isJournalEntryExempt($movement)) {
                    continue;
                }

                return sprintf(
                    'reconcile drift [check 2: missing journal entry] repository %s movement %s (source %s): non-exempt movement has no journal_entry_id.',
                    $repository->id,
                    $movement->id,
                    $movement->source_type->value,
                );
            }

            $entry = JournalEntry::query()->find($movement->journal_entry_id);
            if (! $entry instanceof JournalEntry) {
                return sprintf(
                    'reconcile drift [check 2: dangling journal entry] repository %s movement %s: references missing journal_entry %s.',
                    $repository->id,
                    $movement->id,
                    $movement->journal_entry_id,
                );
            }

            if ($entry->status !== JournalEntryStatus::Posted) {
                return sprintf(
                    'reconcile drift [check 2: unposted journal entry] repository %s movement %s: journal_entry %s status is %s (expected posted).',
                    $repository->id,
                    $movement->id,
                    $entry->id,
                    $entry->status->value,
                );
            }

            if (! $this->journalEntryAmountMatches($entry->id, $repository, $movement, $scale)) {
                return sprintf(
                    'reconcile drift [check 2: journal entry amount mismatch] repository %s movement %s: amount %s not reconciled by journal_entry %s.',
                    $repository->id,
                    $movement->id,
                    $movement->amount,
                    $entry->id,
                );
            }
        }

        // ── Check 3: every transfer_group_id nets to zero across its legs ──────
        $groupIds = $movements
            ->pluck('transfer_group_id')
            ->filter(static fn (?string $id): bool => $id !== null)
            ->unique()
            ->values();

        foreach ($groupIds as $groupId) {
            /** @var string $groupId */
            $net = $this->transferGroupNet($repository->tenant_id, $groupId, $scale);
            if (bccomp($net, '0', $scale) !== 0) {
                return sprintf(
                    'reconcile drift [check 3: transfer imbalance] repository %s transfer_group %s: legs net to %s, expected 0.',
                    $repository->id,
                    $groupId,
                    $net,
                );
            }
        }

        return null;
    }

    /**
     * A movement may legitimately carry a null journal_entry_id only when it is
     * an opening_balance leg or a same-GL-account transfer leg (spec §9.2).
     */
    private function isJournalEntryExempt(RepositoryMovement $movement): bool
    {
        if ($movement->source_type === MovementSourceType::OpeningBalance) {
            return true;
        }

        if ($movement->source_type === MovementSourceType::Transfer && $movement->transfer_group_id !== null) {
            return $this->isSameGlAccountTransfer($movement->tenant_id, $movement->transfer_group_id);
        }

        return false;
    }

    /**
     * True when every repository touched by a transfer group maps to the same
     * gl_account_id (a same-GL-account transfer, whose legs carry a null JE by
     * design). A cross-GL-account transfer group would resolve to >1 distinct
     * account and is therefore NOT exempt — its legs must carry a JE.
     */
    private function isSameGlAccountTransfer(string $tenantId, string $transferGroupId): bool
    {
        $repositoryIds = RepositoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('transfer_group_id', $transferGroupId)
            ->pluck('payment_repository_id')
            ->unique();

        $distinctGlAccounts = PaymentRepository::query()
            ->whereIn('id', $repositoryIds)
            ->pluck('gl_account_id')
            ->unique();

        return $distinctGlAccounts->count() === 1;
    }

    /**
     * The movement amount reconciles against its journal entry when a line on
     * the repository's own cash/bank GL account carries that amount on the
     * matching side (debit for In, credit for Out), or — tolerant of bundled or
     * reversal-convention entries — when any line in the entry carries the
     * amount on either side.
     */
    private function journalEntryAmountMatches(
        string $journalEntryId,
        PaymentRepository $repository,
        RepositoryMovement $movement,
        int $scale,
    ): bool {
        /** @var Collection<int, JournalLine> $lines */
        $lines = JournalLine::query()
            ->where('journal_entry_id', $journalEntryId)
            ->get();

        $amount = $movement->amount;

        if ($repository->gl_account_id !== null) {
            $candidates = $lines->where('account_id', $repository->gl_account_id);
            if ($candidates->isNotEmpty()) {
                $side = $movement->direction === MovementDirection::In ? 'debit' : 'credit';

                $sum = '0';
                foreach ($candidates as $line) {
                    /** @var numeric-string $sideAmount */
                    $sideAmount = $line->{$side};
                    if (bccomp($sideAmount, $amount, $scale) === 0) {
                        return true;
                    }
                    $sum = bcadd($sum, $sideAmount, $scale);
                }

                if (bccomp($sum, $amount, $scale) === 0) {
                    return true;
                }
            }
        }

        // Fallback: the amount appears on either side of any line in the entry.
        foreach ($lines as $line) {
            if (bccomp($line->debit, $amount, $scale) === 0 || bccomp($line->credit, $amount, $scale) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Signed net (In = +, Out = −) of every leg sharing a transfer group across
     * all repositories in the tenant, at the given scale.
     *
     * @return numeric-string
     */
    private function transferGroupNet(string $tenantId, string $transferGroupId, int $scale): string
    {
        /** @var Collection<int, RepositoryMovement> $legs */
        $legs = RepositoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('transfer_group_id', $transferGroupId)
            ->get();

        $net = '0';
        foreach ($legs as $leg) {
            $net = $leg->direction === MovementDirection::In
                ? bcadd($net, $leg->amount, $scale)
                : bcsub($net, $leg->amount, $scale);
        }

        return $net;
    }

    /**
     * Freeze the repository (never repair) and raise the operator alert:
     * error-level log line + an audit_events row. Order: freeze FIRST, so the
     * repository is locked down even if the alert path throws.
     */
    private function freezeAndAlert(PaymentRepository $repository, string $reason): void
    {
        $this->movementService->freeze($repository->id, $reason);

        Log::error(self::DRIFT_EVENT_TYPE, [
            'tenant_id' => $repository->tenant_id,
            'company_id' => $repository->company_id,
            'repository_id' => $repository->id,
            'currency' => $repository->currency,
            'balance' => $repository->balance,
            'reason' => $reason,
        ]);

        $this->auditService->record(
            companyId: $repository->company_id,
            userId: null,
            eventType: self::DRIFT_EVENT_TYPE,
            aggregateType: 'PaymentRepository',
            aggregateId: $repository->id,
            payload: [
                'reason' => $reason,
                'currency' => $repository->currency,
                'cached_balance' => $repository->balance,
            ],
            metadata: [
                'source' => 'treasury:reconcile',
            ],
        );

        $this->error(sprintf('FROZEN repository %s — %s', $repository->id, $reason));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
