<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\TransferReceiptFailureReason;
use RuntimeException;

final class TransferReceiptFailureException extends RuntimeException
{
    /**
     * @param  array{transfer_line_id?: string, batch_id?: int}  $details
     */
    public function __construct(
        public readonly TransferReceiptFailureReason $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason->message());
    }
}
