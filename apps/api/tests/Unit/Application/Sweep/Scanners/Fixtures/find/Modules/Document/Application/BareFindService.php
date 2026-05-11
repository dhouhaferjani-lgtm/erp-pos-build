<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Document\Application;

use App\Modules\Document\Domain\Document;

/**
 * Scanner fixture (positive case). Two unscoped find() calls on a guarded
 * model — both must surface as scanner output.
 */
class BareFindService
{
    public function fetch(int $id): mixed
    {
        return Document::find($id);
    }

    public function fetchOrFail(int $id): mixed
    {
        return Document::findOrFail($id);
    }
}
