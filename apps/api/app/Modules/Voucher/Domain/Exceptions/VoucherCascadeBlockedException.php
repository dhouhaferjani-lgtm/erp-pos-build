<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by VoucherCascadeService when a credit-note void is blocked because
 * at least one voucher issued from that credit note has been redeemed.
 *
 * Phase 1 hard-block: manual accounting is required.
 * See docs/runbooks/voucher-redeemed-credit-note-correction.md
 *
 * Phase 1.1 will replace this with an in-system corrective debit-note flow.
 */
final class VoucherCascadeBlockedException extends RuntimeException
{
    public const RUNBOOK_PATH = 'docs/runbooks/voucher-redeemed-credit-note-correction.md';

    /**
     * @param  list<string>  $redeemedVoucherIds  IDs of vouchers that have redemptions
     */
    public function __construct(
        public readonly string $creditNoteId,
        public readonly array $redeemedVoucherIds,
    ) {
        $ids = implode(', ', $redeemedVoucherIds);
        parent::__construct(
            sprintf(
                'Cannot void credit note %s: voucher(s) [%s] issued from it have already been redeemed. '
                .'Manual accounting is required. See %s',
                $creditNoteId,
                $ids,
                self::RUNBOOK_PATH,
            )
        );
    }
}
