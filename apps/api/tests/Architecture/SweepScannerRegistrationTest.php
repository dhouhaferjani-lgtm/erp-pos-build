<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Application\Sweep\Scanners\ManualScanner;
use App\Application\Sweep\Scanners\PhpAstFindScanner;
use App\Application\Sweep\Scanners\PhpPresentationExistsScanner;
use App\Application\Sweep\Scanners\Scanner;
use App\Application\Sweep\Scanners\TanstackKeysScanner;
use App\Console\Commands\SweepInventoryGenerateCommand;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the discovery contract for tenant-isolation sweep scanners (master
 * plan §5). The {@see SweepInventoryGenerateCommand} must register every
 * Scanner-implementing class under app/Application/Sweep/Scanners/ so the
 * inventory's coverage can't silently regress when a new scanner is added
 * but the command isn't updated to instantiate it.
 *
 * Failure modes this test catches:
 *   - New scanner class added but `handle()` doesn't instantiate it →
 *     coverage gap; cluster is `pending` forever with zero callsites.
 *   - Existing scanner removed from `handle()` but file still present →
 *     intent drift; refactor the file or rename it deliberately.
 *
 * The mechanism: filesystem-walk every file under
 * app/Application/Sweep/Scanners/ that's a non-abstract class implementing
 * the Scanner interface; assert the command's source text references each
 * class via a `new ClassName(` token. The check is intentionally
 * source-text based rather than runtime introspection because instantiating
 * scanners has side effects (subprocess spawning, AST parsing) that the
 * architecture test can't safely trigger.
 */
class SweepScannerRegistrationTest extends TestCase
{
    public function test_every_scanner_class_is_registered_in_the_generate_command(): void
    {
        $expected = [
            PhpPresentationExistsScanner::class,
            PhpAstFindScanner::class,
            TanstackKeysScanner::class,
            ManualScanner::class,
        ];

        $this->assertSame(
            $expected,
            $this->discoverScannerClasses(),
            'app/Application/Sweep/Scanners/ contains a class implementing Scanner that is not in the expected list. Either add it to this test (intentional) or to SweepInventoryGenerateCommand::handle() (intent drift).',
        );

        $commandSource = (string) file_get_contents(
            (string) (new ReflectionClass(SweepInventoryGenerateCommand::class))->getFileName(),
        );

        foreach ($expected as $scannerClass) {
            $shortName = (new ReflectionClass($scannerClass))->getShortName();
            $this->assertStringContainsString(
                'new '.$shortName.'(',
                $commandSource,
                sprintf(
                    '%s is not instantiated in SweepInventoryGenerateCommand::handle(). Add `new %s(...)` to the $allScanners array so the inventory generator picks up its callsites; otherwise the cluster stays at zero callsites and per-batch claim/start cycles cannot fire.',
                    $shortName,
                    $shortName,
                ),
            );
        }
    }

    public function test_ts_query_key_scanner_enum_value_is_registered_in_schema(): void
    {
        // Cluster owners write the inventory by hand-editing the manual stub
        // OR by running a scanner. Either path validates against the JSON
        // Schema's `scanner` enum. If the enum drifts away from the four
        // scanner names this command instantiates, generate fails for any
        // row that came from the missing scanner.
        $schemaPath = base_path('app/Application/Sweep/InventoryYamlSchema.json');
        $this->assertFileExists($schemaPath);

        $schema = json_decode((string) file_get_contents($schemaPath), true);
        $this->assertIsArray($schema);

        $callsiteSchema = $schema['$defs']['callsite'] ?? null;
        $this->assertIsArray($callsiteSchema, 'Schema is missing the $defs.callsite definition.');

        $scannerEnum = $callsiteSchema['properties']['scanner']['enum'] ?? null;
        $this->assertIsArray($scannerEnum);

        foreach (['php_presentation_exists', 'php_ast_find', 'ts_query_key', 'manual'] as $expectedScannerName) {
            $this->assertContains(
                $expectedScannerName,
                $scannerEnum,
                sprintf('Schema scanner enum is missing %s — scanner output would be rejected at validation.', $expectedScannerName),
            );
        }
    }

    /**
     * Walks app/Application/Sweep/Scanners/ for non-abstract classes that
     * implement {@see Scanner}. Order is filesystem-sorted then re-grouped
     * by the conventional registration order (PHP scanners → TS scanner →
     * manual) so the assertion against $expected is deterministic.
     *
     * @return list<class-string<Scanner>>
     */
    private function discoverScannerClasses(): array
    {
        $scannerDir = base_path('app/Application/Sweep/Scanners');
        $files = glob($scannerDir.'/*.php');
        $this->assertIsArray($files, 'glob() failed for '.$scannerDir);
        $this->assertNotEmpty($files, 'No scanner files found under '.$scannerDir);

        /** @var list<class-string<Scanner>> $found */
        $found = [];
        foreach ($files as $file) {
            $shortName = basename($file, '.php');
            $fqcn = 'App\\Application\\Sweep\\Scanners\\'.$shortName;
            if (! class_exists($fqcn)) {
                continue;
            }
            $reflection = new ReflectionClass($fqcn);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }
            if (! $reflection->implementsInterface(Scanner::class)) {
                continue;
            }
            /** @var class-string<Scanner> $fqcn */
            $found[] = $fqcn;
        }

        // Stable canonical order matching SweepInventoryGenerateCommand::handle():
        // PHP-presentation, PHP-AST, TS-query-key, manual.
        /** @var list<class-string<Scanner>> $canonicalOrder */
        $canonicalOrder = [
            PhpPresentationExistsScanner::class,
            PhpAstFindScanner::class,
            TanstackKeysScanner::class,
            ManualScanner::class,
        ];

        /** @var list<class-string<Scanner>> $ordered */
        $ordered = [];
        foreach ($canonicalOrder as $cls) {
            if (in_array($cls, $found, true)) {
                $ordered[] = $cls;
            }
        }
        // Append anything filesystem-discovered that's NOT in the canonical
        // order list — surfaces newly-added scanners that haven't been
        // wired into the command yet (the test assertion will fail loudly).
        foreach ($found as $cls) {
            if (! in_array($cls, $ordered, true)) {
                $ordered[] = $cls;
            }
        }

        return $ordered;
    }
}
