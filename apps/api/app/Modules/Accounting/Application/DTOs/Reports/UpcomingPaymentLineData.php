<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpcomingPaymentLineData extends Data
{
    public function __construct(
        public readonly string $partner_name,
        public readonly string $document_number,
        public readonly string $type,
        public readonly string $due_date,
        public readonly string $balance_due,
        public readonly int $days_until_due,
        public readonly bool $overdue,
        public readonly string $source = 'document',
        public readonly ?string $certainty = null,
    ) {}
}
