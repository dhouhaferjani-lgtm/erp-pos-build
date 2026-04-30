<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use InvalidArgumentException;

/**
 * Inbound payload for POST /api/v1/pos/voucher-ledger/sync.
 *
 * Represents a single ledger entry pushed by the Tauri client (the client
 * loops one-by-one to avoid partial-batch failure semantics). Validation
 * happens upstream in `VoucherLedgerSyncRequest`; this DTO assumes the
 * incoming array has been schema-validated and just normalises the
 * PascalCase `event` value back to the canonical snake_case enum.
 *
 * The client only writes `Redeemed` / `PartiallyRedeemed` ledger rows
 * locally during a redemption (issuance is server-only via
 * VoucherIssuanceService). The push handler enforces this scope rule and
 * rejects every other event kind with `event_kind_not_supported_in_offline_path`.
 */
final readonly class VoucherLedgerPushPayload
{
    /**
     * @param  numeric-string  $amount  Signed decimal at internal precision
     */
    public function __construct(
        public string $id,
        public string $voucherId,
        public VoucherEvent $event,
        public string $amount,
        public string $currency,
        public ?string $receiptId,
        public ?string $terminalId,
        public string $userId,
        public string $occurredAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated payload entry
     *
     * @throws InvalidArgumentException If `event` is not a recognised PascalCase enum case
     */
    public static function fromArray(array $data): self
    {
        /** @var string $eventName */
        $eventName = $data['event'];

        $event = self::pascalCaseToEvent($eventName);

        if ($event === null) {
            throw new InvalidArgumentException(
                "Unknown voucher event '{$eventName}'."
            );
        }

        /** @var string $id */
        $id = $data['id'];
        /** @var string $voucherId */
        $voucherId = $data['voucher_id'];
        /** @var numeric-string $amount */
        $amount = (string) $data['amount'];
        /** @var string $currency */
        $currency = $data['currency'];
        /** @var string $userId */
        $userId = $data['user_id'];
        /** @var string $occurredAt */
        $occurredAt = $data['occurred_at'];

        return new self(
            id: $id,
            voucherId: $voucherId,
            event: $event,
            amount: $amount,
            currency: $currency,
            receiptId: isset($data['receipt_id']) ? (string) $data['receipt_id'] : null,
            terminalId: isset($data['terminal_id']) ? (string) $data['terminal_id'] : null,
            userId: $userId,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Reverse mapping for the wire-protocol PascalCase event names back
     * to canonical VoucherEvent enum cases. Centralised here so it is the
     * single source of truth for the inbound enum case-mapping.
     */
    public static function pascalCaseToEvent(string $name): ?VoucherEvent
    {
        foreach (VoucherEvent::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
