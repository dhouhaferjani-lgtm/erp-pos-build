<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a terminal-lifecycle audit event for NF525 export.
 *
 * Source is `audit_events` rows where `aggregate_type = 'Terminal'` and
 * `event_type` is in {`terminal.activated`, `terminal.deactivated`,
 * `terminal.software_updated`}. The XML builder maps each domain event_type
 * to its NF525 code.
 *
 * `$payload` is JSON pass-through (e.g. `terminal_code`, `activated_by`,
 * `previous_version`, `new_version`).
 */
final readonly class Nf525TerminalLifecycleEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $terminalId,
        public string $eventType,
        public string $occurredAtIso8601,
        public array $payload,
    ) {}
}
