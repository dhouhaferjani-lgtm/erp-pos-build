<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Enums\ExpiryStatus;
use Carbon\Carbon;

readonly class BatchSuggestionDTO
{
    public function __construct(
        public Batch $batch,
        public float $quantity,
        public Carbon $expiryDate,
        public ExpiryStatus $expiryStatus,
    ) {}

    public function toArray(): array
    {
        return [
            'batch_id' => $this->batch->id,
            'batch_number' => $this->batch->batch_number,
            'quantity' => $this->quantity,
            'expiry_date' => $this->expiryDate->toDateString(),
            'days_until_expiry' => $this->batch->daysUntilExpiry(),
            'expiry_status' => $this->expiryStatus->value,
            'expiry_status_label' => $this->expiryStatus->label(),
            'expiry_status_color' => $this->expiryStatus->color(),
            'can_sell' => $this->expiryStatus->canSell(),
        ];
    }
}
