<?php

declare(strict_types=1);

/**
 * Feature-lane manifest checker (enforcement Package 2, deliverable 2(b)).
 *
 * THE DEFECT THIS CLOSES
 * ----------------------
 * `tests/Feature` reaches CI three ways, and all three are silent when they miss:
 *
 *   1. Whole-directory runs — `tests/Feature/Security` (the dedicated `security-regression` job, ungated),
 *      `tests/Feature/Treasury` + `tests/Feature/Accounting` (treasury-spine-pgsql).
 *   2. Two hand-maintained PHPUnit `--filter` allowlists in `.github/workflows/ci.yml`.
 *   3. …nothing else. `backend-test` runs `--testsuite=Unit`, never `--testsuite=Feature`.
 *
 * Census at the enforcement-p2 base: 1329 Feature classes in 74 top-level groups;
 * 326 distinct classes reachable by ANY CI job on ANY event; **990 reachable by none**.
 * A new Feature class in an unlisted directory runs nowhere, forever, and nothing says so.
 *
 * The `--filter` mechanism fails in the other direction too: PHPUnit filters are
 * UNANCHORED regexes over `Namespace\Class::method`, so `AnalyticsTest` also selects
 * `ExpenseAnalyticsTest` (proven with `--list-tests`). Any future class whose name
 * contains an allowlisted substring joins a lane silently.
 *
 * WHAT THIS ENFORCES
 * ------------------
 *   A. Every top-level group under `tests/Feature` has an explicit manifest disposition —
 *      a real CI lane, or an exclusion/deferral carrying a reason string. An unassigned
 *      group FAILS. This is the property that cannot rot: a new directory has no entry.
 *   B. Every lane the manifest names is REAL — its selector string is present in
 *      `.github/workflows/ci.yml`. A lane cannot be a fiction.
 *   C. Every entry in every surviving `--filter` allowlist matches EXACTLY ONE test class.
 *      Zero = a dead entry; two or more = ambiguous selection.
 *   D. Every surviving `--filter` is ANCHORED (the `/\\(A|B)::/` form), so a class name
 *      can only match at a namespace boundary.
 *
 * Runs with no database and no application boot: a discrete step in the
 * `backend-architecture` job, which has no `if:` guard and is in `all-checks-pass`.
 *
 * Usage: php tools/feature-lane-manifest-check.php
 */

/**
 * The five ways a job/step stops gating on PR->dev, in one place so the checker can
 * apply them to ITSELF as well as to the lanes it certifies.
 *
 * @return list<string> human-readable reasons; empty means "genuinely gates on PR->dev"
 */
function gatingDefects(array $wf, ?string $jobId, ?string $stepRun): array
{
    $reasons = [];
    if ($jobId === null) {
        return ['could not be resolved to a job in the workflow'];
    }

    // 1. job-level `if:`
    $jobIf = (string) ($wf['ifs'][$jobId] ?? '');
    if ($jobIf !== '' && ! str_contains($jobIf, "base_ref == 'dev'")) {
        $reasons[] = 'job `if: ' . $jobIf . '` excludes PR->dev';
    }

    // 2. step-level `if:` (always-true forms excepted — they skip nothing)
    $alwaysTrue = ['always()', 'success()', '!cancelled()', '! cancelled()'];
    $stepIf = trim((string) ($wf['stepIf'][$stepRun] ?? ''));
    if ($stepRun !== null && $stepIf !== '' && ! in_array($stepIf, $alwaysTrue, true)) {
        $reasons[] = 'the step carries `if: ' . $stepIf . '`, which cannot be proven true on PR->dev';
    }

    // 3 + 4. continue-on-error on the job or the step
    if (($wf['jobSoft'][$jobId] ?? false) === true) {
        $reasons[] = 'the job sets `continue-on-error`, so failures cannot block the merge';
    }
    if ($stepRun !== null && ($wf['stepSoft'][$stepRun] ?? false) === true) {
        $reasons[] = 'the step sets `continue-on-error`, so failures cannot block the merge';
    }

    // 5. transitive `needs` on a job that is itself gated off PR->dev
    $queue = $wf['needs'][$jobId] ?? [];
    $seen = [];
    while ($queue !== []) {
        $needed = (string) array_shift($queue);
        if (isset($seen[$needed])) {
            continue;
        }
        $seen[$needed] = true;
        $neededIf = (string) ($wf['ifs'][$needed] ?? '');
        if ($neededIf !== '' && ! str_contains($neededIf, "base_ref == 'dev'")) {
            $reasons[] = 'the job depends (transitively) on `' . $needed . '`, itself gated off PR->dev';
            break;
        }
        foreach (($wf['needs'][$needed] ?? []) as $next) {
            $queue[] = $next;
        }
    }

    return $reasons;
}

