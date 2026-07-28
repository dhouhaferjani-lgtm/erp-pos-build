<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\ReplayPreviewMode;

/** Read-only pre-finalize replay projection for one counting line. */
final readonly class ReplayPreviewDto
{
    /**
     * @param  numeric-string|null  $movementsSinceCount
     * @param  numeric-string  $expectedNow
     * @param  numeric-string  $adjustment
     */
    public function __construct(
        public ReplayPreviewMode $mode,
        public ?string $movementsSinceCount,
        public string $expectedNow,
        public string $adjustment,
        public bool $willAutoPost,
        public ?string $blockedReason,
    ) {}

    /**
     * @return array{
     *   mode: string,
     *   movements_since_count: numeric-string|null,
     *   expected_now: numeric-string,
     *   adjustment: numeric-string,
     *   will_auto_post: bool,
     *   blocked_reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'movements_since_count' => $this->movementsSinceCount,
            'expected_now' => $this->expectedNow,
            'adjustment' => $this->adjustment,
            'will_auto_post' => $this->willAutoPost,
            'blocked_reason' => $this->blockedReason,
        ];
    }
}
