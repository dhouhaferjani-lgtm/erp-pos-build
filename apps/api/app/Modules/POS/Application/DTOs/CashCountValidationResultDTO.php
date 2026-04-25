<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Shared\Domain\Enums\VarianceSeverity;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CashCountValidationResultDTO extends Data
{
    public function __construct(
        public readonly VarianceAmount $aggregateVariance,
        public readonly VarianceSeverity $severity,
        public readonly bool $needsReason,
        public readonly bool $needsManagerPin,
        /** @var array<CashCountBreakdownDTO> */
        public readonly array $perTender,
        /** @var array<ValidationError> */
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
