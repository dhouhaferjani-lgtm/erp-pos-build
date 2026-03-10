<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;

readonly class CalculatedTax
{
    public function __construct(
        public string $configurationId,
        public string $code,
        public string $name,
        public TaxType $type,
        public ?string $rate,
        public ?string $fixedAmount,
        public string $base,
        public string $amount,
        public int $sequenceOrder,
        public bool $isStampDuty,
        public bool $isRecoverable,
        public TaxApplicationLevel $appliesTo,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'configuration_id' => $this->configurationId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'rate' => $this->rate,
            'fixed_amount' => $this->fixedAmount,
            'base' => $this->base,
            'amount' => $this->amount,
            'sequence_order' => $this->sequenceOrder,
            'is_stamp_duty' => $this->isStampDuty,
            'is_recoverable' => $this->isRecoverable,
            'applies_to' => $this->appliesTo->value,
        ];
    }
}
