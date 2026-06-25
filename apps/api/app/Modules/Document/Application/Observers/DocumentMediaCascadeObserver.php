<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Observers;

use App\Modules\Document\Domain\Document;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Shared\Contracts\MediaServiceInterface;

/**
 * Purges all media attached to a Document when it is hard-deleted.
 *
 * This mirrors the legacy `document_attachments` FK `cascadeOnDelete`, which
 * fired only on a real SQL DELETE row.  Because Document uses SoftDeletes, a
 * soft delete MUST leave media intact; only a hard delete triggers the purge.
 *
 * Hard document deletion MUST go through Eloquent `forceDelete()` (so this
 * observer fires) or explicitly call
 * `MediaServiceInterface::purgeOwner(MediaOwnerType::Document, …)`.
 * Query-builder / mass hard-deletes on the `documents` table bypass this
 * observer and will orphan media.
 */
final class DocumentMediaCascadeObserver
{
    public function __construct(
        private readonly MediaServiceInterface $media,
    ) {}

    /**
     * Handle the Document "forceDeleted" event.
     *
     * Eloquent fires `forceDeleted` (distinct from `deleting`) only when
     * `forceDelete()` is called on a SoftDeletes model, making it the cleanest
     * hook for hard-delete–only cascade logic.
     */
    public function forceDeleted(Document $document): void
    {
        $this->media->purgeOwner(
            MediaOwnerType::Document,
            $document->id,
            $document->tenant_id,
        );
    }
}
