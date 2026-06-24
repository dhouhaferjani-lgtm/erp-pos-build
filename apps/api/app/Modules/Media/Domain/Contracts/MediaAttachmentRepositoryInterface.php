<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Media\MediaAttachment;

interface MediaAttachmentRepositoryInterface
{
    /**
     * Return all READY attachments for the given owner type + ids in a single tenant,
     * keyed by owner_id, ordered by sort_order ascending.
     *
     * Every tenant-scoped table in the query is tenant-scoped (defense-in-depth):
     * the attachment row, the asset via whereHas, and both eager-loaded relations.
     *
     * @param  array<string>  $ownerIds
     * @return array<string, array<int, MediaAttachment>> keyed by owner_id
     */
    public function forOwners(MediaOwnerType $type, array $ownerIds, string $tenantId): array;
}
