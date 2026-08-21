<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
        $this->sandbox = sys_get_temp_dir().'/flm-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox.'/apps/api/tools', 0o777, true);
        mkdir($this->sandbox.'/apps/api/tests', 0o777, true);
        mkdir($this->sandbox.'/.github/workflows', 0o777, true);

        copy(
            $this->apiRoot.'/tools/feature-lane-manifest-check.php',
            $this->sandbox.'/apps/api/tools/feature-lane-manifest-check.php',
        );
        copy(
            $this->apiRoot.'/tests/feature-lane-manifest.json',
            $this->sandbox.'/apps/api/tests/feature-lane-manifest.json',
        );
        copy(
            $this->apiRoot.'/../../.github/workflows/ci.yml',
            $this->sandbox.'/.github/workflows/ci.yml',
        );
        // O-29: the quarantine ratchet the Feature lanes triage through. Copied so
        // the happy-path case exercises its validation too, not only the negative.
        copy(
            $this->apiRoot.'/tests/quarantine.json',
            $this->sandbox.'/apps/api/tests/quarantine.json',
        );
        // The checker resolves vendor/autoload.php relative to its api root.
        symlink($this->apiRoot.'/vendor', $this->sandbox.'/apps/api/vendor');
        // Only the tree shape matters, so mirror the real Feature dirs by symlink.
        symlink($this->apiRoot.'/tests/Feature', $this->sandbox.'/apps/api/tests/Feature');
        foreach (['Unit', 'Integration', 'Architecture', 'PHPStan'] as $suite) {
            if (is_dir($this->apiRoot.'/tests/'.$suite)) {
                symlink($this->apiRoot.'/tests/'.$suite, $this->sandbox.'/apps/api/tests/'.$suite);
            }
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->sandbox));
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function runChecker(): array
    {
        $output = [];
        $exit = 0;
        exec(
            'php '.escapeshellarg($this->sandbox.'/apps/api/tools/feature-lane-manifest-check.php').' 2>&1',
            $output,
            $exit,
        );

        return [$exit, implode("\n", $output)];
    }

    private function workflow(): string
    {
        return (string) file_get_contents($this->sandbox.'/.github/workflows/ci.yml');
    }

    private function writeWorkflow(string $contents): void
    {
        file_put_contents($this->sandbox.'/.github/workflows/ci.yml', $contents);
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
            substr($wf, 0, $start).'--filter="'.$inner.'"'.substr($wf, $end + strlen("::/'")),
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
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz planted unquoted filter\n"
            ."        run: php artisan test --filter=AnalyticsTest|Foo\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
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
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
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
        //
        // The fixture group is resolved from the manifest rather than hard-coded:
        // O-29 laned every group that used to be `deferred` except `(root files)`,
        // so a literal name here would have silently started exercising the
        // PARKED-lane ceiling (its own case below) instead of this one.
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $deferred = null;
        foreach ($manifest['groups'] as $group => $entry) {
            if (($entry['deferred'] ?? false) === true) {
                $deferred = (string) $group;
                break;
            }
        }
        self::assertNotNull($deferred, 'expected at least one deferred group to exercise the debt ceiling');
        $manifest['groups'][$deferred]['classes'] -= 1;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('COVERAGE DEBT GREW', $out);
    }

    /**
     * O-29: a group laned into a lane that has not been switched on yet keeps the
     * ceiling it had while `deferred`. Without this, "wire a lane, leave the flag
     * off" would be a QUIETER dumping ground than the state it replaced.
     */
    public function test_it_fires_when_a_parked_lane_group_grows(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['groups']['Admin']['classes'] -= 1;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('PARKED-LANE COVERAGE GREW', $out);
    }

    /**
     * O-29: the erasure this whole file exists to prevent, one level down. A lane
     * can be REAL, run the WHOLE directory, sit in the aggregate — and execute on
     * no event at all, because its job is guarded by a repository variable that is
     * off. Dropping `execution_gate` from the manifest would zero the parked
     * counter while nothing runs.
     */
    public function test_it_fires_when_a_flag_gated_lane_hides_its_gate(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['lanes']['feature-lane-platform-misc/Api']['execution_gate']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('declares no `execution_gate`', $out);
    }

    /** O-29: the declared gate must be the gate the job actually carries. */
    public function test_it_fires_when_a_lane_declares_a_gate_the_job_does_not_carry(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['lanes']['feature-lane-platform-misc/Api']['execution_gate'] = "vars.SOME_OTHER_FLAG == 'true'";
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('does not reference it', $out);
    }

    /**
     * O-29: `all-checks-pass` tolerates a SKIPPED dependency only for the
     * flag-gated lane jobs. Adding any other job to that list would make its
     * absence forgivable to the one gate that aggregates every other gate.
     */
    public function test_it_fires_when_the_aggregate_tolerates_a_skip_it_should_not(): void
    {
        $wf = $this->workflow();
        self::assertStringContainsString('ALLOW_SKIPPED_JOBS: feature-lane-', $wf);
        $this->writeWorkflow(str_replace(
            'ALLOW_SKIPPED_JOBS: feature-lane-catalog',
            'ALLOW_SKIPPED_JOBS: security-regression,feature-lane-catalog',
            $wf,
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('AGGREGATE SKIP-TOLERANCE MISMATCH', $out);
    }

    /**
     * O-29: lane resolution is a PREFIX match, so `tests/Feature/Service` also
     * matches the `tests/Feature/Services` step. The trailing slash on every new
     * lane selector is what keeps resolution one-to-one; strip it and the checker
     * must refuse to certify either lane.
     */
    public function test_it_fires_when_a_lane_selector_becomes_ambiguous(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['lanes']['feature-lane-documents/Service']['selector'] = './vendor/bin/phpunit tests/Feature/Service';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('resolves to 2 steps', $out);
    }

    /**
     * O-29 triage posture: the quarantine list is a shrink-only ratchet, not a
     * waiver. Raising the entry count above the recorded ceiling must fail.
     */
    public function test_it_fires_when_the_quarantine_grows(): void
    {
        $quarantinePath = $this->sandbox.'/apps/api/tests/quarantine.json';
        $quarantine = json_decode((string) file_get_contents($quarantinePath), true, 512, JSON_THROW_ON_ERROR);
        $quarantine['entries']['Tests\\Feature\\Admin\\AdminDashboardStatsTest'] = [
            'lane' => 'feature-lane-tenancy/Admin',
            'reason' => 'planted by the liveness test',
            'opened' => '2026-08-21',
        ];
        file_put_contents($quarantinePath, json_encode($quarantine, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('QUARANTINE GREW', $out);
    }

    public function test_it_fires_when_a_lane_misreports_its_pr_dev_coverage(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['lanes']['security-regression']['runs_on_pr_dev'] = false;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
    }

    /**
     * LIVENESS PROOF for the parent's 2026-08-19 F-2 sub-decision.
     *
     * tests/Feature/Security was moved out of the `if:`-gated `backend-test` job
     * into the dedicated `security-regression` job precisely so it runs on the
     * PR→dev merge gate. The way that silently regresses is someone adding an
     * `if:` guard back onto the job — which is invisible to every other check.
     * The manifest's `runs_on_pr_dev: true` is verified against the live job
     * `if:`, so re-gating the job must FAIL.
     */
    public function test_it_fires_when_the_security_job_is_re_gated_off_pr_dev(): void
    {
        $wf = $this->workflow();
        self::assertStringContainsString('  security-regression:', $wf);

        $this->writeWorkflow(str_replace(
            "  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n",
            "  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n"
            ."    if: github.event_name == 'workflow_dispatch' || github.base_ref == 'main'\n",
            $wf,
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
        self::assertStringContainsString('security-regression', $out);
    }

    public function test_the_security_suite_is_wired_to_a_job_with_no_if_guard(): void
    {
        // Positive assertion of the ruled state, so the move itself is pinned and
        // not merely the absence of a regression.
        $wf = Yaml::parse($this->workflow());

        self::assertArrayHasKey('security-regression', $wf['jobs']);
        self::assertArrayNotHasKey('if', $wf['jobs']['security-regression']);
        self::assertContains('security-regression', $wf['jobs']['all-checks-pass']['needs']);

        $runs = [];
        foreach ($wf['jobs']['security-regression']['steps'] as $step) {
            if (isset($step['run'])) {
                $runs[] = $step['run'];
            }
        }
        self::assertContains('./vendor/bin/phpunit tests/Feature/Security', $runs);

        // …and it is no longer duplicated inside the if:-gated job it came from.
        foreach ($wf['jobs']['backend-test']['steps'] as $step) {
            self::assertStringNotContainsString('tests/Feature/Security', (string) ($step['run'] ?? ''));
        }
    }

    /**
     * N-1: a STEP-level `if:` skips the step exactly as a job guard would, and the
     * round-2 reviewer bypassed the PR->dev guarantee with two words on the step
     * while both existing liveness cases stayed green.
     */
    public function test_it_fires_when_a_step_level_if_gates_the_security_lane(): void
    {
        $this->writeWorkflow(str_replace(
            "      - name: Security regression suite (module gating + kill-switches)\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            "      - name: Security regression suite (module gating + kill-switches)\n"
            ."        if: github.base_ref == 'main'\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
        self::assertStringContainsString('the step carries', $out);
    }

    /**
     * N-4: GitHub skips a job whose dependency was skipped, so a `needs:` on a
     * gated job removes the lane from PR->dev through a second door.
     */
    public function test_it_fires_when_the_security_job_needs_a_gated_job(): void
    {
        $this->writeWorkflow(str_replace(
            "  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n",
            "  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n"
            ."    needs: [backend-test]\n",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
        self::assertStringContainsString('depends (transitively) on', $out);
    }

    /**
     * N-2: `--filter` is not a PHPUnit-only token. This is a pnpm workspace and
     * `pnpm --filter @autoerp/web …` is the prescribed form (AGENTS.md); scanning
     * every `--filter` made that normal command hard-fail an ungated job.
     */
    public function test_it_does_not_flag_a_pnpm_workspace_filter(): void
    {
        $this->writeWorkflow(str_replace(
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz pnpm workspace build\n"
            ."        run: pnpm --filter @autoerp/web build\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('@autoerp/web', $out);
    }

    /**
     * N-3: validating only that a lane EXISTS let all 71 deferred groups be
     * rewritten to a real lane by search-and-replace — the checker reported OK and
     * the entire COVERAGE DEBT block disappeared. A lane must actually run the group.
     */
    public function test_it_fires_when_a_group_claims_a_lane_that_does_not_run_it(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['groups']['Admin'] = ['lane' => 'treasury-spine-pgsql/feature-treasury'];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('does not run tests/Feature/Admin', $out);
    }

    public function test_the_whole_debt_cannot_be_erased_by_relabelling_groups(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['groups'] as $group => $entry) {
            if (($entry['deferred'] ?? false) === true) {
                $manifest['groups'][$group] = ['lane' => 'treasury-spine-pgsql/feature-treasury'];
            }
        }
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('does not run tests/Feature/', $out);
    }

    /** R-1: `continue-on-error` lets the step run and show green while no longer gating. */
    public function test_it_fires_when_the_security_step_is_continue_on_error(): void
    {
        $this->writeWorkflow(str_replace(
            "      - name: Security regression suite (module gating + kill-switches)\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            "      - name: Security regression suite (module gating + kill-switches)\n"
            ."        continue-on-error: true\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('continue-on-error', $out);
    }

    /** R-5: GitHub skips transitively, so a two-job chain hides the same hole. */
    public function test_it_fires_on_a_transitively_gated_needs_chain(): void
    {
        $wf = $this->workflow();
        $wf = str_replace(
            "  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n",
            "  zzz-bridge:\n    name: Zzz bridge\n    runs-on: ubuntu-latest\n    needs: [backend-test]\n"
            ."    steps:\n      - run: echo bridge\n\n"
            ."  security-regression:\n    name: Security Regression (module gating + kill-switches)\n    runs-on: ubuntu-latest\n"
            ."    needs: [zzz-bridge]\n",
            $wf,
        );
        $this->writeWorkflow($wf);

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('transitively', $out);
    }

    /** R-3: appending --filter narrows a whole-directory lane to one class. */
    public function test_it_fires_when_a_whole_directory_lane_is_narrowed(): void
    {
        $this->writeWorkflow(str_replace(
            'run: ./vendor/bin/phpunit tests/Feature/Security',
            "run: ./vendor/bin/phpunit tests/Feature/Security --filter='/\\\\(SomeOneTest)::/'",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('whole-directory lane', $out);
    }

    /** R-7: a same-job decoy step that merely MENTIONS the selector must not shadow the real one. */
    public function test_it_is_not_fooled_by_a_decoy_step_mentioning_the_selector(): void
    {
        $wf = str_replace(
            "      - name: Security regression suite (module gating + kill-switches)\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            "      - name: Zzz decoy\n"
            ."        run: echo 'runs ./vendor/bin/phpunit tests/Feature/Security below'\n\n"
            ."      - name: Security regression suite (module gating + kill-switches)\n"
            ."        if: github.base_ref == 'main'\n"
            .'        run: ./vendor/bin/phpunit tests/Feature/Security',
            $this->workflow(),
        );
        $this->writeWorkflow($wf);

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('runs_on_pr_dev', $out);
    }

    /** R-2: CLAUDE.md's own documented `composer test -- --filter=…` must still be scanned. */
    public function test_it_still_flags_an_unanchored_filter_in_a_composer_test_command(): void
    {
        $this->writeWorkflow(str_replace(
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz composer test with a bare filter\n"
            ."        run: composer test -- --filter=AnalyticsTest\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNANCHORED --filter', $out);
    }

    /** R-6: a genuine PHPUnit filter must not be swallowed just because a pnpm call shares the script. */
    public function test_it_still_flags_a_phpunit_filter_sharing_a_script_with_pnpm(): void
    {
        $this->writeWorkflow(str_replace(
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz mixed script\n"
            ."        run: |\n"
            ."          pnpm --filter @autoerp/web build\n"
            ."          ./vendor/bin/phpunit --filter=AnalyticsTest\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNANCHORED --filter', $out);
        self::assertStringNotContainsString('@autoerp/web', $out);
    }

    /** @return array{0:int,1:string} */
    private function withLaneRun(string $replacement): array
    {
        $this->writeWorkflow(str_replace(
            'run: ./vendor/bin/phpunit tests/Feature/Security',
            $replacement,
            $this->workflow(),
        ));

        return $this->runChecker();
    }

    /** G-1: a narrower PATH cuts a 17-class lane to 1, and no flag denylist sees it. */
    public function test_it_fires_when_a_lane_is_narrowed_to_a_single_file(): void
    {
        [$exit, $out] = $this->withLaneRun(
            'run: ./vendor/bin/phpunit tests/Feature/Security/ModuleAccessControlTest.php',
        );

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('whole-directory lane', $out);
    }

    /** G-1: `--list-tests` exits 0 having run nothing. */
    public function test_it_fires_when_a_lane_only_lists_tests(): void
    {
        [$exit, $out] = $this->withLaneRun('run: ./vendor/bin/phpunit tests/Feature/Security --list-tests');

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('whole-directory lane', $out);
    }

    /** G-2: `|| true` — the soft-fail people actually type. */
    public function test_it_fires_on_a_shell_soft_failed_lane(): void
    {
        [$exit, $out] = $this->withLaneRun('run: ./vendor/bin/phpunit tests/Feature/Security || true');

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('cannot fail the step', $out);
    }

    /** G-2: `; exit 0`. */
    public function test_it_fires_on_a_lane_that_swallows_its_exit_code(): void
    {
        [$exit, $out] = $this->withLaneRun('run: ./vendor/bin/phpunit tests/Feature/Security; exit 0');

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('cannot fail the step', $out);
    }

    /** G-1: a neutral flag must NOT be rejected — the allowlist has to stay usable. */
    public function test_it_accepts_a_lane_carrying_only_neutral_flags(): void
    {
        [$exit, $out] = $this->withLaneRun(
            'run: ./vendor/bin/phpunit tests/Feature/Security --no-progress --colors=never',
        );

        self::assertSame(0, $exit, $out);
    }

    /** G-3: relabelling `deferred` -> `excluded` must not erase the debt. */
    public function test_relabelling_deferred_as_excluded_does_not_erase_the_debt(): void
    {
        // Capture the live debt count BEFORE relabelling instead of pasting the
        // number: the pasted literal (1114) went stale the first time a merged
        // lane legitimately raised the ceilings, failing this test for a change
        // it exists to permit. The invariant is that the COUNT SURVIVES the
        // relabelling, whatever it currently is.
        [$exitBefore, $outBefore] = $this->runChecker();
        self::assertSame(0, $exitBefore, $outBefore);
        if (preg_match('/COVERAGE DEBT: \d+ group\(s\) \/ (\d+) class\(es\)/', $outBefore, $m) !== 1) {
            self::fail('COVERAGE DEBT count line not found in checker output: '.$outBefore);
        }
        $debtCount = $m[1];

        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['groups'] as $group => $entry) {
            if (($entry['deferred'] ?? false) === true) {
                unset($manifest['groups'][$group]['deferred']);
                $manifest['groups'][$group]['excluded'] = true;
            }
        }
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('EXCLUDED:', $out);
        self::assertStringContainsString(sprintf('%s class(es)', $debtCount), $out);
    }

    /** G-5 / R-8: `always()` skips nothing — it must NOT hard-fail every PR. */
    public function test_it_accepts_an_always_true_step_if(): void
    {
        [$exit, $out] = $this->withLaneRun(
            "if: always()\n        run: ./vendor/bin/phpunit tests/Feature/Security",
        );

        self::assertSame(0, $exit, $out);
    }

    /** G-6 / R-9: aggregate membership is now the checker's job, for every lane. */
    public function test_it_fires_when_a_lane_job_leaves_the_aggregate(): void
    {
        $this->writeWorkflow(str_replace(
            'backend-test-pgsql, security-regression, treasury-spine-pgsql',
            'backend-test-pgsql, treasury-spine-pgsql',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('all-checks-pass', $out);
        self::assertStringContainsString('security-regression', $out);
    }

    /** G-6: the job carrying the checker itself is pinned too. */
    public function test_it_fires_when_backend_architecture_leaves_the_aggregate(): void
    {
        $this->writeWorkflow(str_replace(
            'backend-lint, backend-analyse, backend-architecture,',
            'backend-lint, backend-analyse,',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('backend-architecture', $out);
    }

    /** G-7: env/wrapper-prefixed package-manager calls must not false-positive. */
    public function test_it_does_not_flag_env_prefixed_pnpm_filters(): void
    {
        $this->writeWorkflow(str_replace(
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz env prefixed pnpm\n"
            ."        run: env CI=1 pnpm --filter @autoerp/web build\n\n"
            ."      - name: Zzz npx prefixed pnpm\n"
            ."        run: npx pnpm --filter @autoerp/pos build\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('@autoerp/', $out);
    }

    /**
     * H-1, closed EMPIRICALLY rather than lexically — the reviewer's own suggestion,
     * and the brief's R2-H-7 requirement (an asserted NONZERO selected-test count;
     * `phpunit.xml` sets no `failOnEmptyTestSuite`, so exit code alone proves nothing).
     *
     * Every declared lane's ACTUAL ci.yml `run:` line — resolved from the parsed
     * workflow, never the manifest's declared selector string — is executed with
     * `--list-tests` appended and must select at least one test. This closes `-c`/`--configuration`, `--group`,
     * `--list-tests`, a narrower path and the whole empty-selection family by
     * OBSERVATION, not by maintaining a flag list.
     */
    public function test_every_lane_actually_selects_tests(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->apiRoot.'/tests/feature-lane-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $workflow = Yaml::parse(
            (string) file_get_contents($this->apiRoot.'/../../.github/workflows/ci.yml'),
        );

        // Collect every LIVE `run:` script, exactly as the checker does.
        $runs = [];
        foreach (($workflow['jobs'] ?? []) as $job) {
            foreach (($job['steps'] ?? []) as $step) {
                if (isset($step['run'])) {
                    $runs[] = (string) $step['run'];
                }
            }
        }

        self::assertNotEmpty($manifest['lanes']);

        foreach ($manifest['lanes'] as $laneId => $lane) {
            $selector = (string) $lane['selector'];

            // THE ACTUAL RUN LINE from the parsed workflow — not the manifest's
            // declared selector string. Executing the selector would test the
            // manifest against itself: nothing appended to the workflow's run line
            // could change the outcome, so `-c evil.xml`, `--group nonexistent`,
            // `--list-tests` or a narrower path would all stay green and the
            // empty-selection family would be closed lexically only.
            $laneRun = null;
            foreach ($runs as $run) {
                if (str_starts_with(trim($run), $selector)) {
                    $laneRun = trim($run);
                    break;
                }
            }
            self::assertNotNull($laneRun, "lane {$laneId}: selector not found in any live ci.yml run: block");

            $output = [];
            $exit = 0;
            exec(
                'cd '.escapeshellarg($this->apiRoot).' && '.$laneRun.' --list-tests 2>&1',
                $output,
                $exit,
            );
            $listed = array_filter($output, static fn (string $l): bool => str_starts_with(trim($l), '- '));

            self::assertSame(0, $exit, "lane {$laneId}: --list-tests failed\n".implode("\n", $output));
            self::assertNotEmpty(
                $listed,
                "lane {$laneId} selects ZERO tests — it cannot gate anything. Run line: {$laneRun}",
            );
        }
    }

    /** H-1: `-c` redefines the whole invocation, so it is not a neutral flag. */
    public function test_it_fires_when_a_lane_carries_a_configuration_flag(): void
    {
        [$exit, $out] = $this->withLaneRun(
            'run: ./vendor/bin/phpunit tests/Feature/Security -c phpunit-security.xml',
        );

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('whole-directory lane', $out);
    }

    /** H-2: the aggregate assertion must not be erasable by deleting an optional manifest key. */
    public function test_aggregate_membership_survives_deleting_the_manifest_job_key(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['lanes']['security-regression']['job']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->writeWorkflow(str_replace(
            'backend-test-pgsql, security-regression, treasury-spine-pgsql',
            'backend-test-pgsql, treasury-spine-pgsql',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('security-regression', $out);
    }

    /** H-3: retiring a whole lane must not quietly grow the TOTAL debt. */
    public function test_it_fires_when_retiring_a_lane_grows_the_total_debt(): void
    {
        $manifestPath = $this->sandbox.'/apps/api/tests/feature-lane-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['groups']['Security'] = [
            'deferred' => true,
            'classes' => 17,
            'reason' => 'retired lane (test)',
        ];
        unset($manifest['lanes']['security-regression']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('TOTAL COVERAGE DEBT GREW', $out);
    }

    /** H-4: a PHPUnit filter FORWARDED through a pnpm script must still be scanned. */
    public function test_it_flags_a_filter_forwarded_through_a_pnpm_script(): void
    {
        $this->writeWorkflow(str_replace(
            '      - name: Check tests/Feature CI-lane manifest',
            "      - name: Zzz wrapper script\n"
            ."        run: pnpm test:backend --filter=AnalyticsTest\n\n"
            .'      - name: Check tests/Feature CI-lane manifest',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('UNANCHORED --filter', $out);
    }

    /** N-1: the trigger set is the root of the event graph and was never checked. */
    public function test_it_fires_when_the_workflow_stops_running_on_pr_dev(): void
    {
        $this->writeWorkflow(str_replace(
            'branches: [main, dev]',
            'branches: [main]',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('TRIGGER SET', $out);
    }

    /** N-2: the checker's own job, gated. */
    public function test_it_fires_when_its_own_host_job_is_gated(): void
    {
        $this->writeWorkflow(str_replace(
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n",
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n"
            ."    if: github.base_ref == 'main'\n",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /** N-2: the checker's own job, softened. */
    public function test_it_fires_when_its_own_host_job_is_continue_on_error(): void
    {
        $this->writeWorkflow(str_replace(
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n",
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n"
            ."    continue-on-error: true\n",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('continue-on-error', $out);
    }

    /** N-2: the checker's own job, made to depend on a gated job. */
    public function test_it_fires_when_its_own_host_job_needs_a_gated_job(): void
    {
        $this->writeWorkflow(str_replace(
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n",
            "  backend-architecture:\n    name: Backend Architecture Boundary (Deptrac ratchet)\n    runs-on: ubuntu-latest\n"
            ."    needs: [backend-test]\n",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /** N-2: the checker's own STEP, softened. */
    public function test_it_fires_when_its_own_step_is_continue_on_error(): void
    {
        $this->writeWorkflow(str_replace(
            '        run: php tools/feature-lane-manifest-check.php',
            "        continue-on-error: true\n        run: php tools/feature-lane-manifest-check.php",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /** N-2: deleting the liveness step outright must not be silent. */
    public function test_it_fires_when_its_own_liveness_step_is_deleted(): void
    {
        $this->writeWorkflow(str_replace(
            '        run: ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php',
            '        run: echo removed',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /**
     * N-3 guard. `test_every_lane_actually_selects_tests` is the empirical backstop
     * for the whole empty-selection family; it is only a backstop if it executes
     * the WORKFLOW's run line rather than the manifest's declared selector string
     * (which would be testing the manifest against itself — nothing appended to
     * ci.yml could change its outcome).
     *
     * This assertion is lexical, and says so: it pins the method to resolving its
     * command from the parsed workflow. The behavioural proof that the resolution
     * works is `test_it_fires_when_a_lane_carries_a_configuration_flag` plus the
     * checker's own run-line resolution, which share the same `str_starts_with`
     * contract.
     */
    public function test_the_selection_assertion_resolves_from_the_workflow(): void
    {
        $src = (string) file_get_contents(__DIR__.'/FeatureLaneManifestCheckerTest.php');
        $start = strpos($src, 'public function test_every_lane_actually_selects_tests');
        self::assertNotFalse($start);
        // Bound the window at the NEXT method rather than a fixed character count,
        // so growing the method cannot silently move code out of view.
        $next = strpos($src, "\n    public function ", $start + 10);
        $method = substr($src, $start, ($next === false ? strlen($src) : $next) - $start);

        // Whitespace-insensitive: Pint's concat_space fixer rewrites `' . $x` as
        // `'.$x`, which a literal-needle check silently stops matching — this guard
        // was already vacuous once for exactly that reason.
        $squashed = preg_replace('/\s+/', '', $method) ?? '';

        self::assertStringContainsString('ci.yml', $method, 'must read the workflow');
        self::assertStringContainsString('$laneRun', $squashed, 'must execute the resolved run line');
        self::assertStringNotContainsString(
            ".'&&'.$".'selector',
            $squashed,
            'must NOT execute the manifest selector string directly',
        );
    }

    /** Round-7 finding 2: the shell-soft door must apply to the checker's OWN steps too. */
    public function test_it_fires_when_its_own_step_is_shell_soft_failed(): void
    {
        $this->writeWorkflow(str_replace(
            '        run: php tools/feature-lane-manifest-check.php',
            '        run: php tools/feature-lane-manifest-check.php || true',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /** Round-7 finding 2, second form: `; exit 0` on the liveness step. */
    public function test_it_fires_when_its_own_liveness_step_swallows_its_exit_code(): void
    {
        $this->writeWorkflow(str_replace(
            '        run: ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php',
            '        run: ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php; exit 0',
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('SELF-CHECK', $out);
    }

    /** Round-7 finding 3: paths-ignore of everything stops every PR starting the workflow. */
    public function test_it_fires_when_paths_ignore_excludes_everything(): void
    {
        $this->writeWorkflow(str_replace(
            '    branches: [main, dev]',
            "    branches: [main, dev]\n    paths-ignore: ['**']",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('excludes every path', $out);
    }

    /** Round-7 finding 3, second form: a `types` list without the ordinary PR events. */
    public function test_it_fires_when_pr_types_exclude_ordinary_events(): void
    {
        $this->writeWorkflow(str_replace(
            '    branches: [main, dev]',
            "    branches: [main, dev]\n    types: [labeled]",
            $this->workflow(),
        ));

        [$exit, $out] = $this->runChecker();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('contains none of opened/synchronize/reopened', $out);
    }
}