$apiRoot = dirname(__DIR__);
$repoRoot = dirname($apiRoot, 2);
$manifestPath = $apiRoot . '/tests/feature-lane-manifest.json';
$workflowPath = $repoRoot . '/.github/workflows/ci.yml';
$featureRoot = $apiRoot . '/tests/Feature';

/** @return list<string> relative paths of every `*Test.php` under $root */
function enumerateTestClasses(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }
        $out[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
    }
    sort($out);

    return $out;
}

function groupOf(string $relative): string
{
    return str_contains($relative, '/') ? explode('/', $relative)[0] : '(root files)';
}

foreach ([$manifestPath => 'manifest', $workflowPath => 'workflow'] as $path => $label) {
    if (! is_file($path)) {
        fwrite(STDERR, "feature-lane {$label} not found: {$path}\n");
        exit(1);
    }
}

require_once $apiRoot . '/vendor/autoload.php';

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$workflowText = (string) file_get_contents($workflowPath);
try {
    $workflowYaml = \Symfony\Component\Yaml\Yaml::parse($workflowText);
} catch (\Throwable $e) {
    // FAIL CLOSED, and with a clean exit code: an unparseable workflow means the
    // lane and --filter checks cannot run at all, which must never look like a pass.
    fwrite(STDERR, "tests/Feature lane manifest — FAILED\n");
    fwrite(STDERR, '  ✗ .github/workflows/ci.yml does not parse as YAML: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Every LIVE `run:` script in the workflow, plus each job's `if:` expression.
 *
 * Parsed from YAML, never grepped from the file text: a commented-out or deleted
 * step still matches a raw `str_contains`, so a lane could be "verified" against
 * a step that no longer executes.
 *
 * @return array{runs: list<string>, ifs: array<string,string>, stepJob: array<string,string>}
 */
function collectWorkflowRuns(array $workflowYaml): array
{
    $runs = [];
    $ifs = [];
    $needs = [];
    $jobSoft = [];
    $stepJob = [];
    $stepIf = [];
    $stepSoft = [];
    foreach (($workflowYaml['jobs'] ?? []) as $jobId => $job) {
        $ifs[$jobId] = (string) ($job['if'] ?? '');
        $jobSoft[$jobId] = ($job['continue-on-error'] ?? false) === true;
        $jobNeeds = $job['needs'] ?? [];
        $needs[$jobId] = is_array($jobNeeds) ? $jobNeeds : [(string) $jobNeeds];
        foreach (($job['steps'] ?? []) as $step) {
            if (! isset($step['run'])) {
                continue;
            }
            $run = (string) $step['run'];
            $runs[] = $run;
            $stepJob[$run] = (string) $jobId;
            // A STEP-level `if:` gates just as effectively as a job-level one —
            // GitHub skips the step. Deriving coverage from the job guard alone
            // let two words on the step silently remove a lane from PR->dev.
            $stepIf[$run] = (string) ($step['if'] ?? '');
            // `continue-on-error: true` is a THIRD way to stop a lane gating:
            // the step still executes and still shows green, but its failure can
            // no longer block the merge.
            $stepSoft[$run] = ($step['continue-on-error'] ?? false) === true;
        }
    }

    return [
        'runs' => $runs,
        'ifs' => $ifs,
        'needs' => $needs,
        'jobSoft' => $jobSoft,
        'stepJob' => $stepJob,
        'stepIf' => $stepIf,
        'stepSoft' => $stepSoft,
    ];
}

$wf = collectWorkflowRuns($workflowYaml);

$lanes = $manifest['lanes'] ?? [];
$groups = $manifest['groups'] ?? [];

$classes = enumerateTestClasses($featureRoot);

// The --filter allowlists are handed to `php artisan test -c phpunit-pgsql.xml`,
// which spans EVERY testsuite — Unit, Feature, Integration, Architecture. Checking
// their entries against tests/Feature alone would report perfectly good Unit
// entries (e.g. VoucherLedgerTest, which lives at tests/Unit/Voucher/Domain/) as
// dead. Group DISPOSITIONS stay scoped to tests/Feature; filter RESOLUTION uses
// the whole tree.
$allTestClasses = [];
foreach (['Unit', 'Feature', 'Integration', 'Architecture', 'PHPStan', 'E2E'] as $suite) {
    foreach (enumerateTestClasses($apiRoot . '/tests/' . $suite) as $relative) {
        $allTestClasses[] = $suite . '/' . $relative;
    }
}
$byGroup = [];
foreach ($classes as $relative) {
    $byGroup[groupOf($relative)][] = $relative;
}
ksort($byGroup);

$errors = [];
$notes = [];
/** @var list<string> jobs resolved from the workflow for each declared lane */
$resolvedLaneJobs = [];
$deferredGroups = 0;
$deferredClasses = 0;
$excludedGroups = 0;
$excludedClasses = 0;

// ---- A. every group carries an explicit disposition ------------------------
foreach ($byGroup as $group => $members) {
    if (! array_key_exists($group, $groups)) {
        $errors[] = sprintf(
            'UNASSIGNED GROUP "%s" (%d class(es), e.g. %s). Every tests/Feature group must name a CI '
            . 'lane, or be excluded/deferred with a reason, in tests/feature-lane-manifest.json. '
            . 'Silence is exactly the defect this manifest exists to make impossible.',
            $group,
            count($members),
            $members[0],
        );

        continue;
    }

    $entry = $groups[$group];
    $hasLane = isset($entry['lane']);
    $isExcluded = ($entry['excluded'] ?? false) === true;
    $isDeferred = ($entry['deferred'] ?? false) === true;

    if (((int) $hasLane + (int) $isExcluded + (int) $isDeferred) !== 1) {
        $errors[] = sprintf(
            'GROUP "%s" must have EXACTLY ONE disposition (`lane`, `excluded: true`, or `deferred: true`).',
            $group,
        );

        continue;
    }

    if ($hasLane && ! isset($lanes[$entry['lane']])) {
        $errors[] = sprintf('GROUP "%s" names lane "%s", which is not declared in `lanes`.', $group, $entry['lane']);
    } elseif ($hasLane) {
        // THE LANE MUST ACTUALLY RUN THIS GROUP. Validating only that the lane
        // exists let every one of the 71 deferred groups be rewritten to a real
        // lane by search-and-replace: the checker said "every group has a
        // disposition; every declared lane is present in ci.yml" and the entire
        // COVERAGE DEBT block vanished. The lane was real — it just did not run
        // the group. Same failure class as a fictional lane, one level down.
        $laneSelector = (string) ($lanes[$entry['lane']]['selector'] ?? '');
        $coversGroup = $laneSelector !== ''
            && preg_match(
                '#(^|[\s/])tests/Feature/' . preg_quote($group, '#') . '/?$#',
                trim($laneSelector),
            ) === 1;
        if (! $coversGroup) {
            $errors[] = sprintf(
                'GROUP "%s" claims lane "%s", but that lane\'s selector (%s) does not run '
                . 'tests/Feature/%s. A lane disposition must name a WHOLE-DIRECTORY selector for this '
                . 'group; anything else must carry a `deferred`/`excluded` reason and a ceiling instead.',
                $group,
                $entry['lane'],
                var_export($laneSelector, true),
                $group,
            );
        }
    }
    if (($isExcluded || $isDeferred) && empty($entry['reason'])) {
        $errors[] = sprintf('GROUP "%s" is %s with no `reason` string.', $group, $isExcluded ? 'excluded' : 'deferred');
    }
    if ($isDeferred) {
        $deferredGroups++;
        $deferredClasses += count($members);
    }
    // `excluded` is counted too, separately labelled. Reporting only `deferred`
    // let all 71 groups be relabelled `excluded` — same two required fields — and
    // the whole COVERAGE DEBT block vanished while 71 reason strings still said
    // "no CI lane runs this directory". Relabelling must not erase the number.
    if ($isExcluded) {
        $excludedGroups++;
        $excludedClasses += count($members);
    }

    // NON-GROWTH CEILING. Without this, planting a class in an EXISTING uncovered
    // group (e.g. tests/Feature/Admin) is silent — the debt just ticks 1114 -> 1115
    // in a stdout line nobody reads. That is the same silence one level down from
    // the one this manifest exists to end, so `classes` is a contract, not a
    // comment: a laneless group may SHRINK freely and may never grow.
    if ($isDeferred || $isExcluded) {
        if (! isset($entry['classes']) || ! is_int($entry['classes'])) {
            $errors[] = sprintf(
                'GROUP "%s" is %s and must carry an integer `classes` ceiling.',
                $group,
                $isExcluded ? 'excluded' : 'deferred',
            );
        } elseif (count($members) > $entry['classes']) {
            $errors[] = sprintf(
                'COVERAGE DEBT GREW: group "%s" now holds %d class(es), ceiling is %d. A group that no CI '
                . 'lane runs may shrink, never grow — put the new class in a lane, or get the lane funded '
                . '(brief §6 F-2). Lowering the ceiling to match a real deletion is fine.',
                $group,
                count($members),
                $entry['classes'],
            );
        }
    }
}

// A manifest entry for a group that no longer exists is stale bookkeeping, not a
// coverage hole — reported so the file cannot accumulate fiction unnoticed.
foreach (array_keys($groups) as $group) {
    if (! array_key_exists($group, $byGroup)) {
        $notes[] = sprintf('stale manifest entry: group "%s" no longer exists under tests/Feature.', $group);
    }
}

// ---- B. every declared lane is real ----------------------------------------
foreach ($lanes as $laneId => $lane) {
    $selector = $lane['selector'] ?? null;
    if (! is_string($selector) || $selector === '') {
        $errors[] = sprintf('LANE "%s" declares no `selector` to verify against ci.yml.', $laneId);

        continue;
    }
    // Resolve to the step whose `run` STARTS WITH the selector. A `str_contains`
    // match let an earlier step in the same job that merely MENTIONS the selector
    // (an echo, a comment) shadow the real one — the gate checks then read the
    // decoy's empty `if:`.
    $candidates = [];
    foreach ($wf['runs'] as $run) {
        if (str_starts_with(trim($run), $selector)) {
            $candidates[] = $run;
        }
    }
    if (count($candidates) > 1) {
        $errors[] = sprintf(
            'LANE "%s" selector %s resolves to %d steps; the lane must be unambiguous.',
            $laneId,
            var_export($selector, true),
            count($candidates),
        );

        continue;
    }
    $laneRun = $candidates[0] ?? null;
    $owningJob = $laneRun === null ? null : ($wf['stepJob'][$laneRun] ?? null);
    if ($owningJob !== null) {
        $resolvedLaneJobs[] = $owningJob;
    }
    if ($owningJob === null) {
        $errors[] = sprintf(
            'LANE "%s" is a FICTION: its selector %s does not appear in any LIVE `run:` step of '
            . '.github/workflows/ci.yml. (Resolved against parsed YAML, not file text — a commented-out '
            . 'or deleted step must not keep certifying coverage.)',
            $laneId,
            var_export($selector, true),
        );

        continue;
    }

    // R-3: a whole-directory lane must actually run the WHOLE directory. Appending
    // `--filter=OneTest` to the step narrows it to one class while the manifest
    // still certifies the directory — N-3's failure ("the lane was real, it just
    // did not run the group") one level down, worth up to 215 classes.
    // ALLOWLIST, not a denylist. Naming four narrowing flags left every other way
    // of cutting the lane open — appending a single test PATH, `--list-tests`
    // (exits 0 having run nothing), `|| true`, `; exit 0`. The rule is therefore
    // inverted: a whole-directory lane's run line must be EXACTLY the selector
    // plus tokens from a small neutral set, and nothing else.
    $tail = trim(substr(trim((string) $laneRun), strlen($selector)));
    if ($tail !== '') {
        // `-c` / `--configuration` is deliberately NOT here. It is the one flag that
        // redefines the entire invocation — bootstrap, env, group filters, testsuite
        // definitions — so a lane carrying it can run ZERO tests and exit 0 while the
        // manifest still certifies the directory (proven end-to-end: a config whose
        // only content is a nonexistent `<group>` include yields "No tests executed!",
        // EXIT=0, because phpunit.xml sets no failOnEmptyTestSuite). A lane that
        // genuinely needs a config must be an explicit, reviewed exception.
        $neutralValueFlags = ['--log-junit', '--cache-result-file'];
        $neutralBareFlags = [
            '--colors', '--colors=always', '--colors=never', '--colors=auto',
            '--no-progress', '--no-coverage', '--no-output', '--testdox',
            '--do-not-cache-result', '--fail-on-warning', '--fail-on-risky',
        ];
        $tokens = preg_split('/\s+/', $tail) ?: [];
        $expectValue = false;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($expectValue) {
                $expectValue = false;

                continue;
            }
            if (in_array($token, $neutralValueFlags, true)) {
                $expectValue = true;

                continue;
            }
            if (in_array($token, $neutralBareFlags, true)) {
                continue;
            }
            $errors[] = sprintf(
                'LANE "%s" is a whole-directory lane, but its run line carries %s, which this checker '
                . 'cannot prove leaves the whole directory gating. Run line: %s. A lane must be the '
                . 'selector plus neutral flags only — a narrower PATH, `--list-tests`, `--filter`, '
                . '`|| true` or `; exit 0` all leave the manifest certifying coverage that does not happen.',
                $laneId,
                var_export($token, true),
                var_export(trim((string) $laneRun), true),
            );
            break;
        }
    }

    // The manifest also claims which EVENTS the lane runs on. Verify that claim
    // against the owning job's `if:` rather than trusting the prose: narrowing a
    // job to `main` would otherwise leave the manifest asserting PR->dev coverage.
    $declaredJob = $lane['job'] ?? null;
    if (is_string($declaredJob) && $declaredJob !== $owningJob) {
        $errors[] = sprintf(
            'LANE "%s" claims job "%s" but its selector lives in job "%s".',
            $laneId,
            $declaredJob,
            $owningJob,
        );
    }
    // STRUCTURED, not prose: an `events` sentence containing "skipped on PR->dev"
    // reads as a PR->dev claim to any substring test. The boolean is the contract;
    // `events_note` is documentation the checker never interprets.
    if (! array_key_exists('runs_on_pr_dev', $lane)) {
        $errors[] = sprintf('LANE "%s" has no boolean `runs_on_pr_dev` to verify against the job `if:`.', $laneId);

        continue;
    }
    $claimsPrDev = $lane['runs_on_pr_dev'] === true;
    $jobIf = $wf['ifs'][$owningJob] ?? '';
    $runsOnPrDev = $jobIf === '' || str_contains($jobIf, "base_ref == 'dev'");

    // A step-level `if:` skips the step on PR->dev exactly as a job guard would.
    // Conservative by design: ANY `if:` on a lane's own step means we do not
    // certify PR->dev coverage.
    // `always()` / `success()` / `!cancelled()` skip nothing, so treating ANY step
    // `if:` as gating produced a hard failure — on an ungated job, i.e. blocking
    // every PR — carrying the false claim "which skips it".
    $alwaysTrueIf = ['always()', 'success()', '!cancelled()', '! cancelled()'];
    $selectorStepIf = trim((string) ($wf['stepIf'][$laneRun] ?? ''));
    if ($selectorStepIf !== '' && ! in_array($selectorStepIf, $alwaysTrueIf, true)) {
        $runsOnPrDev = false;
    } else {
        $selectorStepIf = '';
    }

    // R-1: `continue-on-error` on the step or the job means failures cannot block
    // the merge — the lane executes but no longer GATES.
    $soft = ($wf['stepSoft'][$laneRun] ?? false) || ($wf['jobSoft'][$owningJob] ?? false);
    // …and the shell-level forms, which are what people actually type: `|| true`,
    // `; exit 0`, `set +e`. The step still runs and still shows green, but its
    // failure can no longer block the merge — identical consequence to
    // `continue-on-error`, through a door YAML does not see.
    $laneScript = (string) $laneRun;
    foreach (['||', ';', '|', 'set +e', '&&'] as $shellSoft) {
        if (str_contains($laneScript, $shellSoft)) {
            $soft = true;
            $errors[] = sprintf(
                'LANE "%s" run line contains %s, so this checker cannot prove a failure of the suite '
                . 'fails the step. A lane must be a single unconditional command. Run line: %s',
                $laneId,
                var_export($shellSoft, true),
                var_export(trim($laneScript), true),
            );
            break;
        }
    }
    if ($soft) {
        $runsOnPrDev = false;
    }

    // …and GitHub skips a job whose dependency was skipped, so a `needs:` on a
    // gated job removes the lane from PR->dev through a second door.
    // R-5: GitHub skips TRANSITIVELY, so a two-job chain hides the same hole.
    $skippedNeed = null;
    $queue = $wf['needs'][$owningJob] ?? [];
    $seen = [];
    while ($queue !== []) {
        $needed = (string) array_shift($queue);
        if (isset($seen[$needed])) {
            continue;
        }
        $seen[$needed] = true;
        $neededIf = $wf['ifs'][$needed] ?? '';
        if ($neededIf !== '' && ! str_contains($neededIf, "base_ref == 'dev'")) {
            $skippedNeed = $needed;
            $runsOnPrDev = false;
            break;
        }
        foreach (($wf['needs'][$needed] ?? []) as $next) {
            $queue[] = $next;
        }
    }
    if ($claimsPrDev !== $runsOnPrDev) {
        $why = $jobIf === '' ? 'no job if: guard (always runs)' : 'job if: ' . $jobIf;
        if ($selectorStepIf !== '') {
            $why .= '; the lane STEP carries `if: ' . $selectorStepIf
                . '`, which this checker cannot prove is true on PR->dev';
        }
        if ($soft) {
            $why .= '; `continue-on-error` is set, so failures cannot block the merge';
        }
        if ($skippedNeed !== null) {
            $why .= '; the job depends (transitively) on `' . $skippedNeed . '`, itself gated off PR->dev';
        }
        $errors[] = sprintf(
            'LANE "%s" claims runs_on_pr_dev=%s but the workflow says %s. (%s)',
            $laneId,
            $claimsPrDev ? 'true' : 'false',
            $runsOnPrDev ? 'true' : 'false',
            $why,
        );
    }
}

// ---- B1. THE TRIGGER SET ----------------------------------------------------
// The root of the event graph, and the one input every `runs_on_pr_dev: true`
// claim rests on. Everything else here verifies job/step guards; none of it means
// anything if the workflow does not start on PR->dev at all. One token
// (`branches: [main, dev]` -> `[main]`) removes the entire workflow — including
// the parent-ruled security-regression job — while every other check still passes.
$anyLaneClaimsPrDev = false;
foreach ($lanes as $lane) {
    if (($lane['runs_on_pr_dev'] ?? false) === true) {
        $anyLaneClaimsPrDev = true;
        break;
    }
}
if ($anyLaneClaimsPrDev) {
    // Symfony's parser yields the STRING key "on" here, not the YAML-1.1 boolean.
    $prBranches = $workflowYaml['on']['pull_request']['branches'] ?? null;
    if (! is_array($prBranches) || ! in_array('dev', $prBranches, true)) {
        $errors[] = sprintf(
            'TRIGGER SET: a lane declares runs_on_pr_dev=true, but the workflow does not start on '
            . 'PR->dev. `on.pull_request.branches` = %s. No job guard can rescue a workflow that never '
            . 'runs.',
            var_export($prBranches, true),
        );
    }
}

// ---- B2. aggregate membership (brief H-9), for EVERY lane's job -------------
// Asserting this from one PHPUnit case, for one lane, left `backend-architecture`
// (the job carrying this very checker) and `treasury-spine-pgsql` unpinned.
$aggregateNeeds = $workflowYaml['jobs']['all-checks-pass']['needs'] ?? [];
$aggregateNeeds = is_array($aggregateNeeds) ? $aggregateNeeds : [(string) $aggregateNeeds];
// Keyed off the job RESOLVED FROM THE WORKFLOW (recorded during lane validation),
// never the optional manifest `job` field: deleting that one key would otherwise
// erase both this assertion and the job-identity check in the same edit.
$mustBeInAggregate = array_merge(['backend-architecture'], $resolvedLaneJobs);
foreach (array_unique($mustBeInAggregate) as $jobId) {
    if (! in_array($jobId, $aggregateNeeds, true)) {
        $errors[] = sprintf(
            'JOB "%s" is missing from the `all-checks-pass` `needs` list. Brief H-9 makes aggregate '
            . 'membership a package-wide obligation: a gate outside the aggregate does not gate.',
            $jobId,
        );
    }
}

// ---- B3. SELF-APPLICATION --------------------------------------------------
// Everything above protects the lanes. This protects the checker itself. Without
// it the package's entire backend guard surface is removable by exactly the
// remediation an unrelated lane reaches for when `backend-architecture` goes red
// — gate it, soften it, or make it depend on a gated job — while every check here
// still reports OK. 43 s of security tests were protected against five doors; the
// guard that protects them was protected against none.
$selfJob = 'backend-architecture';
$selfSteps = [
    'php tools/feature-lane-manifest-check.php',
    './vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php',
];
foreach ($selfSteps as $selfStep) {
    $selfRun = null;
    foreach ($wf['runs'] as $run) {
        if (str_starts_with(trim($run), $selfStep)) {
            $selfRun = $run;
            break;
        }
    }
    if ($selfRun === null) {
        $errors[] = sprintf(
            'SELF-CHECK: the step `%s` is not present in any live `run:` block of ci.yml. The checker '
            . 'and its liveness suite must both actually run.',
            $selfStep,
        );

        continue;
    }
    $owner = $wf['stepJob'][$selfRun] ?? null;
    if ($owner !== $selfJob) {
        $errors[] = sprintf('SELF-CHECK: the step `%s` has moved out of the `%s` job.', $selfStep, $selfJob);

        continue;
    }
    foreach (gatingDefects($wf, $owner, $selfRun) as $reason) {
        $errors[] = sprintf('SELF-CHECK: `%s` no longer gates PR->dev — %s.', $selfStep, $reason);
    }
}

// ---- C + D. surviving --filter allowlists ----------------------------------
$basenames = [];
foreach ($allTestClasses as $relative) {
    $basenames[] = substr(basename($relative), 0, -4); // strip ".php"
}
$basenameCounts = array_count_values($basenames);

// Every `--filter` argument form PHPUnit and `php artisan test` accept:
//   --filter="A|B"   --filter='A|B'   --filter=A|B   --filter "A|B"   --filter A|B
// Matching only the quoted forms let an unquoted or space-form allowlist bring the
// substring-shadowing mechanism back with the guard reporting OK. Scanned over the
// LIVE `run:` scripts, and any `--filter` the parser cannot resolve is a hard
// failure rather than a silent skip.
$filterValues = [];
foreach ($wf['runs'] as $run) {
    // SCOPE THE SCAN. `--filter` is not a PHPUnit-only token: this is a pnpm
    // workspace, where `pnpm --filter @autoerp/web …` is the prescribed form
    // (AGENTS.md). Scanning every `--filter` in every script turned that normal
    // command into a hard failure of `backend-architecture` — a job with no `if:`
    // guard, so it blocked every PR — with an error telling the author to rewrite
    // their pnpm selector as a PHPUnit regex. Only test-runner scripts are scanned;
    // inside them the check still fails closed.
    // Scan EVERY script and skip only the specific package-manager INVOCATION that
    // owns its own `--filter`. Scoping by "does the script mention phpunit" instead
    // re-opened the hole for `composer test -- --filter=…` (CLAUDE.md's own
    // documented command), and skipping the whole LINE swallowed a genuine PHPUnit
    // filter that merely shared a line with a pnpm call. The unit is the command
    // segment.
    // Split on `&&`, `||`, `;` and newlines ONLY — never on a bare `|`. A single
    // pipe is also the alternation separator INSIDE an anchored filter value
    // (`/\\(A|B|C)::/`), so splitting on it shreds the very value being checked.
    foreach (preg_split('/(?:&&|\|\||;|\n)/', $run) as $segment) {
        // Skip env/wrapper prefixes before deciding which binary owns the flags:
        // `env CI=1 pnpm …`, `npx pnpm …`, `corepack pnpm …`, `sudo -E pnpm …` all
        // otherwise fell through and produced a false positive on an ungated job.
        $probe = ltrim($segment);
        while (
            preg_match('/^(?:env|sudo|nice|time|command|exec|npx|corepack|xargs)\b\s*/', $probe) === 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=\S*\s+/', $probe) === 1
            || preg_match('/^-{1,2}\S+\s+/', $probe) === 1
        ) {
            $probe = ltrim(preg_replace('/^(?:(?:env|sudo|nice|time|command|exec|npx|corepack|xargs)\b|[A-Za-z_][A-Za-z0-9_]*=\S*|-{1,2}\S+)\s*/', '', $probe, 1) ?? '');
        }
        $isPackageManager = preg_match('/^(?:\S*\/)?(pnpm|npm|yarn|turbo)\b/', $probe) === 1;

        // A package manager's OWN `--filter` comes before the script name; anything
        // after the script name is FORWARDED to whatever that script runs. Skipping
        // the whole segment therefore hid a real PHPUnit filter behind a wrapper
        // script (`pnpm test:backend --filter=AnalyticsTest`), reintroducing
        // substring shadowing invisibly. Only the manager-owned prefix is skipped.
        $scanFrom = 0;
        if ($isPackageManager) {
            $tokens = preg_split('/\s+/', trim($probe)) ?: [];
            $consumeValue = false;
            $scriptToken = null;
            foreach (array_slice($tokens, 1) as $token) {
                if ($consumeValue) {
                    $consumeValue = false;

                    continue;
                }
                if (str_starts_with($token, '-')) {
                    // `--filter <value>` (space form) consumes the next token.
                    $consumeValue = ! str_contains($token, '=');

                    continue;
                }
                $scriptToken = $token;
                break;
            }
            if ($scriptToken === null) {
                // Nothing forwarded — every flag belongs to the package manager.
                continue;
            }
            $scriptPos = strpos($segment, $scriptToken);
            $scanFrom = $scriptPos === false ? 0 : $scriptPos + strlen($scriptToken);
        }
        $offset = $scanFrom;
        while (($pos = strpos($segment, '--filter', $offset)) !== false) {
            $offset = $pos + 8;
            $rest = substr($segment, $offset);
            if (preg_match('/^(?:=|[ \t]+)(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s]+))/s', $rest, $m) !== 1) {
                $errors[] = sprintf(
                    'UNPARSEABLE --filter in a `run:` step (context: %s). Fail closed: the anchoring and '
                    . 'uniqueness lint cannot certify an allowlist it cannot read.',
                    trim(substr($segment, max(0, $pos - 20), 60)),
                );

                continue;
            }
            if (($m[1] ?? '') !== '') {
                $filterValues[] = $m[1];
            } elseif (($m[2] ?? '') !== '') {
                $filterValues[] = $m[2];
            } else {
                $filterValues[] = $m[3] ?? '';
            }
        }
    }
}

foreach ($filterValues as $raw) {

    // The anchored form is  /\\( A | B )::/  — a literal backslash pair before the
    // alternation forces the match to start at a namespace separator. String checks,
    // not a regex over a regex: escaping a pattern that matches patterns is how these
    // guards become unreadable and then wrong.
    $anchored = str_starts_with($raw, '/\\\\(') && str_ends_with($raw, ')::/');

    if (! $anchored) {
        $errors[] = sprintf(
            'UNANCHORED --filter in ci.yml (starts: %s…). PHPUnit filters are unanchored regexes over '
            . '`Namespace\\Class::method`, so a bare alternation lets any class whose name CONTAINS an '
            . 'entry join the lane silently (AnalyticsTest also selects ExpenseAnalyticsTest). '
            . 'Use the /\\\\(A|B|C)::/ form.',
            substr($raw, 0, 48),
        );
    }

    $inner = $anchored ? substr($raw, 4, -4) : $raw;
    foreach (explode('|', $inner) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        $exact = $basenameCounts[$entry] ?? 0;
        if ($exact === 1) {
            continue;
        }

        if ($exact === 0) {
            $shadow = array_values(array_unique(array_filter(
                $basenames,
                static fn (string $b): bool => str_contains($b, $entry),
            )));
            $errors[] = $shadow === []
                ? sprintf('DEAD --filter entry "%s": matches no Feature test class at all.', $entry)
                : sprintf(
                    'DEAD --filter entry "%s": no class has that exact name (substring-only matches: %s).',
                    $entry,
                    implode(', ', $shadow),
                );

            continue;
        }

        $paths = array_values(array_filter(
            $allTestClasses,
            static fn (string $c): bool => substr(basename($c), 0, -4) === $entry,
        ));
        $errors[] = sprintf(
            'AMBIGUOUS --filter entry "%s": %d classes share that basename (%s). The lane composition '
            . 'is undefined — disambiguate or split the lane.',
            $entry,
            $exact,
            implode(', ', $paths),
        );
    }
}

// GLOBAL CEILING. The per-group ceilings enforce "no new SILENT hole"; they do not
// enforce "no coverage loss" — a whole lane can be retired (group relabelled
// `deferred` with a fresh ceiling, job deleted) and the total debt GROWS with
// EXIT=0. This pins the total as well, so retiring a lane is a deliberate, visible
// edit to this number rather than a side effect.
$declaredDebtCeiling = $manifest['debt_ceiling'] ?? null;
if (! is_int($declaredDebtCeiling)) {
    $errors[] = 'MANIFEST is missing an integer top-level `debt_ceiling` (the global laneless-class ceiling).';
} elseif (($deferredClasses + $excludedClasses) > $declaredDebtCeiling) {
    $errors[] = sprintf(
        'TOTAL COVERAGE DEBT GREW: %d class(es) now sit in groups no lane runs, ceiling is %d. '
        . 'Retiring a CI lane must be a deliberate edit to `debt_ceiling`, not a side effect.',
        $deferredClasses + $excludedClasses,
        $declaredDebtCeiling,
    );
}

// ---- report ----------------------------------------------------------------
foreach ($notes as $note) {
    fwrite(STDOUT, "  note: {$note}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "tests/Feature lane manifest — FAILED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  ✗ {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf(
    "tests/Feature lane manifest OK — %d Feature classes in %d groups; every group has a disposition; "
    . "every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched "
    . "against %d test classes across all suites.\n",
    count($classes),
    count($byGroup),
    count($allTestClasses),
));

if ($excludedGroups > 0) {
    fwrite(STDOUT, sprintf(
        "  ⚠ EXCLUDED: %d group(s) / %d class(es) are declared unable to run in CI. Each carries a\n"
        . "    reason; relabelling a `deferred` group as `excluded` does NOT remove it from this report.\n",
        $excludedGroups,
        $excludedClasses,
    ));
}

if ($deferredGroups > 0) {
    // Loud on EVERY run, on purpose. These classes run in no CI lane; the manifest makes
    // that fact explicit and un-growable, but it does not make it acceptable. Flipping
    // them on is an owner CI-budget decision (dispatch brief §6 F-2).
    // Precise wording on purpose: these classes live in groups NO WHOLE-DIRECTORY
    // lane runs. A minority of them are still named individually in an anchored
    // --filter allowlist, so "run in no CI lane on any event" would be a false
    // statement in a guard's own output — the exact sin this package exists to
    // stop. The strictly-unreachable figure is in the decision doc's census.
    fwrite(STDOUT, sprintf(
        "  ⚠ COVERAGE DEBT: %d group(s) / %d class(es) sit in groups that NO CI lane runs as a whole,\n"
        . "    pending the F-2 CI-budget decision. Some are individually named in a --filter allowlist;\n"
        . "    a NEW class in any of these groups is selected by nothing. Ceilings are enforced above.\n"
        . "    See docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md §M2.\n",
        $deferredGroups,
        $deferredClasses,
    ));
}

exit(0);
