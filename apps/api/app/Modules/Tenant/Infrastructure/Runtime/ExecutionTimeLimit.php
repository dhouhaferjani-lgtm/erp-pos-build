<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Infrastructure\Runtime;

use Closure;

interface ExecutionTimeLimit
{
    public const PROVISIONING_SECONDS = 240;

    public function setTimeLimit(int $seconds): void;

    public function registerShutdownHandler(Closure $handler): void;

    public function clear(): void;
}
