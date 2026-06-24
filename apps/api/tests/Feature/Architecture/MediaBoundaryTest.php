<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Enums\RenditionFormat;
use App\Modules\Media\Domain\Enums\RenditionName;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Architecture boundary test: media/rendition enums must live under
 * App\Modules\Media\Domain\Enums, not App\Modules\Catalog\Domain\Enums.
 *
 * Assertion (a): all 7 enum classes exist under App\Modules\Media\Domain\Enums.
 * Assertion (b): no .php file under app/Modules/Media, app/Modules/Document,
 *               or app/Shared/Contracts imports the old Catalog enum paths.
 */
final class MediaBoundaryTest extends TestCase
{
    /** @return array<int, string> */
    private function enumClasses(): array
    {
        return [
            MediaAssetType::class,
            MediaOwnerType::class,
            MediaRole::class,
            MediaSource::class,
            MediaStatus::class,
            RenditionFormat::class,
            RenditionName::class,
        ];
    }

    public function test_seven_enums_exist_under_media_module(): void
    {
        foreach ($this->enumClasses() as $fqcn) {
            self::assertTrue(
                class_exists($fqcn) || enum_exists($fqcn),
                "Expected enum {$fqcn} to exist under App\\Modules\\Media\\Domain\\Enums",
            );
        }
    }

    public function test_no_catalog_enum_leak_in_media_document_or_shared_contracts(): void
    {
        $appBase = dirname(__DIR__, 4).'/app';

        $scanDirs = [
            $appBase.'/Modules/Media',
            $appBase.'/Modules/Document',
            $appBase.'/Shared/Contracts',
        ];

        $forbiddenPatterns = [
            'App\\Modules\\Catalog\\Domain\\Enums\\Media',
            'App\\Modules\\Catalog\\Domain\\Enums\\Rendition',
        ];

        $violations = [];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                foreach ($forbiddenPatterns as $pattern) {
                    if (str_contains($content, $pattern)) {
                        $violations[] = sprintf(
                            '%s contains forbidden import: %s',
                            str_replace($appBase.'/', '', $file->getPathname()),
                            $pattern,
                        );
                    }
                }
            }
        }

        self::assertEmpty(
            $violations,
            "Catalog enum leak detected:\n".implode("\n", $violations),
        );
    }
}
