<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

final readonly class ParseDefect
{
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array{path: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
