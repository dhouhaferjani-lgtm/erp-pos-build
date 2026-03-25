<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductImageControllerTest extends TestCase
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
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy-images',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Test User',
            'email' => 'user@images.test',
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
            'name' => 'Test Product',
            'sku' => 'IMG-TEST-001',
        ]);
    }

    public function test_upload_image_to_product(): void
    {
        $file = UploadedFile::fake()->image('product-photo.jpg', 800, 600)->size(1024);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'product_id',
                    'filename',
                    'original_filename',
                    'mime_type',
                    'file_size',
                    'sort_order',
                    'is_primary',
                ],
            ]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $this->product->id,
            'original_filename' => 'product-photo.jpg',
            'is_primary' => true, // First image becomes primary
        ]);
    }

    public function test_list_product_images(): void
    {
        $image1 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img1.jpg',
            'original_filename' => 'photo1.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img1.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $image2 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img2.jpg',
            'original_filename' => 'photo2.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img2.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$this->product->id}/images");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $image1->id)
            ->assertJsonPath('data.1.id', $image2->id);
    }

    public function test_set_primary_image(): void
    {
        $image1 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img1.jpg',
            'original_filename' => 'photo1.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img1.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $image2 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img2.jpg',
            'original_filename' => 'photo2.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img2.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}/images/{$image2->id}", [
                'is_primary' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_primary', true);

        // Original primary should be cleared
        $this->assertDatabaseHas('product_images', [
            'id' => $image1->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $image2->id,
            'is_primary' => true,
        ]);
    }

    public function test_delete_image(): void
    {
        Storage::fake('url');

        $image = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img1.jpg',
            'original_filename' => 'photo1.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img1.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$this->product->id}/images/{$image->id}");

        $response->assertStatus(204);

        // Soft-deleted
        $this->assertSoftDeleted('product_images', [
            'id' => $image->id,
        ]);
    }

    public function test_reorder_images(): void
    {
        $image1 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img1.jpg',
            'original_filename' => 'photo1.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img1.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $image2 = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'img2.jpg',
            'original_filename' => 'photo2.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/img2.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        // Reverse the order
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images/reorder", [
                'image_ids' => [$image2->id, $image1->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('product_images', [
            'id' => $image2->id,
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $image1->id,
            'sort_order' => 1,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_product_images(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}/images");

        $response->assertStatus(401);
    }

    public function test_user_without_permission_cannot_upload_image(): void
    {
        // Create user with no permissions
        $limitedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limited User',
            'email' => 'limited@images.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        // Assign a role with no product permissions
        $limitedUser->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $limitedUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 100, 100)->size(512);

        $response = $this->actingAs($limitedUser, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertStatus(403);
    }

    public function test_cross_tenant_user_cannot_see_other_tenant_product_images(): void
    {
        // Create images on first tenant's product
        $image = ProductImage::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'filename' => 'secret.jpg',
            'original_filename' => 'secret.jpg',
            'storage_path' => "products/{$this->tenant->id}/{$this->product->id}/secret.jpg",
            'storage_disk' => 'url',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        // Create a second tenant with its own user and product
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-images',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@images.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $otherUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($otherCompany->id);

        $otherProduct = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-001',
        ]);

        // Other tenant user lists images of their own product -- should see no images
        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/products/{$otherProduct->id}/images");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Verify the first tenant's image is not leaked
        $imageIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($image->id, $imageIds);
    }

    public function test_upload_rejects_non_image_file(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/images", [
                'image' => $file,
            ]);

        $response->assertStatus(422);
    }
}
