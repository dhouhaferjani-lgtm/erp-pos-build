<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use App\Modules\Document\Domain\Enums\DeliveryComplianceCode;

/**
 * The single verdict of the unified delivery-compliance gate (Wave 3 T25f).
 *
 * Carries everything BOTH former call sites needed: the posting chokepoint reads
 * `code` and `message` to raise its refusal, the guided modal reads
 * `draftDeliveryNotes` / `canAutoConfirm` to render the "confirm & post"
 * affordance. One evaluation, one shape — before T25f the two call sites derived
 * these independently, from a traversal that read only one of the two linkage
 * shapes.
 */
final class DeliveryComplianceStatus
{
    /**
     * @param  list<array{id: string, number: string, total: string, line_count: int}>  $draftDeliveryNotes
     */
    public function __construct(
        public readonly DeliveryComplianceCode $code,
        public readonly string $message,
        public readonly array $draftDeliveryNotes = [],
        public readonly bool $canAutoConfirm = false,
    ) {}

    public static function compliant(): self
    {
        return new self(DeliveryComplianceCode::Compliant, '');
    }

    public function isCompliant(): bool
    {
        return $this->code->isCompliant();
    }
}
