<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use DomainException;

final readonly class StatementActionRegistry
{
    /** @param iterable<StatementActionHandlerInterface> $handlers */
    public function __construct(private iterable $handlers) {}

    public function handler(MatchActionType $action): StatementActionHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($action)) {
                return $handler;
            }
        }

        throw new DomainException("Statement action {$action->value} is not registered.");
    }
}
