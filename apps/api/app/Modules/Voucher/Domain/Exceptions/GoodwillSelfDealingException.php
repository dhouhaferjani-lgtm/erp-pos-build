<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a cashier attempts to issue a goodwill voucher to themselves.
 *
 * Self-dealing on goodwill issuance is a fraud-risk control: the issuing user
 * must not be the same person as the recipient partner's linked user.
 */
final class GoodwillSelfDealingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Self-dealing detected: the issuing user and the recipient partner are linked to the same account. '
            .'Goodwill vouchers must be issued to a different person.'
        );
    }
}
