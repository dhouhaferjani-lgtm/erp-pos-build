<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\DTOs\ReceiptQrIndexRowDto;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Support\Carbon;

/**
 * Pulls receipt_qr_index entries for an offline POS terminal.
 *
 * Returns one row per fiscalised receipt at the requesting terminal,
 * carrying a server-signed `qr_token` so the Tauri scan dispatcher
 * (Task 50) can resolve scanned tokens to receipt UUIDs without a
 * network round-trip.
 *
 * Edge case: when no active `receipt_qr` signing key exists for the
 * tenant, {@see ReceiptQrTokenIssuanceService::issueTokenFor()} returns
 * null. We still emit the row with `qr_token: null` — the dispatcher
 * falls through to receipt_number lookup.
 *
 * Pending-seal receipts are excluded — they have not yet earned a stable
 * `fiscal_hash` and shipping them in the index would make scanned tokens
 * resolve to a receipt that may still be rolled back.
 */
final class ReceiptQrIndexSyncService
{
    /** Page size matched to {@see VoucherSyncService::PAGE_SIZE}. */
    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly ReceiptQrTokenIssuanceService $tokenIssuer,
    ) {}

    /**
     * @return list<ReceiptQrIndexRowDto>
     */
    public function pull(string $terminalId, ?Carbon $updatedSince): array
    {
        $query = Receipt::query()
            ->where('terminal_id', $terminalId)
            ->where('fiscal_status', FiscalStatus::Fiscalized);

        if ($updatedSince !== null) {
            $query->where('updated_at', '>', $updatedSince);
        }

        $now = Carbon::now()->toIso8601String();

        /** @var list<ReceiptQrIndexRowDto> $rows */
        $rows = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn (Receipt $r): ReceiptQrIndexRowDto => new ReceiptQrIndexRowDto(
                receiptUuid: $r->id,
                qrToken: $this->tokenIssuer->issueTokenFor($r),
                receiptNumber: $r->receipt_number,
                terminalId: $r->terminal_id,
                postedAt: ($r->posted_at ?? $r->created_at)->toIso8601String(),
                total: $r->total,
                currency: $r->currency,
                syncedAt: $now,
                partnerId: $r->partner_id,
            ))
            ->all();

        return $rows;
    }
}
