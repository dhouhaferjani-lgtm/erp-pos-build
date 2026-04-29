<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Modules\Compliance\Presentation\Controllers\Nf525ExportController;
use App\Modules\Compliance\Services\Nf525\Nf525JetExportService;
use App\Modules\Compliance\Services\Nf525\Nf525XmlBuilder;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Architectural unit test — the Compliance NF525 surface must not import
 * POS Domain classes directly. Cross-module Domain imports are forbidden by
 * Rule #6; communication goes via Shared/Contracts/, Events, or a public
 * Application service. This test pins the H3 cleanup so future commits
 * cannot silently re-introduce direct imports.
 *
 * Scope: the three production files refactored in H3 plus a sweep of the
 * `Modules/Compliance/Services/Nf525` and
 * `Modules/Compliance/Presentation/Controllers/Nf525ExportController.php`
 * directories. The DomainEventSubscriber listeners under
 * `Modules/Compliance/Listeners/` are NOT in scope — they import POS Events,
 * which Rule #6 explicitly allows.
 */
class Nf525ContractIsolationTest extends TestCase
{
    public function test_nf525_export_controller_does_not_import_pos_domain(): void
    {
        $this->assertNoPosDomainImportsInClass(Nf525ExportController::class);
    }

    public function test_nf525_jet_export_service_does_not_import_pos_domain(): void
    {
        $this->assertNoPosDomainImportsInClass(Nf525JetExportService::class);
    }

    public function test_nf525_xml_builder_does_not_import_pos_domain(): void
    {
        $this->assertNoPosDomainImportsInClass(Nf525XmlBuilder::class);
    }

    public function test_nf525_export_directory_has_no_pos_domain_imports(): void
    {
        $directories = [
            // Walk relative to this test file: tests/Unit/Compliance/ → app/Modules/...
            dirname(__DIR__, 3).'/app/Modules/Compliance/Services/Nf525',
        ];
        $offenders = [];

        foreach ($directories as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                if (preg_match('/use\s+App\\\\Modules\\\\POS\\\\Domain(?!\\\\Events)/', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These Compliance NF525 files still import POS Domain (non-Event) symbols: '
            .implode(', ', $offenders),
        );
    }

    private function assertNoPosDomainImportsInClass(string $className): void
    {
        $reflection = new ReflectionClass($className);
        $filename = $reflection->getFileName();
        $this->assertNotFalse($filename, 'Could not resolve filename for '.$className);

        $contents = file_get_contents($filename);
        $this->assertNotFalse($contents, 'Could not read file '.$filename);

        // Allow `App\Modules\POS\Domain\Events\*` (Rule #6 carves out events).
        $matched = preg_match('/use\s+App\\\\Modules\\\\POS\\\\Domain(?!\\\\Events)/', $contents);

        $this->assertNotSame(
            1,
            $matched,
            $className.' must not import POS Domain (non-Event) classes after H3.',
        );
    }
}
