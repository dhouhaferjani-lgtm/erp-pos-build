<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a receipt has been fully paid.
 * Listeners include loyalty point earning.
 */
final class ReceiptCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $receiptId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $customerId,
        public readonly string $totalAmount,
        public readonly string $currency,
    ) {}
}
