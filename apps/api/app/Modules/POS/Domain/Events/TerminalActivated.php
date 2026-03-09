<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an admin activates a pending terminal.
 * Listeners broadcast the event via WebSocket so the POS app auto-transitions.
 */
final class TerminalActivated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $terminalName,
        public readonly string $tenantId,
        public readonly string $companyId,
    ) {}
}
