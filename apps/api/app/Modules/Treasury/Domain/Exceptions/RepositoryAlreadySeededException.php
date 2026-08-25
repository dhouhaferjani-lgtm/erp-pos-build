<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * An opening float was posted onto a payment repository that Treasury already
 * has a money record for (campaign wave-4 §W4-2).
 *
 * The exact mirror image of RepositoryNotSeededException: that one refuses a
 * count-variance ADJUSTMENT on a till that has never held money (it would book
 * a float as revenue); this one refuses an opening FLOAT on a till that already
 * has. An opening balance is by definition the state before any transaction —
 * once a till has traded, "opening" is a lie and the second float would double
 * the balance sheet's cash without a second physical banknote.
 *
 * The remedy is the operator's legal path in
 * docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md: move real cash into a
 * trading till with a Treasury TRANSFER (which writes a leg on both
 * repositories), and correct a wrong float with an adjustment — which is now
 * permitted, because the till is seeded.
 */
final class RepositoryAlreadySeededException extends DomainException
{
    /**
     * Machine-readable discriminator so the wizard can name the offending row
     * rather than re-render a generic 422.
     */
    public const ERROR_CODE = 'REPOSITORY_ALREADY_SEEDED';

    public function __construct(
        public readonly string $repositoryId,
        public readonly string $repositoryName,
        public readonly string $repositoryCode,
    ) {
        parent::__construct(
            "Cannot post an opening float: payment repository '{$repositoryName}' ({$repositoryCode}) ".
            'already holds money in Treasury. An opening balance is the state before the first '.
            'transaction, so it can only be entered on a repository that has never moved. '.
            'To move cash into a till that has already traded, use a Treasury transfer; '.
            'to correct its balance, use a balance adjustment.'
        );
    }
}
