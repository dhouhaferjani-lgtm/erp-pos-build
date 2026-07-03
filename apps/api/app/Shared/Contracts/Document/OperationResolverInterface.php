<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Document;

use App\Modules\Document\Domain\Document;

interface OperationResolverInterface
{
    /**
     * @return array{side: 'purchase'|'sales', invoice: array<string, mixed>, operations: list<array<string, mixed>>, auto_selected_id: string|null}
     */
    public function resolve(Document $invoice): array;
}
