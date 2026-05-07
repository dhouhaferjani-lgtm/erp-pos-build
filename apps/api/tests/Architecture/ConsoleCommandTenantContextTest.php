<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Console\TenantScopedCommand;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Step 7 of api.console-commands cluster (master plan §14).
 *
 * Asserts every concrete Artisan command class is tenant-classified:
 *
 *   (a) Extends {@see TenantScopedCommand} — the singleshot subclass binds
 *       --tenant + --company on entry; the per-tenant-iter subclass uses
 *       forEachTenant() to bound each pass to one tenant.
 *
 *   (b) Carries a class-level PHPDoc tag `@cross-tenant-by-design <text>`
 *       with non-empty justification (Section 9 grammar, simplified
 *       single-line form). Bare `@cross-tenant-by-design` with no text
 *       fails this test — every annotation must name the genuine reason.
 *
 * Abstract classes are skipped via {@see ReflectionClass::isAbstract()}.
 *
 * The deferrals fixture (`tests/Architecture/fixtures/console-command-deferrals.json`)
 * lists classes that live in another cluster's surface and have been
 * deferred for that cluster to handle. Listed classes are skipped here.
 * The fixture is read on every test invocation (NOT cached at class-load)
 * so a parallel cluster mutating the file mid-run is picked up after the
 * next `git pull --ff-only`.
 */
final class ConsoleCommandTenantContextTest extends TestCase
{
    public function test_every_concrete_artisan_command_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverArtisanCommandClasses();

        $unclassified = [];
        $bareAnnotations = [];

        foreach ($classes as $class) {
            if (in_array($class, $deferrals, true)) {
                continue;
            }

            // (a) Extends TenantScopedCommand
            if (is_subclass_of($class, TenantScopedCommand::class)) {
                continue;
            }

            // (b) Class-level @cross-tenant-by-design with non-empty justification
            $reflection = new ReflectionClass($class);
            $docBlock = $reflection->getDocComment();

            if ($docBlock === false) {
                $unclassified[] = $class;

                continue;
            }

            if (! preg_match('/@cross-tenant-by-design\b[ \t]*([^\r\n]*)/m', $docBlock, $matches)) {
                $unclassified[] = $class;

                continue;
            }

            $justification = trim($matches[1]);
            if ($justification === '') {
                $bareAnnotations[] = $class;

                continue;
            }
        }

        $this->assertEmpty(
            $unclassified,
            "Found concrete Artisan command class(es) with no tenant-context classification:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEach concrete Artisan command MUST EITHER extend App\\Console\\TenantScopedCommand"
            .' OR carry a class-level PHPDoc tag `@cross-tenant-by-design <non-empty justification>`.'
            ."\nSee docs/superpowers/audits/2026-05-06-api-console-commands-triage.md for the cluster's classification rules.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found Artisan command class(es) with bare `@cross-tenant-by-design` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line."
            ."\nExample: `@cross-tenant-by-design Iterates Company::all() for fiscal chain integrity verification.`",
        );
    }

    /**
     * Discover every concrete (non-abstract) class that extends
     * Illuminate\Console\Command and lives under a Commands/ or Console/
     * directory in the application source tree.
     *
     * @return list<class-string<Command>>
     */
    private function discoverArtisanCommandClasses(): array
    {
        $basePath = base_path();
        $roots = [
            $basePath.'/app/Console/Commands',
            $basePath.'/app/Modules',
        ];

        /** @var array<class-string<Command>, true> $seen */
        $seen = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                if (preg_match('@/(Commands|Console)/@', $path) !== 1) {
                    continue;
                }

                $class = $this->resolveClassFromFile($path);
                if ($class === null) {
                    continue;
                }

                if (! class_exists($class)) {
                    continue;
                }

                if (! is_subclass_of($class, Command::class)) {
                    continue;
                }

                /** @var class-string<Command> $class */
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $seen[$class] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Parse the namespace + class name from a PHP source file. Returns the
     * fully-qualified class name or null if the file doesn't declare a
     * namespaced class.
     */
    private function resolveClassFromFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatches) !== 1) {
            return null;
        }
        if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $classMatches) !== 1) {
            return null;
        }

        return trim($nsMatches[1]).'\\'.$classMatches[1];
    }

    /**
     * Read the deferrals fixture FRESH on every test invocation so that a
     * parallel cluster mutating the file mid-run is observable after a
     * `git pull --ff-only`.
     *
     * @return list<string>
     */
    private function loadDeferralsFresh(): array
    {
        $path = base_path('tests/Architecture/fixtures/console-command-deferrals.json');
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        /** @var mixed $data */
        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        $classes = [];
        foreach ($data as $entry) {
            if (is_array($entry) && isset($entry['class']) && is_string($entry['class'])) {
                $classes[] = $entry['class'];
            }
        }

        return $classes;
    }
}
