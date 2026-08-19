<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;

/**
 * The NEGATIVE control for top-level code: a bare file-level statement that is
 * properly linked. It lives in its own file because the synthetic `(top-level)`
 * scope spans a whole file body (blind spot B), so a movement here would credit
 * the unlinked route-closure writes in the sibling fixture.
 *
 * PARSED, never executed.
 */
StockMovement::create([
    'product_id' => 'p-2',
    'quantity' => '1.0000',
    'reference_type' => StockMovementReferenceType::Document,
    'reference_id' => 'doc-1',
]);
