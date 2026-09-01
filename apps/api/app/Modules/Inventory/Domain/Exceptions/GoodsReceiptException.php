<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\DTOs\GoodsReceiptFailureDetails;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptFailureReason;

final class GoodsReceiptException extends \DomainException
{
    public function __construct(
        public readonly GoodsReceiptFailureReason $reason,
        string $message,
        public readonly GoodsReceiptFailureDetails $details = new GoodsReceiptFailureDetails,
    ) {
        parent::__construct($message);
    }
}
