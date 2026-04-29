<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\Fiscal\ReceiptQrTokenSigner;
use App\Modules\POS\Domain\Exceptions\InsufficientSearchSpecificityException;
use App\Modules\POS\Domain\Exceptions\InvalidReceiptTokenException;
use App\Modules\POS\Domain\Exceptions\ReceiptNotFoundException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Receipt lookup primitives for the POS refund flow.
 *
 * Supports three lookup entry points:
 *   1. QR token scan — cashier scans the QR code on a printed receipt.
 *   2. Manual receipt number entry — cashier types the receipt number.
 *   3. Customer history — cashier searches by partner (privacy-hardened, Task 25).
 *
 * PHASE 1 SINGLE-TERMINAL SCOPE:
 *   All lookups are bounded to the terminal that issued the receipt
 *   (terminal_id = terminal.id). A receipt printed at terminal A CANNOT be
 *   looked up at terminal B in Phase 1. This prevents cross-terminal refund
 *   abuse without requiring cross-terminal receipt replication.
 *
 * VOIDED RECEIPTS:
 *   Voided receipts are excluded from QR and receipt-number lookups. A voided
 *   receipt cannot be refunded (it was already reversed). If a cashier attempts
 *   to return a voided receipt, the caller should surface an appropriate message.
 */
final class ReceiptLookupService
{
    public function __construct(
        private readonly ReceiptQrTokenSigner $signer,
        private readonly CustomerHistorySearchService $customerHistorySearchService,
    ) {}

    // -------------------------------------------------------------------------
    // QR token lookup
    // -------------------------------------------------------------------------

    /**
     * Verify a QR token and return the corresponding receipt.
     *
     * @throws InvalidReceiptTokenException on bad token
     * @throws ReceiptNotFoundException on miss (generic)
     */
    public function findByQrToken(string $token, Terminal $terminal): Receipt
    {
        $verified = $this->signer->verify($token, $terminal);

        $receipt = Receipt::query()
            ->where('id', $verified->receiptUuid)
            ->where('tenant_id', $terminal->tenant_id)
            ->where('company_id', $terminal->company_id)
            ->where('terminal_id', $terminal->id) // Phase 1: single-terminal scope (SQL level)
            ->where('is_voided', false)
            ->first();

        if ($receipt === null) {
            throw ReceiptNotFoundException::forToken(
                "Receipt {$verified->receiptUuid} not found on terminal {$terminal->id}"
            );
        }

        return $receipt;
    }

    // -------------------------------------------------------------------------
    // Manual receipt number lookup
    // -------------------------------------------------------------------------

    /**
     * Find a receipt by its printed receipt number.
     *
     * The search is bounded to the current terminal (Phase 1 single-terminal scope).
     * Voided receipts are excluded.
     *
     * @throws ReceiptNotFoundException on miss (generic)
     */
    public function findByReceiptNumber(string $number, Terminal $terminal): Receipt
    {
        $receipt = Receipt::query()
            ->where('receipt_number', $number)
            ->where('tenant_id', $terminal->tenant_id)
            ->where('company_id', $terminal->company_id)
            ->where('terminal_id', $terminal->id) // Phase 1: single-terminal scope (SQL level)
            ->where('is_voided', false)
            ->first();

        if ($receipt === null) {
            throw ReceiptNotFoundException::forReceiptNumber($number);
        }

        return $receipt;
    }

    // -------------------------------------------------------------------------
    // Customer history lookup (privacy-hardened — Task 25)
    // -------------------------------------------------------------------------

    /**
     * Return receipts for a partner at this terminal.
     *
     * Privacy rules and rate-limiting are enforced by CustomerHistorySearchService.
     * This method is a thin delegation layer; the controller/resource layer is
     * responsible for masking `total` until the cashier opens an individual row.
     *
     * @return Collection<int, Receipt>
     *
     * @throws InsufficientSearchSpecificityException
     */
    public function findByCustomer(
        Partner $partner,
        User $cashier,
        Terminal $terminal,
    ): Collection {
        return $this->customerHistorySearchService->searchByPartner(
            partner: $partner,
            cashier: $cashier,
            terminal: $terminal,
        );
    }
}
