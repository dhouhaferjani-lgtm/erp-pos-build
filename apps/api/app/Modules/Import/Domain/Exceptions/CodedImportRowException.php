<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Exceptions;

use App\Modules\Import\Domain\Enums\ImportErrorCode;
use RuntimeException;

final class CodedImportRowException extends RuntimeException
{
    /**
     * @param array{
     *   supplied?: string,
     *   accepted?: list<string>,
     *   candidates?: list<array{id: string, code: string, name: string, category: string, tier: string}>,
     *   candidate_skus?: list<string>,
     *   sku?: string,
     *   existing_product_id?: string,
     *   barcode?: string,
     *   row_numbers?: list<int>,
     *   differing_fields?: list<string>,
     *   column?: string,
     *   raw?: string,
     *   remedy?: string
     * } $detail
     */
    public function __construct(
        public readonly ImportErrorCode $errorCode,
        string $message,
        public readonly array $detail = [],
    ) {
        parent::__construct($message);
    }
}
