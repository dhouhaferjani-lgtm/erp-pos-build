<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;

/**
 * Request a status transition via WorkOrderTransitionService.
 *
 * `expected_updated_at` implements application-level optimistic concurrency
 * (Strategy A from the plan). When supplied, the transition service compares
 * against the locked row's updated_at and throws StaleWorkOrderException if
 * they differ — mapped to HTTP 409 at the boundary.
 *
 * `context` is a per-transition free-form map (e.g. approval payload,
 * cancellation reason). Typed helpers like CaptureApprovalCommand handle the
 * shaped cases.
 */
final readonly class TransitionStatusCommand
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public string $work_order_id,
        public WorkOrderStatus $to_status,
        public ?string $reason_code,
        public ?string $triggered_by_user_id,
        public \DateTimeImmutable $occurred_at,
        public ?array $context = null,
        public ?\DateTimeImmutable $expected_updated_at = null,
    ) {}
}
