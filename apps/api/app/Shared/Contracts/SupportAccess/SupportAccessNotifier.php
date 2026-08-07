<?php

declare(strict_types=1);

namespace App\Shared\Contracts\SupportAccess;

interface SupportAccessNotifier
{
    public function grantRequested(string $grantId, string $tenantId, string $ticketRef): void;
}
