<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Jobs;

use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Application\Services\ImageVariantService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateImageVariantsTest extends TestCase
{
    public function test_job_generates_sm_and_md_variants_on_s3(): void
    {
        Storage::fake('s3');

        $source = imagecreatetruecolor(800, 600);
        $color = imagecolorallocate($source, 100, 150, 200);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $storagePath = 'products/tid/pid/test-uuid.jpg';
        Storage::disk('s3')->put($storagePath, $jpegData);

        $job = new GenerateImageVariants('img-001', $storagePath, 's3');
        $job->handle(new ImageVariantService);

        Storage::disk('s3')->assertExists('products/tid/pid/test-uuid_sm.webp');
        Storage::disk('s3')->assertExists('products/tid/pid/test-uuid_md.webp');

        $smData = Storage::disk('s3')->get('products/tid/pid/test-uuid_sm.webp');
        $smImg = imagecreatefromstring($smData);
        $this->assertSame(150, imagesx($smImg));
        imagedestroy($smImg);

        $mdData = Storage::disk('s3')->get('products/tid/pid/test-uuid_md.webp');
        $mdImg = imagecreatefromstring($mdData);
        $this->assertSame(400, imagesx($mdImg));
        imagedestroy($mdImg);
    }

    public function test_job_is_idempotent(): void
    {
        Storage::fake('s3');

        $source = imagecreatetruecolor(200, 100);
        ob_start();
        imagejpeg($source, null, 90);
        $jpegData = ob_get_clean();
        imagedestroy($source);

        $storagePath = 'products/tid/pid/existing.jpg';
        Storage::disk('s3')->put($storagePath, $jpegData);
        Storage::disk('s3')->put('products/tid/pid/existing_sm.webp', 'old-data');

        $job = new GenerateImageVariants('img-002', $storagePath, 's3');
        $job->handle(new ImageVariantService);

        Storage::disk('s3')->assertExists('products/tid/pid/existing_sm.webp');
        $newData = Storage::disk('s3')->get('products/tid/pid/existing_sm.webp');
        $this->assertNotSame('old-data', $newData);
    }
}
