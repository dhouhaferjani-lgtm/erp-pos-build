<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Shared\Domain\QuantityScale;

/**
 * @phpstan-type ReceiveLot array{batch_id: int, quantity_received: numeric-string, quantity_damaged: numeric-string}
 * @phpstan-type ReceiveLine array{transfer_line_id: string, quantity_received: numeric-string, quantity_damaged: numeric-string, discrepancy_reason?: string|null, discrepancy_note?: string|null, lots?: list<ReceiveLot>|null}
 * @phpstan-type ReceivePayload array{idempotency_key: string, notes?: string|null, lines: list<ReceiveLine>}
 * @phpstan-type ClosePayload array{idempotency_key: string, disposition: string, reason: string, note?: string|null}
 */
final class ReceiptPayloadCanonicalizer
{
    /** @param ReceivePayload|ClosePayload $payload */
    public function hash(array $payload): string
    {
        unset($payload['idempotency_key']);
        if (isset($payload['lines'])) {
            $payload['notes'] ??= null;
            foreach ($payload['lines'] as &$line) {
                $line['discrepancy_note'] ??= null;
                $line['quantity_received'] = bcadd($line['quantity_received'], '0', QuantityScale::SCALE);
                $line['quantity_damaged'] = bcadd($line['quantity_damaged'], '0', QuantityScale::SCALE);
                $line['lots'] ??= [];
                foreach ($line['lots'] as &$lot) {
                    $lot['quantity_received'] = bcadd($lot['quantity_received'], '0', QuantityScale::SCALE);
                    $lot['quantity_damaged'] = bcadd($lot['quantity_damaged'], '0', QuantityScale::SCALE);
                    ksort($lot);
                }
                unset($lot);
                usort($line['lots'], static fn (array $a, array $b): int => $a['batch_id'] <=> $b['batch_id']);
                ksort($line);
            }
            unset($line);
            usort($payload['lines'], static fn (array $a, array $b): int => strcmp($a['transfer_line_id'], $b['transfer_line_id']));
        } else {
            $payload['note'] ??= null;
        }
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
