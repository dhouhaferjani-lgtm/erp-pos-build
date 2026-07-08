<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Media\Domain\Media\MediaRendition;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing the lifecycle of {@see MediaAttachment} links and
 * the safe deletion of {@see MediaAsset} objects.
 *
 * Tenant isolation: every Eloquent query in this service carries an explicit
 * `->where('tenant_id', $tenantId)` clause.  The DB partial unique index
 * `idx_media_attachments_owner_primary` (PG-only) is the database-level backstop
 * for the single-PRIMARY invariant; this service enforces it in application code
 * so SQLite-backed test runs (which lack partial indexes) also uphold the rule.
 */
final class MediaAttachmentService
{
    public function __construct(
        private readonly MediaStorageInterface $storage,
    ) {}

    /**
     * Link a MediaAsset to an owner with the given role.
     *
     * If `$role === PRIMARY`, any existing PRIMARY attachment for the same
     * (owner_type, owner_id, tenant_id, channel=NULL, locale=NULL) slot is
     * first demoted to GALLERY in the same DB transaction.  The DB partial
     * unique index is the database-level backstop on PostgreSQL.
     *
     * @param  string  $assetId  UUID of the MediaAsset to attach.
     * @param  MediaOwnerType  $ownerType  Enum discriminator (PRODUCT, PRODUCT_VARIANT, CATEGORY).
     * @param  string  $ownerId  UUID of the owning entity.
     * @param  MediaRole  $role  Role for this attachment.
     * @param  int  $sort  Display sort order (0-based).
     * @param  string  $tenantId  Tenant scope guard.
     * @param  string|null  $caption  Optional human-readable caption stored on the attachment row.
     */
    public function attach(
        string $assetId,
        MediaOwnerType $ownerType,
        string $ownerId,
        MediaRole $role,
        int $sort,
        string $tenantId,
        ?string $caption = null,
    ): MediaAttachment {
        return DB::transaction(function () use ($assetId, $ownerType, $ownerId, $role, $sort, $tenantId, $caption): MediaAttachment {
            if ($role === MediaRole::Primary) {
                // Demote any existing PRIMARY for this slot before inserting the new one.
                MediaAttachment::where('tenant_id', $tenantId)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)
                    ->where('role', MediaRole::Primary)
                    ->whereNull('channel')
                    ->whereNull('locale')
                    ->update(['role' => MediaRole::Gallery]);
            }

            return MediaAttachment::create([
                'tenant_id' => $tenantId,
                'media_asset_id' => $assetId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'role' => $role,
                'sort_order' => $sort,
                'caption' => $caption,
            ]);
        });
    }

    /**
     * Update the sort_order of a set of attachments for a given owner.
     *
     * The caller passes an ordered array of attachment IDs; the index position
     * within the array becomes the new `sort_order` value (0-based).
     *
     * Only attachments that belong to the given (owner_type, owner_id, tenant_id)
     * triple are updated; IDs from other owners are silently ignored.
     *
     * @param  array<int, string>  $orderedAttachmentIds  Attachment UUIDs in desired display order.
     */
    public function reorder(
        MediaOwnerType $ownerType,
        string $ownerId,
        array $orderedAttachmentIds,
        string $tenantId,
    ): void {
        DB::transaction(function () use ($ownerType, $ownerId, $orderedAttachmentIds, $tenantId): void {
            foreach ($orderedAttachmentIds as $index => $attachmentId) {
                MediaAttachment::where('id', $attachmentId)
                    ->where('tenant_id', $tenantId)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * Hard-delete a single attachment link.
     *
     * If the removed attachment was the PRIMARY for its owner, the remaining
     * attachment with the lowest `sort_order` is promoted to PRIMARY.
     *
     * @param  string  $attachmentId  UUID of the MediaAttachment row to remove.
     * @param  string  $tenantId  Tenant scope guard.
     */
    public function detachLink(string $attachmentId, string $tenantId): void
    {
        DB::transaction(function () use ($attachmentId, $tenantId): void {
            $attachment = MediaAttachment::where('id', $attachmentId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $wasPrimary = $attachment->role === MediaRole::Primary;
            $ownerType = $attachment->owner_type;
            $ownerId = $attachment->owner_id;

            // Hard-delete (MediaAttachment has no SoftDeletes).
            $attachment->delete();

            if ($wasPrimary) {
                // Promote the remaining attachment with the lowest sort_order.
                $next = MediaAttachment::where('tenant_id', $tenantId)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)
                    ->orderBy('sort_order')
                    ->first();

                if ($next !== null) {
                    $next->role = MediaRole::Primary;
                    $next->save();
                }
            }
        });
    }

    /**
     * Soft-delete a MediaAsset and schedule its stored files for deletion.
     *
     * Precondition: the asset must have **no remaining attachment links**.
     * If any links exist, a {@see \RuntimeException} is thrown to prevent
     * orphaning live attachments.
     *
     * Files (original + all renditions) are deleted from storage only **after**
     * the DB commit so the soft-delete row is the source of truth while the
     * deletion is in-flight.
     *
     * @param  string  $assetId  UUID of the MediaAsset to delete.
     * @param  string  $tenantId  Tenant scope guard.
     *
     * @throws \RuntimeException If attachment links still exist for this asset.
     */
    public function deleteAsset(string $assetId, string $tenantId): void
    {
        DB::transaction(function () use ($assetId, $tenantId): void {
            $asset = MediaAsset::where('id', $assetId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $linkCount = MediaAttachment::where('media_asset_id', $assetId)
                ->where('tenant_id', $tenantId)
                ->count();

            if ($linkCount > 0) {
                throw new \RuntimeException(
                    "Cannot delete MediaAsset [{$assetId}]: {$linkCount} attachment link(s) still exist.",
                );
            }

            // Capture file identifiers before the soft-delete.
            $storageDisk = $asset->storage_disk;
            $storagePath = $asset->storage_path;

            /** @var array<int, array{storage_disk: string, storage_path: string}> $renditionPaths */
            $renditionPaths = MediaRendition::where('media_asset_id', $assetId)
                ->where('tenant_id', $tenantId)
                ->get(['storage_disk', 'storage_path'])
                ->map(fn (MediaRendition $r): array => [
                    'storage_disk' => $r->storage_disk,
                    'storage_path' => $r->storage_path,
                ])
                ->all();

            // Soft-delete the asset (cascades to renditions via application code below).
            $asset->delete();

            // Delete stored files only after the commit (so the soft-delete row is durable first).
            if ($storageDisk !== 'url' && $asset->source !== MediaSource::ExternalUrl) {
                DB::afterCommit(function () use ($storageDisk, $storagePath, $renditionPaths): void {
                    if ($storagePath !== null && $storagePath !== '') {
                        $this->storage->delete($storageDisk, $storagePath);
                    }

                    foreach ($renditionPaths as $rendition) {
                        $this->storage->delete($rendition['storage_disk'], $rendition['storage_path']);
                    }
                });
            }
        });
    }

    /**
     * Fully remove an asset that has NO attachment links — orphan cleanup after a
     * failed or duplicate enrichment persist.
     *
     * Unlike {@see deleteAsset()} (which only soft-deletes and leaves rendition
     * rows), this permanently removes the storage object, all rendition files and
     * rows, and hard-deletes the `media_assets` row. It refuses to run if any
     * attachment link still points at the asset, so live links can never be
     * orphaned.
     *
     * Storage deletion runs in {@see DB::afterCommit()} so the durable DB removal
     * is the source of truth while files are torn down; external-URL assets have
     * no stored bytes and are skipped.
     *
     * @param  string  $assetId  UUID of the orphan MediaAsset to purge.
     * @param  string  $tenantId  Tenant scope guard.
     *
     * @throws \RuntimeException If any attachment link still exists for this asset.
     */
    public function hardDeleteOrphan(string $assetId, string $tenantId): void
    {
        DB::transaction(function () use ($assetId, $tenantId): void {
            $asset = MediaAsset::withTrashed()
                ->where('id', $assetId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($asset === null) {
                return;
            }

            $linkCount = MediaAttachment::where('media_asset_id', $assetId)
                ->where('tenant_id', $tenantId)
                ->count();

            if ($linkCount > 0) {
                throw new \RuntimeException(
                    "Refusing to hard-delete MediaAsset [{$assetId}]: {$linkCount} attachment link(s) still exist.",
                );
            }

            $storageDisk = $asset->storage_disk;
            $storagePath = $asset->storage_path;
            $isExternal = $asset->source === MediaSource::ExternalUrl;

            /** @var array<int, array{storage_disk: string, storage_path: string}> $renditionPaths */
            $renditionPaths = MediaRendition::where('media_asset_id', $assetId)
                ->where('tenant_id', $tenantId)
                ->get(['storage_disk', 'storage_path'])
                ->map(fn (MediaRendition $r): array => [
                    'storage_disk' => $r->storage_disk,
                    'storage_path' => $r->storage_path,
                ])
                ->all();

            // Remove rendition rows then hard-delete the asset row (bypasses SoftDeletes).
            MediaRendition::where('media_asset_id', $assetId)
                ->where('tenant_id', $tenantId)
                ->delete();
            $asset->forceDelete();

            if ($storageDisk !== 'url' && ! $isExternal) {
                DB::afterCommit(function () use ($storageDisk, $storagePath, $renditionPaths): void {
                    if ($storagePath !== null && $storagePath !== '') {
                        $this->storage->delete($storageDisk, $storagePath);
                    }

                    foreach ($renditionPaths as $rendition) {
                        $this->storage->delete($rendition['storage_disk'], $rendition['storage_path']);
                    }
                });
            }
        });
    }
}
