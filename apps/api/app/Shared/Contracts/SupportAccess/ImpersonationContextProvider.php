<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

use App\Shared\DTOs\SupportAccess\ImpersonationContextData;

interface ImpersonationContextProvider
{
    public function current(): ?ImpersonationContextData;
}
