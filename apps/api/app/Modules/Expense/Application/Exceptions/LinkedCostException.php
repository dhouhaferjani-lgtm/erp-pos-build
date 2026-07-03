<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Exceptions;

final class LinkedCostException extends \RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
