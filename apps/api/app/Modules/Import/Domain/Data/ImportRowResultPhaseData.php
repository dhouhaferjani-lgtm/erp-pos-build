<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportRowResultPhaseData
{
    /** @param array<string, string> $breadcrumbs */
    public function __construct(public array $breadcrumbs) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): self
    {
        $breadcrumbs = [];
        foreach ($payload as $name => $detail) {
            if (is_string($detail)) {
                $breadcrumbs[$name] = $detail;
            }
        }

        return new self($breadcrumbs);
    }

    /** @return array<string, string> */
    public function toStorage(): array
    {
        return $this->breadcrumbs;
    }
}
