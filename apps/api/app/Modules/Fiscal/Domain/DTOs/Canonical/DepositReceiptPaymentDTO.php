<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class DepositReceiptPaymentDTO
{
    public function __construct(
        public string $amount,
        public string $methodCode,
        public ?string $repositoryId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: FiscalPayloadArrayGuards::requireString($data, 'amount'),
            methodCode: FiscalPayloadArrayGuards::requireString($data, 'method_code'),
            repositoryId: FiscalPayloadArrayGuards::optionalString($data, 'repository_id'),
        );
    }
}
