<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\Services;

use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
use App\Shared\DTOs\SupportAccess\ImpersonationContextData;

final class RequestImpersonationContext implements ImpersonationContextProvider
{
    private ?ImpersonationContextData $context = null;

    public function current(): ?ImpersonationContextData
    {
        return $this->context;
    }

    public function set(ImpersonationContextData $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
