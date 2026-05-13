<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;

/**
 * Capture customer approval + transition Quoted → Approved atomically.
 *
 * Owns the approval evidence payload (method, captured-by-user, reference).
 * The transition service reads this command's data into the WO header and
 * emits WorkOrderApproved.
 */
final readonly class CaptureApprovalCommand
{
    public function __construct(
        public string $work_order_id,
        public ApprovalMethod $approval_method,
        public string $approval_captured_by_user_id,
        public ?string $approval_reference,
        public \DateTimeImmutable $approval_captured_at,
        public string $tenant_id,
        public string $company_id,
        public ?\DateTimeImmutable $expected_updated_at = null,
    ) {}
}
