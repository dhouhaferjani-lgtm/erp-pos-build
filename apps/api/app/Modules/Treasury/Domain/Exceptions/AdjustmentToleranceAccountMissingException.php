<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use DomainException;

/**
 * The company's chart of accounts carries no account for the 658/758 payment
 * tolerance purpose a repository adjustment needs (V3 audit fix 4 / K2).
 *
 * Raised BEFORE the adjustment transaction opens, so nothing is written. The
 * check exists at all because {@see Account::findByPurposeOrFail}
 * would otherwise escape the GL post as a bare RuntimeException → HTTP 500; the
 * HTTP adapter maps this typed exception to the translated 422, and the G3
 * shift-variance listener logs it and blocks nothing.
 */
final class AdjustmentToleranceAccountMissingException extends DomainException
{
    public function __construct(
        public readonly SystemAccountPurpose $purpose,
        public readonly string $companyId,
    ) {
        parent::__construct(
            "Company {$companyId} has no account assigned to the '{$purpose->value}' purpose; ".
            'a repository adjustment cannot be posted.'
        );
    }
}
