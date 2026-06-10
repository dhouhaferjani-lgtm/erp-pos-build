<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\DepositReceipt;

/**
 * Read model for the back-office deposit history. Owns all access to the
 * POS-owned `pos_deposit_receipts` table so cross-module callers (the Partner
 * deposit endpoint) never touch the model directly (CLAUDE.md rule 6).
 */
final class DepositReceiptQueryService
{
    /**
     * Paginated, newest-first deposit-receipt history for a partner.
     *
     * @return array{data: array<int, array<string, string>>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public function historyForPartner(string $tenantId, string $companyId, string $partnerId, int $perPage): array
    {
        $paginator = DepositReceipt::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('customer_id', $partnerId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $paginator->getCollection()
            ->map(static function (DepositReceipt $r): array {
                $snapshot = $r->payload_snapshot;
                $payment = is_array($snapshot['payment'] ?? null) ? $snapshot['payment'] : [];

                return [
                    'fiscal_event_id' => $r->fiscal_event_id,
                    'deposit_receipt_uuid' => $r->deposit_receipt_uuid,
                    'customer_id' => $r->customer_id,
                    'customer_name' => $r->customer_name,
                    'amount' => $r->amount,
                    'currency_code' => $r->currency_code,
                    'payment_method_code' => is_string($payment['method_code'] ?? null) ? $payment['method_code'] : '',
                    'actor_name' => is_string($snapshot['actor_name'] ?? null) ? $snapshot['actor_name'] : '',
                    'note' => is_string($snapshot['notes'] ?? null) ? $snapshot['notes'] : '',
                    'recorded_at' => $r->created_at->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
