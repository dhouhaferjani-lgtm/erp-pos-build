<?php

declare(strict_types=1);

namespace App\Modules\Media\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Promote FAILED image assets to READY — but ONLY where the original object
 * actually exists on disk.
 *
 * Companion to the 2026_08_06_100000_backfill_ready_image_media_assets migration
 * (BUG-005 / RCA A2, authz-gate ruling 2026-08-06). That migration deliberately
 * excludes FAILED, because `markFailed()` fired whenever
 * RenditionService::generate() threw — and one of the reasons it throws is that
 * the original object is missing or unreadable. Blind promotion would convert a
 * clean 404 into a broken response.
 *
 * This command therefore checks `Storage::disk($asset->storage_disk)
 * ->exists($asset->storage_path)` per asset and promotes only the ones whose
 * bytes are present, reporting the rest so an operator can decide.
 *
 * NOT scheduled and NOT run by any deploy — invoke it manually after a deploy
 * if the report shows recoverable rows. Dry-run is the DEFAULT: pass --apply to
 * actually write.
 */
final class PromoteFailedImageAssetsCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'media:promote-failed-images
                            {--apply : Actually promote. Without this flag the command only reports (dry run).}
                            {--tenant= : Restrict the run to a single tenant UUID.}';

    /** @var string */
    protected $description = 'Report (and with --apply, promote) FAILED image assets whose original bytes still exist';

    protected function executeCommand(): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('tenant');
        $onlyTenantId = is_string($only) && $only !== '' ? $only : null;

        if (! $apply) {
            $this->info('DRY RUN — no rows will be written. Re-run with --apply to promote.');
        }

        $totalRecoverable = 0;
        $totalMissingBytes = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $apply,
            $onlyTenantId,
            &$totalRecoverable,
            &$totalMissingBytes,
        ): int {
            if ($onlyTenantId !== null && $tenant->id !== $onlyTenantId) {
                return self::SUCCESS;
            }

            // A tenant behind on its migration lane has no table yet.
            if (! Schema::hasTable('media_assets')) {
                return self::SUCCESS;
            }

            $failed = MediaAsset::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', MediaAssetType::Image)
                ->where('status', MediaStatus::Failed)
                ->get();

            if ($failed->isEmpty()) {
                return self::SUCCESS;
            }

            $recoverable = 0;
            $missingBytes = 0;

            foreach ($failed as $asset) {
                $disk = (string) $asset->storage_disk;
                $path = (string) $asset->storage_path;

                if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                    $missingBytes++;

                    continue;
                }

                $recoverable++;

                if ($apply) {
                    $asset->status = MediaStatus::Ready;
                    $asset->save();
                }
            }

            $totalRecoverable += $recoverable;
            $totalMissingBytes += $missingBytes;

            $this->line(sprintf(
                '  tenant %s (%s): %d FAILED image asset(s) — %d recoverable%s, %d with missing bytes (left FAILED)',
                $tenant->id,
                $tenant->slug ?? '—',
                $failed->count(),
                $recoverable,
                $apply ? ' (PROMOTED)' : '',
                $missingBytes,
            ));

            return self::SUCCESS;
        });

        $this->newLine();
        $this->info(sprintf(
            '%s: %d recoverable, %d left FAILED (original bytes absent).',
            $apply ? 'Promoted' : 'Would promote',
            $totalRecoverable,
            $totalMissingBytes,
        ));

        if ($totalMissingBytes > 0) {
            $this->warn(
                'Assets with missing bytes are NOT promotable — their original object is gone. '
                .'They serve a clean 404 today; re-upload is the only fix.'
            );
        }

        return $exit;
    }
}
