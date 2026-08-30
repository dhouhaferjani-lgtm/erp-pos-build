<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportRowErrorBagData
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public array $errors) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $errors = [];
        foreach ($payload as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter(
                $messages,
                static fn (mixed $message): bool => is_string($message),
            ));
        }

        return new self($errors);
    }
}
