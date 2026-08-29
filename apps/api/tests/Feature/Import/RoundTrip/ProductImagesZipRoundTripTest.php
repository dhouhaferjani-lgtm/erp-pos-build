<?php

declare(strict_types=1);

namespace Tests\Feature\Import\RoundTrip;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessProductImageImport;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Catalog\Media\ProductImageImportServiceMediaTest;
use Tests\TestCase;
use ZipArchive;

/**
 * HTTP counterpart to the media persistence coverage.
 *
 * @see ProductImageImportServiceMediaTest
 */
final class ProductImagesZipRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Product Images Round Trip Tenant',
            'slug' => 'product-images-round-trip-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product Images Round Trip Company',
            'legal_name' => 'Product Images Round Trip Company LLC',
            'tax_id' => 'TAX-IMAGES-ROUND-TRIP',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product Images Import Admin',
            'email' => 'product-images-round-trip@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Storage::fake('local');
        Queue::fake();
    }

    public function test_product_images_zip_upload_creates_pending_job_and_dispatches_processor(): void
    {
        $zipPath = $this->makeZipWithImage('IMAGE-SKU.jpg');
        $contents = file_get_contents($zipPath);
        $this->assertIsString($contents);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', [
            'file' => UploadedFile::fake()->createWithContent('product-images.zip', $contents),
            'type' => ImportType::ProductImages->value,
        ]);

        $response->assertStatus(202);
        $jobId = $response->json('data.id');
        $this->assertIsString($jobId);

        $job = ImportJob::query()->findOrFail($jobId);
        $this->assertSame(ImportType::ProductImages, $job->type);
        $this->assertSame('product-images.zip', $job->original_filename);
        $this->assertSame(ImportStatus::Pending, $job->status);

        Queue::assertPushed(
            ProcessProductImageImport::class,
            fn (ProcessProductImageImport $queued): bool => $queued->importJobId === $job->id
                && $queued->tenantId === $this->tenant->id
                && $queued->zipPath === Storage::disk('local')->path($job->file_path),
        );
    }

    private function makeZipWithImage(string $imageFilename): string
    {
        $tempDir = sys_get_temp_dir().'/product-images-http-'.Str::random(8);
        mkdir($tempDir, 0755, true);

        $jpegPath = $tempDir.'/'.$imageFilename;
        $image = imagecreatetruecolor(1, 1);
        imagejpeg($image, $jpegPath, 80);

        $zipPath = $tempDir.'/import.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($jpegPath, $imageFilename);
        $zip->close();

        $this->beforeApplicationDestroyed(function () use ($tempDir): void {
            if (! is_dir($tempDir)) {
                return;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($tempDir);
        });

        return $zipPath;
    }
}
