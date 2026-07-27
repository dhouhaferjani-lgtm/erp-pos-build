<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Contracts;

use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;

interface StatementActionHandlerInterface
{
    public function supports(MatchActionType $action): bool;

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult;
}
