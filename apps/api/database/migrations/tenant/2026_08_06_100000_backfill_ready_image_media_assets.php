<?php

declare(strict_types=1);

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-005 / RCA A2 backfill — authz-gate ruling 2026-08-06.
 *
 * MediaUploadService used to create Image assets as UPLOADED and only the
 * queued GenerateRenditions job promoted them to READY, while every read path
 * filters on READY. Assets uploaded before the fix (and any uploaded while the
 * Horizon `images` supervisor was not consuming) are stranded — the client's
 * already-broken product photo is one of them. Promote them so the fix reaches
 * existing data, not just new uploads.
 *
 * Predicate — UPLOADED and PROCESSING only:
 *   - PROCESSING rows are mid-encode leftovers from a worker that died; the
 *     original object is present, so they are serveable.
 *   - FAILED is deliberately EXCLUDED. markFailed() fired when
 *     RenditionService::generate() threw, which INCLUDES "the original object is
 *     missing or unreadable". Promoting those blind would turn a clean 404 into
 *     a broken response. They are handled by `media:promote-failed-images`,
 *     which checks Storage::exists() per asset before promoting and is run
 *     manually — never as part of a deploy.
 *
 * Self-guarding: `Schema::hasTable()` so a tenant whose migration lane is behind
 * no-ops instead of failing the deploy (push to origin/dev auto-runs
 * tenants:migrate). Idempotent — a second run matches zero rows.
 *
 * `down()` is a deliberate no-op: never demote an asset that is now serving.
 *
 * Precedent: 2026_07_16_100000_backfill_user_company_memberships.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_assets')) {
            return;
        }

        // Enum-backed literals, NOT hand-written strings: the column stores the
        // enum's backing value, which is UPPERCASE ('IMAGE', 'UPLOADED', …).
        // A lowercase predicate would match zero rows and silently no-op.
        $promoted = DB::table('media_assets')
            ->where('type', MediaAssetType::Image->value)
            ->whereIn('status', [
                MediaStatus::Uploaded->value,
                MediaStatus::Processing->value,
            ])
            ->update([
                'status' => MediaStatus::Ready->value,
                'updated_at' => now(),
            ]);

        if ($promoted > 0) {
            Log::info('media.backfill.promoted_image_assets_to_ready', [
                'rows' => $promoted,
                'from' => [MediaStatus::Uploaded->value, MediaStatus::Processing->value],
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty. Demoting a READY asset would re-break every image
        // this migration fixed, and the pre-migration status is not recoverable.
    }
};
