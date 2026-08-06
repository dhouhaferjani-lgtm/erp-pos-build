<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\Jobs\GenerateRenditions;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BUG-005 / RCA A2 — a freshly uploaded product image must be viewable
 * IMMEDIATELY, without waiting on the queue.
 *
 * Before this fix, MediaUploadService created Image assets as UPLOADED and only
 * the queued GenerateRenditions job (queue `images`) promoted them to READY,
 * while SignedMediaController 404s any non-READY asset. The 201 response
 * nonetheless handed the client a URL. Net effect: the product form showed a
 * broken image until a worker picked the job up — and *forever* if the `images`
 * supervisor was not consuming, which is exactly the staging symptom.
 *
 * Image assets are now READY on upload: `MediaStorageAdapter::serve()` already
 * falls back to the original bytes when a rendition row is absent, so a READY
 * asset with no renditions serves the original. GenerateRenditions keeps
 * running and only ADDS renditions — the queue is now an optimisation, not a
 * correctness dependency.
 *
 * `Queue::fake()` here is not a convenience: it *is* the staging failure mode
 * (worker never runs). Without it the sync queue driver would silently promote
 * the asset and mask the bug.
 */
final class FreshUploadIsImmediatelyViewableTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->tenant = Tenant::create([
            'name' => 'Fresh Upload Tenant',
            'slug' => 'fresh-upload-tenant-media',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fresh Upload Company',
            'legal_name' => 'Fresh Upload Company LLC',
            'tax_id' => 'TAX-FRESH-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fresh Upload Admin',
            'email' => 'fresh-upload-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Fresh Upload Product',
            'sku' => 'FRESH-UPLOAD-001',
        ]);
    }

    public function test_url_returned_by_upload_serves_bytes_before_the_worker_runs(): void
    {
        Queue::fake();

        $url = $this->uploadImage();

        // The URL handed back by the 201 must resolve to actual bytes even
        // though GenerateRenditions has not (and may never) run.
        $this->get($url)->assertOk();

        // The job is still dispatched — it now only ADDS renditions.
        Queue::assertPushed(GenerateRenditions::class);
    }

    public function test_uploaded_image_asset_is_ready_immediately(): void
    {
        Queue::fake();

        $this->uploadImage();

        $asset = MediaAsset::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

        self::assertSame(
            MediaStatus::Ready,
            $asset->status,
            'Image assets must be READY on upload — a worker outage must never mean "no images, ever"'
        );
    }

    public function test_fresh_upload_appears_in_the_product_image_list(): void
    {
        Queue::fake();

        $this->uploadImage();

        $listed = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/images")
            ->assertOk()
            ->json('data');

        self::assertCount(1, $listed, 'A fresh upload must be visible in the gallery list immediately');
    }

    /**
     * Upload one image and return the signed URL from the 201 response.
     */
    private function uploadImage(): string
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => UploadedFile::fake()->image('hero.jpg', 1200, 900),
            ])
            ->assertCreated();

        $url = $response->json('data.url');

        self::assertIsString($url, 'The 201 response must carry a resolvable URL');

        return $url;
    }
}
