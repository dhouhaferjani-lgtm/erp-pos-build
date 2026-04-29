<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Thrown when a refund amount exceeds the manager-override threshold and no
 * authorized_by_user_id was supplied in the return request.
 *
 * The frontend (Phase H) catches HTTP 403 with code MANAGER_OVERRIDE_REQUIRED
 * and shows the manager PIN prompt.  On success the frontend re-submits with
 * authorized_by_user_id populated.
 */
final class ManagerOverrideRequiredException extends \DomainException
{
    /**
     * @param  numeric-string  $refundAmount
     * @param  numeric-string  $threshold
     */
    public function __construct(
        public readonly string $refundAmount,
        public readonly string $threshold,
    ) {
        parent::__construct(
            "Refund amount {$refundAmount} exceeds the manager-override threshold {$threshold}. ".
            'An authorized_by_user_id (manager PIN approval) is required.'
        );
    }
}
