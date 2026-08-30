<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\ImportWarningCode;

final readonly class ImportRowWarningData
{
    public function __construct(
        public ?ImportWarningCode $code,
        public string $detail,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $rawCode = is_string($payload['code'] ?? null) ? $payload['code'] : '';
        $detail = is_string($payload['detail'] ?? null) ? $payload['detail'] : '';
        $code = ImportWarningCode::tryFrom($rawCode);

        return new self(
            $code,
            $code === null && $rawCode !== '' ? $rawCode.': '.$detail : $detail,
        );
    }

    /** @return array{code: string|null, detail: string} */
    public function toStorage(): array
    {
        return ['code' => $this->code?->value, 'detail' => $this->detail];
    }
}
