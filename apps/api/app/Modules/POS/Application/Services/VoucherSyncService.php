<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\DTOs\VoucherLedgerSyncRowDto;
use App\Modules\POS\Application\DTOs\VoucherSyncRowDto;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Support\Carbon;

/**
 * Pulls voucher + voucher_ledger updates for an offline POS terminal.
 *
 * Phase 1 single-terminal scope: only vouchers (and their ledger rows)
 * whose `redeemable_at_terminal_id` matches the requesting terminal are
 * returned. The ledger's own `terminal_id` column is NOT used for
 * filtering — back-office ledger events legitimately have null terminal_id.
 *
 * Outbound enums are serialised as PascalCase case-names via the row DTOs.
 * The Tauri client expects exactly that wire-format (apps/pos/src/lib/
 * offline/voucherRepository.ts:57-95). Database backing values
 * (snake_case) MUST NOT leak to the wire.
 */
final class VoucherSyncService
{
    /** Page size used for both vouchers and ledger pulls. */
    public const PAGE_SIZE = 100;

    /**
     * Fetch up to {@see self::PAGE_SIZE} vouchers redeemable at the given terminal,
     * filtered by `updated_at > $updatedSince` when a cursor is provided.
     *
     * @return list<VoucherSyncRowDto>
     */
    public function pullVouchers(string $terminalId, ?Carbon $updatedSince): array
    {
        $query = Voucher::query()
            ->where('redeemable_at_terminal_id', $terminalId);

        if ($updatedSince !== null) {
            $query->where('updated_at', '>', $updatedSince);
        }

        $now = Carbon::now()->toIso8601String();

        /** @var list<VoucherSyncRowDto> $rows */
        $rows = $query
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(static fn (Voucher $v): VoucherSyncRowDto => VoucherSyncRowDto::fromVoucher($v, $now))
            ->all();

        return $rows;
    }

    /**
     * Fetch up to {@see self::PAGE_SIZE} ledger rows belonging to vouchers
     * redeemable at the given terminal. Ledger is append-only (created_at
     * only), so the cursor is `created_at > $updatedSince`.
     *
     * @return list<VoucherLedgerSyncRowDto>
     */
    public function pullLedger(string $terminalId, ?Carbon $updatedSince): array
    {
        $query = VoucherLedger::query()
            ->whereIn(
                'voucher_id',
                Voucher::query()
                    ->where('redeemable_at_terminal_id', $terminalId)
                    ->select('id')
            );

        if ($updatedSince !== null) {
            $query->where('created_at', '>', $updatedSince);
        }

        /** @var list<VoucherLedgerSyncRowDto> $rows */
        $rows = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(static fn (VoucherLedger $l): VoucherLedgerSyncRowDto => VoucherLedgerSyncRowDto::fromLedger($l))
            ->all();

        return $rows;
    }
}
