<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for the customer-approval audit bundle captured on the
 * `Quoted → Approved` transition (Spec §7, Task 12 ApprovalFlowTest).
 *
 * `reference` carries free-form evidence: signature URL, phone-call
 * recording id, email thread subject, etc.
 */
#[TypeScript]
final class ApprovalEvidenceData extends Data
{
    public function __construct(
        public ApprovalMethod $method,
        public string $captured_at,
        public ?string $captured_by_user_id,
        public ?string $captured_by_display_name,
        public ?string $reference,
    ) {}
}
