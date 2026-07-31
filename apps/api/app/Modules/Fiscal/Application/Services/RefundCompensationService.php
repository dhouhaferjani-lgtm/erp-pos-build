<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v3-refund-chain-integration spec §5.2 — server-observable-evidence write-
 * off action.
 *
 * The compensation action NEVER reads device-local `refund_intents.
 * payout_confirmed_at`. Payout evidence is exclusively (a) the signed
 * refund payload's own `shift_id` (already present, chain-immutable) and
 * (b) the operator attestation captured on this request. One idempotent
 * `fiscal_refund_compensations` record per rejected `fiscal_event_id`
 * (universally addressable regardless of whether the rejection came from
 * projection dead-letter or ingress quarantine).
 */
final class RefundCompensationService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly TreasuryMovementServiceInterface $movementService,
    ) {}

    /**
     * @return array{compensation: array<string, mixed>, wasIdempotentHit: bool}
     */
    public function compensate(
        string $fiscalEventId,
        string $compensationClass,
        string $operatorId,
        string $operatorAttestation,
    ): array {
        if (! in_array($compensationClass, ['invalid_refund', 'valid_unbooked'], true)) {
            throw new \InvalidArgumentException(
                'Unknown compensation_class '.$compensationClass.'; expected invalid_refund or valid_unbooked.'
            );
        }

        // Idempotent-hit short-circuit — a repeated POST for the same
        // fiscal_event_id returns the existing record rather than
        // duplicating the GL entry and drawer adjustment.
        $existing = DB::table('fiscal_refund_compensations')
            ->where('fiscal_event_id', $fiscalEventId)
            ->first();
        if ($existing !== null) {
            return [
                'compensation' => (array) $existing,
                'wasIdempotentHit' => true,
            ];
        }

        $event = FiscalEvent::query()->find($fiscalEventId);
        if ($event === null) {
            throw new RuntimeException("RefundCompensationService: fiscal_event {$fiscalEventId} not found.");
        }
        $payload = $event->payload;
        if (! is_array($payload)) {
            throw new RuntimeException("RefundCompensationService: fiscal_event {$fiscalEventId} has no parsed payload to compensate.");
        }

        $scale = (int) ($payload['currency_scale'] ?? 2);
        /** @var numeric-string $amount */
        $amount = (string) ($payload['total'] ?? '0');
        $amount = CurrencyScale::bcformatStrict($amount, $scale);

        $repository = PaymentRepository::query()
            ->forCompany($event->company_id)
            ->ofType(RepositoryType::CashRegister)
            ->first();
        if ($repository === null) {
            throw new RuntimeException(
                "RefundCompensationService: no cash repository found for company {$event->company_id}."
            );
        }

        $compensationId = Str::uuid()->toString();

        $result = DB::transaction(function () use (
            $event,
            $compensationClass,
            $amount,
            $scale,
            $repository,
            $operatorId,
            $operatorAttestation,
            $compensationId,
        ): array {
            $entry = $this->generalLedgerService->createRefundCompensationEntry(
                tenantId: (string) $event->tenant_id,
                companyId: (string) $event->company_id,
                fiscalEventId: (string) $event->id,
                compensationClass: $compensationClass,
                amount: $amount,
                scale: $scale,
                entryDate: $event->event_time_device,
            );
            $this->generalLedgerService->postEntryNow($entry, null, (string) ($event->payload['currency_code'] ?? 'EUR'));

            // §5.2 — the movement port's own derived idempotency key,
            // structurally distinct from TreasuryReceiptBridge's per-
            // payment-leg keys (fiscal_event:{id}:payment:{i}): the
            // 'refund_writeoff' leg discriminator can never equal
            // 'payment:{i}' for any integer i, so this can never collide
            // with that same event's own payment-leg movement.
            $movementResult = $this->movementService->record(new MovementIntent(
                repositoryId: (string) $repository->id,
                tenantId: (string) $event->tenant_id,
                companyId: (string) $event->company_id,
                direction: MovementDirection::Out,
                amount: $amount,
                currency: (string) ($event->payload['currency_code'] ?? 'EUR'),
                sourceType: MovementSourceType::FiscalEvent,
                sourceId: (string) $event->id,
                idempotencyLeg: 'refund_writeoff',
                journalEntryId: (string) $entry->id,
                occurredAt: null,
                reasonCode: MovementReasonCode::Other,
                reversesMovementId: null,
                createdBy: $operatorId,
                notes: "Refund compensation ({$compensationClass}): {$operatorAttestation}",
            ));

            DB::table('fiscal_refund_compensations')->insert([
                'id' => $compensationId,
                'tenant_id' => (string) $event->tenant_id,
                'company_id' => (string) $event->company_id,
                'fiscal_event_id' => (string) $event->id,
                'compensation_class' => $compensationClass,
                'journal_entry_id' => (string) $entry->id,
                'repository_movement_id' => $movementResult->movementId,
                'operator_id' => $operatorId,
                'operator_attestation' => $operatorAttestation,
                'created_at' => now(),
            ]);

            $row = DB::table('fiscal_refund_compensations')->where('id', $compensationId)->first();

            return ['compensation' => (array) $row, 'wasIdempotentHit' => false];
        });

        return $result;
    }
}
