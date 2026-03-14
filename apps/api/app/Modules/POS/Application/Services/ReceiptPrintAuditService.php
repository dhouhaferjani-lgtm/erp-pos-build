<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReceiptPrintType;
use App\Modules\POS\Domain\Events\ReceiptPrinted;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPrint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Receipt Print Audit Service
 *
 * Records every print/reprint of a receipt for NF525 fiscal compliance.
 * Determines copy number automatically and dispatches domain events.
 */
final class ReceiptPrintAuditService
{
    public function __construct() {}

    /**
     * Record a print event and return the ReceiptPrint record.
     *
     * Determines copy_number by counting existing prints for this receipt.
     * First print = Original (copy_number 1), subsequent = Duplicate (copy_number 2+).
     */
    public function recordPrint(
        string $receiptId,
        string $terminalId,
        string $userId,
        PrintMethod $printMethod,
    ): ReceiptPrint {
        $copyNumber = $this->getCopyCount($receiptId) + 1;

        $printType = $copyNumber === 1
            ? ReceiptPrintType::Original
            : ReceiptPrintType::Duplicate;

        $printedAt = Carbon::now();

        $receiptPrint = ReceiptPrint::create([
            'receipt_id' => $receiptId,
            'terminal_id' => $terminalId,
            'user_id' => $userId,
            'print_type' => $printType,
            'copy_number' => $copyNumber,
            'printed_at' => $printedAt,
            'print_method' => $printMethod,
        ]);

        $receipt = Receipt::findOrFail($receiptId);

        event(new ReceiptPrinted(
            receiptId: $receiptId,
            terminalId: $terminalId,
            companyId: $receipt->company_id,
            userId: $userId,
            printType: $printType->value,
            copyNumber: $copyNumber,
            printMethod: $printMethod->value,
            printedAt: $printedAt->toIso8601String(),
        ));

        return $receiptPrint;
    }

    /**
     * Get the current copy count for a receipt.
     */
    public function getCopyCount(string $receiptId): int
    {
        return ReceiptPrint::where('receipt_id', $receiptId)->count();
    }

    /**
     * Get print history for a receipt, ordered by printed_at.
     *
     * @return Collection<int, ReceiptPrint>
     */
    public function getPrintHistory(string $receiptId): Collection
    {
        return ReceiptPrint::where('receipt_id', $receiptId)
            ->orderBy('printed_at')
            ->get();
    }
}
