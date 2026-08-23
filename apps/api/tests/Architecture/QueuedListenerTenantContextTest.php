<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * The queued-LISTENER half of the tenant-context guard — R-8 gate finding P3-3.
 *
 * {@see QueueJobTenantContextTest} polices every concrete `ShouldQueue` class
 * under `app/Modules/<vertical>/{Jobs,Application/Jobs,Infrastructure/Jobs}`,
 * and says so in its own docblock: "queued LISTENERS … live outside the Jobs/
 * directory triplet and are NOT covered by this test. They are tracked for a
 * future cluster." R-8 made `PostShiftCashVarianceAdjustment` the first queued
 * listener on the Treasury/GL path, so that future arrived. This is the sibling.
 *
 * A queued listener must carry ONE of three classifications:
 *
 *   (a) the {@see BindsTenantContext} trait — the same explicit binding the Jobs
 *       guard accepts;
 *   (b) a class-level `@cross-tenant-by-design <justification>` — it genuinely
 *       spans tenants;
 *   (c) a class-level `@tenancy-via-queue-payload <justification>` — it relies on
 *       Stancl's `QueueTenancyBootstrapper` (`config/tenancy.php:42`), which
 *       stamps `tenant_id` into every job payload via a GLOBAL payload generator
 *       and re-initializes tenancy on `JobProcessing` / `JobRetryRequested`.
 *
 * (c) exists because it is the honest answer for a listener and has no equivalent
 * in the Jobs guard, where the house style is explicit binding. It is NOT a free
 * pass: the tag is required to carry text, and the text is where the author
 * states that the handler's queries are additionally scoped by ids carried ON the
 * event, so the class is correct even with no tenancy bound at all. A bare tag
 * fails, exactly as it does in the Jobs guard.
 *
 * The deferrals fixture (`tests/Architecture/fixtures/queued-listener-deferrals.json`)
 * lists the queued listeners that predate this guard. Classifying another
 * module's listener is that module's call, not R-8's, so the guard is introduced
 * non-retroactively and polices new queued listeners from here on. The fixture is
 * read fresh on every invocation, matching the Jobs guard's own convention.
 */
final class QueuedListenerTenantContextTest extends TestCase
{
    public function test_every_concrete_queued_listener_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverQueuedListenerClasses();

        $unclassified = [];
        $bareAnnotations = [];

        foreach ($classes as $class) {
            if (in_array($class, $deferrals, true)) {
                continue;
            }

            if ($this->classUsesTrait($class, BindsTenantContext::class)) {
                continue;
            }

            $docBlock = (new ReflectionClass($class))->getDocComment();

            if ($docBlock === false) {
                $unclassified[] = $class;

                continue;
            }

            $matched = false;
            foreach (['cross-tenant-by-design', 'tenancy-via-queue-payload'] as $tag) {
                if (preg_match('/@'.$tag.'\b[ \t]*([^\r\n]*)/m', $docBlock, $matches) !== 1) {
                    continue;
                }

                $matched = true;

                if (trim($matches[1]) === '') {
                    $bareAnnotations[] = $class.' (@'.$tag.')';
                }

                break;
            }

            if (! $matched) {
                $unclassified[] = $class;
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            "Found concrete ShouldQueue LISTENER(s) with no tenant-context classification:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nA queued listener crosses the queue boundary exactly like a queued job, so it MUST"
            .' declare how it carries the tenant. Use the App\\Jobs\\Concerns\\BindsTenantContext trait,'
            .' OR a class-level `@cross-tenant-by-design <justification>`,'
            .' OR — if it relies on Stancl QueueTenancyBootstrapper stamping tenant_id into the job'
            .' payload — a class-level `@tenancy-via-queue-payload <justification>` stating that the'
            .' handler is additionally scoped by ids carried on the event.',
        );

        $this->assertSame(
            [],
            $bareAnnotations,
            "Found queued listener(s) with a BARE tenancy annotation (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST carry non-empty justification on the same line.",
        );
    }

    /**
     * The guard is only worth having if it actually finds the class the gate was
     * about — a silently-empty discovery would make every assertion above pass
     * vacuously.
     */
    public function test_the_scan_reaches_the_treasury_shift_variance_listener(): void
    {
        $this->assertContains(
            PostShiftCashVarianceAdjustment::class,
            $this->discoverQueuedListenerClasses(),
            'The queued-listener scan no longer reaches the listener this guard was added for.',
        );
    }

    /**
     * Discover every concrete `ShouldQueue` class under any
     * `app/Modules/<vertical>/{Listeners,Application/Listeners,Infrastructure/Listeners}`.
     *
     * @return list<class-string<ShouldQueue>>
     */
    private function discoverQueuedListenerClasses(): array
    {
        $modulesRoot = base_path('app/Modules');

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

            // Anchored boundaries so we never capture e.g. /Domain/ListenersRegistry.php.
            if (preg_match('@/(Listeners|Application/Listeners|Infrastructure/Listeners)/@', $path) !== 1) {
                continue;
            }

            $class = $this->resolveClassFromFile($path);
            if ($class === null || ! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            /** @var class-string<ShouldQueue> $class */
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $seen[$class] = true;
        }

        return array_keys($seen);
    }

    private function resolveClassFromFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatches) !== 1) {
            return null;
        }
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $classMatches) !== 1) {
            return null;
        }

        return trim($nsMatches[1]).'\\'.$classMatches[1];
    }

    /**
     * @param  class-string  $class
     * @param  class-string  $traitClass
     */
    private function classUsesTrait(string $class, string $traitClass): bool
    {
        $traits = [];
        $current = $class;
        while ($current !== false) {
            foreach ((new ReflectionClass($current))->getTraitNames() as $traitName) {
                $this->collectTraitGraph($traitName, $traits);
            }
            $current = get_parent_class($current);
        }

        return isset($traits[$traitClass]);
    }

    /**
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
     * @return list<string>
     */
    private function loadDeferralsFresh(): array
    {
        $path = base_path('tests/Architecture/fixtures/queued-listener-deferrals.json');
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
