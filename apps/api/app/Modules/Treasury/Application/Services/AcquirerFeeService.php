<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AcquirerFeeService
{
    private const VAT_RATE_SCALE = 3;

    public function __construct(
        private GeneralLedgerService $generalLedger,
        private TreasuryMovementServiceInterface $movementService,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function record(
        BankStatementLine $line,
        string $paymentMethodId,
        string $grossAmount,
        string $feeAmount,
        string $userId,
    ): RepositoryMovement {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('AcquirerFeeService::record() requires the statement matcher transaction.');
        }

        $idempotencyKey = MovementSourceType::Adjustment->value.":{$line->id}:acquirer_fee";
        $existing = RepositoryMovement::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof RepositoryMovement) {
            $replayScale = $this->scaleResolver->getScale($existing->currency);
            $replayedFee = CurrencyScale::bcformatStrict($feeAmount, $replayScale);
            if ($existing->payment_repository_id !== $line->payment_repository_id
                || $existing->direction !== MovementDirection::Out
                || $existing->source_type !== MovementSourceType::Adjustment
                || $existing->source_id !== $line->id
                || bccomp($existing->amount, $replayedFee, $replayScale) !== 0) {
                throw new DomainException('Acquirer fee idempotency replay does not match the recorded movement.');
            }

            return $existing;
        }

        $statement = $line->statement()->firstOrFail();
        $scale = $this->scaleResolver->getScale($statement->currency);
        $gross = CurrencyScale::bcformatStrict($grossAmount, $scale);
        $fee = CurrencyScale::bcformatStrict($feeAmount, $scale);
        if ($line->direction !== MovementDirection::In
            || bccomp($gross, '0', $scale) <= 0
            || bccomp($fee, '0', $scale) <= 0
            || bccomp($fee, $gross, $scale) >= 0
            || bccomp(bcsub($gross, $fee, $scale), $line->amount, $scale) !== 0) {
            throw new DomainException('Acquirer fee must be positive, below gross, and close the gross minus fee to the statement net.');
        }

        $repository = PaymentRepository::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereKey($line->payment_repository_id)
            ->first();
        $method = PaymentMethod::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereKey($paymentMethodId)
            ->first();
        $user = User::query()->where('tenant_id', $statement->tenant_id)->whereKey($userId)->first();
        if (! $repository instanceof PaymentRepository
            || ! $method instanceof PaymentMethod
            || ! $user instanceof User
            || ! $repository->is_active
            || $repository->gl_account_id === null
            || ! $method->is_active
            || ! $method->has_deducted_fees
            || $method->fee_account_id === null
            || $method->default_repository_id !== $repository->id) {
            throw new DomainException('Acquirer fee routing is not configured for this statement repository.');
        }
        $feeAccountExists = Account::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('type', AccountType::Expense)
            ->where('is_active', true)
            ->whereKey($method->fee_account_id)
            ->exists();
        if (! $feeAccountExists) {
            throw new DomainException('Acquirer fee account must be an active expense account in the statement company.');
        }

        $configuredVatRate = config('treasury.acquirer_fee_vat_rate');
        if (! is_string($configuredVatRate)
            || bccomp(
                CurrencyScale::bcformatStrict($configuredVatRate, self::VAT_RATE_SCALE),
                '0',
                self::VAT_RATE_SCALE,
            ) !== 0) {
            throw new DomainException('Acquirer fee posting supports only the configured VAT-exempt launch treatment.');
        }

        $configuredFee = CurrencyScale::bcformatStrict($method->calculateFee($gross, $scale), $scale);
        if (bccomp($configuredFee, $fee, $scale) !== 0) {
            throw new DomainException('Acquirer fee is not plausible under the payment method fee configuration.');
        }

        $entry = $this->generalLedger->createAcquirerFeeJournalEntry(
            companyId: $statement->company_id,
            tenantId: $statement->tenant_id,
            statementLineId: $line->id,
            feeAccountId: $method->fee_account_id,
            repositoryGlAccountId: $repository->gl_account_id,
            amount: $fee,
            date: CarbonImmutable::parse($line->value_date),
            user: $user,
            currencyCode: $statement->currency,
        );
        $result = $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $statement->tenant_id,
            companyId: $statement->company_id,
            direction: MovementDirection::Out,
            amount: $fee,
            currency: $statement->currency,
            sourceType: MovementSourceType::Adjustment,
            sourceId: $line->id,
            idempotencyLeg: 'acquirer_fee',
            journalEntryId: $entry->id,
            occurredAt: CarbonImmutable::parse($line->value_date),
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $user->id,
            notes: "Acquirer fee for payment method {$method->code}",
            allowWhileFrozen: false,
        ));

        return RepositoryMovement::query()->findOrFail($result->movementId);
    }
}
