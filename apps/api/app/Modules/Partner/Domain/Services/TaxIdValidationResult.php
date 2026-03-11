<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Services;

final readonly class TaxIdValidationResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $isValid,
        public string $format,
        public array $errors = [],
    ) {}

    public static function valid(string $format): self
    {
        return new self(
            isValid: true,
            format: $format,
        );
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalid(string $format, array $errors): self
    {
        return new self(
            isValid: false,
            format: $format,
            errors: $errors,
        );
    }

    /**
     * @return array{is_valid: bool, format: string, errors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'format' => $this->format,
            'errors' => $this->errors,
        ];
    }
}
