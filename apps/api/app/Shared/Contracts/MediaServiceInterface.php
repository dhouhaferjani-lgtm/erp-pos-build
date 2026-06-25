<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Shared\DTOs\Media\MediaAttachmentView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface MediaServiceInterface
{
    /**
     * Upload a file, create the asset, attach it to the owner, return the view row.
     *
     * @param  array<int, string>  $allowedMime
     */
    public function attachUpload(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $tenantId,
        UploadedFile $file,
        ?string $userId,
        MediaRole $role,
        ?string $caption,
        array $allowedMime,
        MediaAssetType $assetType,
    ): MediaAttachmentView;

    /**
     * @return array<int, MediaAttachmentView> newest-first
     */
    public function listForOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): array;

    /**
     * Stream/redirect the attachment's file for download. 404s if missing/foreign.
     */
    public function download(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $attachmentId,
        string $tenantId,
    ): StreamedResponse|RedirectResponse;

    /**
     * Detach one link; deletes the asset when it becomes orphaned.
     */
    public function detach(
        MediaOwnerType $ownerType,
        string $ownerId,
        string $attachmentId,
        string $tenantId,
    ): void;

    /**
     * Remove ALL media for an owner (used by delete-cascade).
     */
    public function purgeOwner(MediaOwnerType $ownerType, string $ownerId, string $tenantId): void;
}
