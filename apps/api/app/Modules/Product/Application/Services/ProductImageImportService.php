<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Catalog\Application\Services\MediaAttachmentService;
use App\Modules\Catalog\Application\Services\MediaUploadService;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Product\Domain\Product;
use Exception;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ProductImageImportService
{
    public const MAX_ZIP_SIZE = 100 * 1024 * 1024; // 100MB

    public const MAX_FILES_PER_ZIP = 5000;

    public const MAX_COMPRESSION_RATIO = 100; // ZIP bomb detection

    /**
     * Allowed MIME types for imported images.
     * Mirrors MediaUploadService::ALLOWED_MIME (cannot reference private const cross-class).
     *
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Maximum image file size (5 MB).
     */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private readonly MediaUploadService $mediaUploadService,
        private readonly MediaAttachmentService $mediaAttachmentService,
    ) {}

    /**
     * Process a ZIP import for product images.
     *
     * @return list<array<string, mixed>>
     */
    public function processZipImport(ImportJob $job, string $zipPath): array
    {
        $this->validateZipFile($zipPath);

        // Extract ZIP to temporary directory
        $tempDir = $this->extractZipFile($zipPath);

        try {
            // Scan and validate images
            $scannedFiles = $this->scanExtractedFiles($tempDir);

            // Import each image
            $results = [];
            foreach ($scannedFiles as $fileInfo) {
                try {
                    $result = $this->importProductImage($fileInfo, $job->tenant_id);
                    $results[] = $result;
                } catch (Exception $e) {
                    $results[] = [
                        'filename' => $fileInfo['filename'],
                        'sku' => $fileInfo['sku'],
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        } finally {
            // Always cleanup temp directory
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * Validate ZIP file for security and size constraints.
     */
    private function validateZipFile(string $zipPath): void
    {
        // Check file size
        $fileSize = filesize($zipPath);
        if ($fileSize === false || $fileSize > self::MAX_ZIP_SIZE) {
            throw new InvalidArgumentException('ZIP file exceeds maximum size of 100MB');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('Invalid ZIP file');
        }

        // Check file count
        if ($zip->numFiles > self::MAX_FILES_PER_ZIP) {
            $zip->close();
            throw new InvalidArgumentException('ZIP contains too many files (max: '.self::MAX_FILES_PER_ZIP.')');
        }

        // ZIP bomb detection: check compression ratio
        $uncompressedSize = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $uncompressedSize += $stat['size'];
            }
        }

        $compressionRatio = $fileSize > 0 ? $uncompressedSize / $fileSize : 0;
        if ($compressionRatio > self::MAX_COMPRESSION_RATIO) {
            $zip->close();
            throw new InvalidArgumentException('Suspicious compression ratio detected (possible ZIP bomb)');
        }

        $zip->close();
    }

    /**
     * Extract ZIP file with path traversal protection.
     */
    private function extractZipFile(string $zipPath): string
    {
        $tempDir = storage_path('app/temp/product-images-'.uniqid());
        if (! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new InvalidArgumentException('Failed to create temporary directory');
        }

        $zip = new ZipArchive;
        $zip->open($zipPath);

        // Extract with path traversal protection
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($filename === false) {
                continue;
            }

            // Skip directories
            if (str_ends_with($filename, '/')) {
                continue;
            }

            // Path traversal protection
            $safePath = $tempDir.'/'.basename($filename);
            $realTempDir = realpath($tempDir);
            $realDirPath = realpath(dirname($safePath));

            if ($realTempDir === false || $realDirPath === false || ! str_starts_with($realDirPath, $realTempDir)) {
                throw new InvalidArgumentException('Path traversal attempt detected');
            }

            $source = "zip://{$zipPath}#{$filename}";
            if (! copy($source, $safePath)) {
                throw new InvalidArgumentException("Failed to extract file: {$filename}");
            }
        }

        $zip->close();

        return $tempDir;
    }

    /**
     * Scan extracted files for images.
     *
     * @return array<int, array{path: string, filename: string, sku: string}>
     */
    private function scanExtractedFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $this->isImageFile($file->getPathname())) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'filename' => $file->getFilename(),
                    'sku' => $this->parseSkuFromFilename($file->getFilename()),
                ];
            }
        }

        return $files;
    }

    /**
     * Parse product SKU from filename using multiple strategies.
     */
    private function parseSkuFromFilename(string $filename): string
    {
        // Remove extension
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

        // Strategy 1: Exact match (PROD-001.jpg → PROD-001)
        $sku = $nameWithoutExt;

        // Strategy 2: Underscore suffix (PROD-001_front.jpg → PROD-001)
        if (str_contains($nameWithoutExt, '_')) {
            $sku = explode('_', $nameWithoutExt)[0];
        }

        // Strategy 3: Dash suffix (PROD-001-main.jpg → PROD-001)
        // Only if there are multiple dashes (preserve SKUs like PROD-001)
        if (substr_count($nameWithoutExt, '-') > 1) {
            $parts = explode('-', $nameWithoutExt);
            array_pop($parts); // Remove last segment
            $sku = implode('-', $parts);
        }

        return trim($sku);
    }

    /**
     * Import a single product image using the new media model.
     *
     * The returned `image_id` is the MediaAttachment id (façade identity), not the
     * MediaAsset id.  Callers (e.g. the POS sync) cache by this identifier, so it
     * must remain stable even if the underlying asset is replaced.
     *
     * @param  array{path: string, filename: string, sku: string}  $fileInfo
     * @return array{filename: string, sku: string, product_id: string, image_id: string, success: bool}
     */
    private function importProductImage(array $fileInfo, string $tenantId): array
    {
        $sku = $fileInfo['sku'];
        $filePath = $fileInfo['path'];

        // Find product by SKU scoped to the job's tenant (avoids the tenant() helper).
        $product = Product::where('sku', $sku)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $product) {
            throw new Exception("Product not found for SKU: {$sku}");
        }

        // Validate image before touching storage.
        $this->validateImageFile($filePath);

        // Create UploadedFile from extracted temp file.
        $uploadedFile = new UploadedFile(
            $filePath,
            $fileInfo['filename'],
            mime_content_type($filePath) ?: 'application/octet-stream',
            null,
            true // test mode — allows reading from arbitrary temp paths
        );

        // 1. Upload the file and create a MediaAsset row (UPLOADED → PROCESSING → READY via job).
        $asset = $this->mediaUploadService->uploadForProduct(
            $tenantId,
            $product->id,
            $uploadedFile,
            null, // no authenticated user in a batch import context
        );

        // 2. Create the PRIMARY attachment link.
        //    The attachment id is the façade identity used by the POS sync and all callers.
        $attachment = $this->mediaAttachmentService->attach(
            assetId: $asset->id,
            ownerType: MediaOwnerType::Product,
            ownerId: $product->id,
            role: MediaRole::Primary,
            sort: 0,
            tenantId: $tenantId,
        );

        return [
            'filename' => $fileInfo['filename'],
            'sku' => $sku,
            'product_id' => $product->id,
            'image_id' => $attachment->id, // attachment id — NOT the asset id
            'success' => true,
        ];
    }

    /**
     * Validate image file MIME type and size.
     */
    private function validateImageFile(string $filePath): void
    {
        $mimeType = mime_content_type($filePath);
        if (! $mimeType || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new Exception('Invalid image format');
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new Exception('Image exceeds maximum size of 5MB');
        }
    }

    /**
     * Check if file is an image.
     */
    private function isImageFile(string $filePath): bool
    {
        $mimeType = mime_content_type($filePath);

        return $mimeType !== false && str_starts_with($mimeType, 'image/');
    }

    /**
     * Cleanup temporary directory.
     */
    private function cleanupTempDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($directory);
    }
}
