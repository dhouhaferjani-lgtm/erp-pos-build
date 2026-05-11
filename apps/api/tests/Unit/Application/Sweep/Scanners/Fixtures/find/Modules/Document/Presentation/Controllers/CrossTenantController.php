<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Document\Presentation\Controllers;

use App\Modules\Document\Domain\Document;
use App\Shared\Architecture\CrossTenantRoute;

/**
 * Scanner fixture (skip case). #[CrossTenantRoute] annotation must cause
 * the visitor to skip the unscoped find() inside.
 */
class CrossTenantController
{
    #[CrossTenantRoute(reason: 'super-admin lookup, no tenant filter applies')]
    public function show(int $id): mixed
    {
        return Document::findOrFail($id);
    }
}
