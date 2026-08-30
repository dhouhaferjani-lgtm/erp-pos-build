<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Infrastructure\Runtime;

use App\Modules\Tenant\Application\Contracts\ExecutionTimeLimit;
use Closure;

final class PhpExecutionTimeLimit implements ExecutionTimeLimit
{
    private bool $shutdownHandlerRegistered = false;

    private ?Closure $pendingShutdownHandler = null;

    public function setTimeLimit(int $seconds): void
    {
        set_time_limit($seconds);
    }

    public function registerShutdownHandler(Closure $handler): void
    {
        $this->pendingShutdownHandler = $handler;

        if ($this->shutdownHandlerRegistered) {
            return;
        }

        register_shutdown_function($this->dispatchPendingShutdownHandler(...));
        $this->shutdownHandlerRegistered = true;
    }

    public function clear(): void
    {
        $this->pendingShutdownHandler = null;
    }

    private function dispatchPendingShutdownHandler(): void
    {
        $handler = $this->pendingShutdownHandler;
        $this->clear();
        $handler?->__invoke();
    }
}
