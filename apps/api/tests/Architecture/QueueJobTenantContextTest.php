<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Jobs\Concerns\BindsTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Step 6 of api.scheduled-jobs cluster (master plan §14 invariant 2 +
 * Codex Section 18 note).
 *
 * Asserts every concrete ShouldQueue class living under
 * `app/Modules/<vertical>/{Jobs,Application/Jobs,Infrastructure/Jobs}`
 * is tenant-classified:
 *
 *   (a) Uses the {@see BindsTenantContext} trait — the trait carries
 *       a public readonly string $tenantId on the using class and
 *       exposes withTenantContext(callable $fn): mixed which wraps
 *       the closure in Tenant::find($this->tenantId)?->run($fn) with
 *       fail-loud null handling.
 *
 *   (b) Carries a class-level PHPDoc tag `@cross-tenant-by-design <text>`
 *       with non-empty justification (Section 9 grammar, simplified
 *       single-line form). Bare `@cross-tenant-by-design` with no text
 *       fails this test — every annotation must name the genuine
 *       reason and is expected to mention "queue job" so the failure
 *       message disambiguates queue contexts from console-command
 *       contexts.
 *
 * Abstract classes are skipped via {@see ReflectionClass::isAbstract()}.
 *
 * The deferrals fixture (`tests/Architecture/fixtures/queue-job-deferrals.json`)
 * lists classes that live in another cluster's surface and have been
 * deferred for that cluster to handle. Listed classes are skipped here.
 * The fixture is read on every test invocation (NOT cached at class-load)
 * so a parallel cluster mutating the file mid-run is picked up after the
 * next `git pull --ff-only`.
 *
 * Scope boundary: queued LISTENERS (e.g. EarnPointsOnReceiptCompleted)
 * and queued NOTIFICATIONS (e.g. PaymentSucceededNotification) live
 * outside the Jobs/ directory triplet and are NOT covered by this
 * test. They are tracked for a future cluster at
 * docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md
 * (Finding C).
 */
final class QueueJobTenantContextTest extends TestCase
{
    public function test_every_concrete_queue_job_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverQueueJobClasses();

        $unclassified = [];
        $bareAnnotations = [];

        foreach ($classes as $class) {
            if (in_array($class, $deferrals, true)) {
                continue;
            }

            // (a) Uses BindsTenantContext trait
            if ($this->classUsesTrait($class, BindsTenantContext::class)) {
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
            "Found concrete ShouldQueue class(es) under app/Modules/*/Jobs|Application/Jobs|Infrastructure/Jobs with no tenant-context classification:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEach concrete queue job MUST EITHER use the App\\Jobs\\Concerns\\BindsTenantContext trait"
            .' (which requires a public readonly string $tenantId constructor property and wrapping handle()'
            .' in $this->withTenantContext(fn () => ...))'
            .' OR carry a class-level PHPDoc tag `@cross-tenant-by-design <non-empty justification>`'
            ." that includes the words 'queue job' in the justification text."
            ."\nSee docs/superpowers/audits/2026-05-07-api-scheduled-jobs-triage.md for the cluster's classification rules.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found ShouldQueue class(es) with bare `@cross-tenant-by-design` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line that mentions 'queue job'."
            ."\nExample: `@cross-tenant-by-design Daily system-wide batch expiry sweep queue job — iterates Batch rows across all tenants by design.`",
        );
    }

    /**
     * Discover every concrete (non-abstract) class implementing
     * Illuminate\Contracts\Queue\ShouldQueue that lives under any
     * app/Modules/<vertical>/{Jobs,Application/Jobs,Infrastructure/Jobs}
     * directory.
     *
     * Listener and notification queue classes (under Listeners/ and
     * Notifications/ directories) are intentionally excluded — they
     * fall under future clusters per the cross-cluster observations
     * audit.
     *
     * @return list<class-string<ShouldQueue>>
     */
    private function discoverQueueJobClasses(): array
    {
        $basePath = base_path();
        $modulesRoot = $basePath.'/app/Modules';

        if (! is_dir($modulesRoot)) {
            return [];
        }

        /** @var array<class-string<ShouldQueue>, true> $seen */
        $seen = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // Match exactly the three Jobs/ directory shapes — anchored
            // boundaries so we never accidentally capture e.g.
            // /Domain/JobsRepository.php or /JobsHistory.php.
            if (preg_match('@/(Jobs|Application/Jobs|Infrastructure/Jobs)/@', $path) !== 1) {
                continue;
            }

            $class = $this->resolveClassFromFile($path);
            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            /** @var class-string<ShouldQueue> $class */
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $seen[$class] = true;
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
     * Walk the class's trait graph fully recursively (own traits + parent
     * traits + traits used by traits at arbitrary nesting depth) and
     * return true if $traitClass appears anywhere. Recursion-safe via a
     * visited-set so cyclic trait graphs (PHP allows them) don't loop.
     *
     * @param  class-string  $class
     * @param  class-string  $traitClass
     */
    private function classUsesTrait(string $class, string $traitClass): bool
    {
        $traits = [];
        $current = $class;
        while ($current !== false) {
            $reflection = new ReflectionClass($current);
            foreach ($reflection->getTraitNames() as $traitName) {
                $this->collectTraitGraph($traitName, $traits);
            }
            $current = get_parent_class($current);
        }

        return isset($traits[$traitClass]);
    }

    /**
     * Recursively flatten a trait's trait graph into $accumulated. The
     * visited-set ($accumulated keys) ensures O(N) traversal even if the
     * trait composition forms a cycle.
     *
     * @param  class-string  $traitName
     * @param  array<class-string, true>  $accumulated
     */
    private function collectTraitGraph(string $traitName, array &$accumulated): void
    {
        if (isset($accumulated[$traitName])) {
            return;
        }
        $accumulated[$traitName] = true;

        foreach ((new ReflectionClass($traitName))->getTraitNames() as $nested) {
            /** @var class-string $nested */
            $this->collectTraitGraph($nested, $accumulated);
        }
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
        $path = base_path('tests/Architecture/fixtures/queue-job-deferrals.json');
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
