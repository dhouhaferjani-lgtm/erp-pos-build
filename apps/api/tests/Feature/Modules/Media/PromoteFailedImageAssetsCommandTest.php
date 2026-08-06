<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `media:promote-failed-images` — companion to the A2 backfill migration
 * (authz-gate ruling 2026-08-06).
 *
 * The migration excludes FAILED because markFailed() also fired when the
 * ORIGINAL object was missing. This command promotes FAILED assets only after
 * confirming the bytes exist, and it is dry-run by default so a deploy can
 * never move data through it accidentally.
 */
final class PromoteFailedImageAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');

        $this->tenant = Tenant::create([
            'name' => 'Promote Failed Images Tenant',
            'slug' => 'promote-failed-images-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $recoverable = $this->makeFailedAsset(withBytes: true);

        $this->artisan('media:promote-failed-images')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        self::assertSame(
            MediaStatus::Failed,
            $recoverable->refresh()->status,
            'Without --apply the command must not write'
        );
    }

    public function test_apply_promotes_only_assets_whose_original_bytes_exist(): void
    {
        $recoverable = $this->makeFailedAsset(withBytes: true);
        $unrecoverable = $this->makeFailedAsset(withBytes: false);

        $this->artisan('media:promote-failed-images --apply')->assertExitCode(0);

        self::assertSame(
            MediaStatus::Ready,
            $recoverable->refresh()->status,
            'A FAILED asset whose original object is present is safe to promote'
        );
        self::assertSame(
            MediaStatus::Failed,
            $unrecoverable->refresh()->status,
            'An asset with no bytes must stay FAILED — promoting it would replace a clean 404 with a broken response'
        );
    }

    public function test_ready_and_uploaded_assets_are_untouched(): void
    {
        $ready = $this->makeAsset(MediaStatus::Ready, withBytes: true);
        $uploaded = $this->makeAsset(MediaStatus::Uploaded, withBytes: true);

        $this->artisan('media:promote-failed-images --apply')->assertExitCode(0);

        self::assertSame(MediaStatus::Ready, $ready->refresh()->status);
        self::assertSame(
            MediaStatus::Uploaded,
            $uploaded->refresh()->status,
            'UPLOADED is the migration\'s job, not this command\'s'
        );
    }

    private function makeFailedAsset(bool $withBytes): MediaAsset
    {
        return $this->makeAsset(MediaStatus::Failed, $withBytes);
    }

    private function makeAsset(MediaStatus $status, bool $withBytes): MediaAsset
    {
        $path = 'products/'.$this->tenant->id.'/'.Str::uuid().'/original.jpg';

        if ($withBytes) {
            Storage::disk('s3')->put($path, 'FAKE_IMAGE_BYTES');
        }

        return MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => $status,
            'storage_disk' => 's3',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
        ]);
    }
}
