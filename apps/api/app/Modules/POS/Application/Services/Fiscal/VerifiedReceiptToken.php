<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services\Fiscal;

use Illuminate\Support\Carbon;

/**
 * Value object returned by ReceiptQrTokenSigner::verify().
 *
 * Fields are taken exclusively from the verified terminal context — NOT from
 * the token itself — to prevent cross-tenant injection. The only fields sourced
 * from the token are version, kid (for key lookup), and receipt_uuid (the
 * resource being looked up).
 */
final readonly class VerifiedReceiptToken
{
    public function __construct(
        public string $version,
        public string $kid,
        public string $receiptUuid,
        public string $tenantId,
        public string $companyId,
        public Carbon $issuedAt,
    ) {}
}
