<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Document\Presentation\Controllers;

use App\Modules\Document\Domain\Document;

/**
 * Scanner fixture (positive case for stable_key uniqueness). Two
 * Document::findOrFail() calls in DIFFERENT methods of the same class.
 *
 * Before Codex Phase 1 review #1 the scanner fingerprinted these as
 * (Cls::find, Document, "unscoped_eloquent_findOrFail") — which is
 * identical for both → stable_keys collapsed. They must now produce two
 * distinct stable_keys: the symbol carries the enclosing method name
 * (Cls::show vs Cls::edit) and the per-statement fingerprint hashes the
 * AST node's byte offset.
 */
class MultipleFindMethodsController
{
    public function show(int $id): mixed
    {
        return Document::findOrFail($id);
    }

    public function edit(int $id): mixed
    {
        return Document::findOrFail($id);
    }
}
