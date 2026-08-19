<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Liveness test for tools/feature-lane-manifest-check.php.
 *
 * docs/conventions/08-DETECTOR-LIVENESS.md — the convention this same package
 * landed at M1 — requires every detector to ship with at least one test proving
 * it FIRES on a planted violation, in the same CI lane as the detector. The
 * checker was the one detector in the package that had only a recorded red/green
 * transcript, so a later edit to its `--filter` parsing or its anchoring test
 * could silently stop it seeing the allowlists (exactly the C6 rot the convention
 * cites).
 *
 * Every case drives a COPY of the real tree in a temp dir; nothing here mutates
 * the repository.
 */
final class FeatureLaneManifestCheckerTest extends TestCase
{
    private string $sandbox;

    private string $apiRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoot = dirname(__DIR__, 2);
        $this->sandbox = sys_get_temp_dir() . '/flm-' . bin2hex(random_bytes(6));

        mkdir($this->sandbox . '/apps/api/tools', 0o777, true);
        mkdir($this->sandbox . '/apps/api/tests', 0o777, true);
        mkdir($this->sandbox . '/.github/workflows', 0o777, true);

        copy(
            $this->apiRoot . '/tools/feature-lane-manifest-check.php',
            $this->sandbox . '/apps/api/tools/feature-lane-manifest-check.php',
        );
        copy(
            $this->apiRoot . '/tests/feature-lane-manifest.json',
            $this->sandbox . '/apps/api/tests/feature-lane-manifest.json',
        );
        copy(
            $this->apiRoot . '/../../.github/workflows/ci.yml',
            $this->sandbox . '/.github/workflows/ci.yml',
        );
        // The checker resolves vendor/autoload.php relative to its api root.
        symlink($this->apiRoot . '/vendor', $this->sandbox . '/apps/api/vendor');
        // Only the tree shape matters, so mirror the real Feature dirs by symlink.
        symlink($this->apiRoot . '/tests/Feature', $this->sandbox . '/apps/api/tests/Feature');
        foreach (['Unit', 'Integration', 'Architecture', 'PHPStan'] as $suite) {
            if (is_dir($this->apiRoot . '/tests/' . $suite)) {
                symlink($this->apiRoot . '/tests/' . $suite, $this->sandbox . '/apps/api/tests/' . $suite);
            }
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->sandbox));
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function runChecker(): array
    {
        $output = [];
        $exit = 0;
        exec(
            'php ' . escapeshellarg($this->sandbox . '/apps/api/tools/feature-lane-manifest-check.php') . ' 2>&1',
            $output,
            $exit,
        );

        return [$exit, implode("\n", $output)];
    }

    private function workflow(): string
    {
        return (string) file_get_contents($this->sandbox . '/.github/workflows/ci.yml');
    }

    private function writeWorkflow(string $contents): void
    {
        file_put_contents($this->sandbox . '/.github/workflows/ci.yml', $contents);
    }

    public function test_it_passes_on_the_real_tree(): void
    {
        [$exit, $out] = $this->runChecker();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('lane manifest OK', $out);
    }

    public function test_it_fires_when_an_allowlist_is_unanchored(): void
    {
        $wf = $this->workflow();
        $start = strpos($wf, "--filter='/");
        self::assertNotFalse($start, 'expected an anchored --filter in ci.yml');
        $end = strpos($wf, "::/'", $start);
        $inner = substr($wf, $start + strlen("--filter='/\\\\("), $end - $start - strlen("--filter='/\\\\("));
        $this->writeWorkflow(
            substr($wf, 0, $start) . '--filter="' . $inner . '"' . substr($wf, $end + strlen("::/'")),
        );

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNANCHORED --filter', $out);
    }

    public function test_it_fires_when_a_filter_uses_the_unquoted_form(): void
    {
        // PHPUnit and `php artisan test` both accept it; a quoted-only scanner
        // would let the substring-shadowing mechanism back in silently.
        $wf = $this->workflow();
        // A COMPLETE extra step — injecting a second `run:` into an existing step
        // would be invalid YAML and would test the parser, not the filter scanner.
        $this->writeWorkflow(str_replace(
            "      - name: Check tests/Feature CI-lane manifest",
            "      - name: Zzz planted unquoted filter\n"
            . "        run: php artisan test --filter=AnalyticsTest|Foo\n\n"
            . "      - name: Check tests/Feature CI-lane manifest",
            $wf,
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNANCHORED --filter', $out);
    }

    public function test_it_fires_when_a_lane_selector_is_commented_out(): void
    {
        // A raw file-text search still matches a commented step, so the manifest
        // would keep certifying 119 Treasury classes as lane-covered.
        $this->writeWorkflow(str_replace(
            'run: ./vendor/bin/phpunit tests/Feature/Treasury',
            'run: echo skipped  # ./vendor/bin/phpunit tests/Feature/Treasury (disabled)',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('is a FICTION', $out);
    }

    public function test_it_fires_on_an_unassigned_group(): void
    {
        $manifestPath = $this->sandbox . '/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['groups']['Admin']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNASSIGNED GROUP "Admin"', $out);
    }

    public function test_it_fires_when_the_coverage_debt_grows(): void
    {
        // The strict half of the brief's negative proof: a new class in an
        // EXISTING uncovered group must fail loudly, not tick a stdout counter.
        $manifestPath = $this->sandbox . '/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['groups']['Admin']['classes'] -= 1;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('COVERAGE DEBT GREW', $out);
    }

    public function test_it_fires_when_a_lane_misreports_its_pr_dev_coverage(): void
    {
        $manifestPath = $this->sandbox . '/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['lanes']['backend-test/security']['runs_on_pr_dev'] = true;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
    }
}
