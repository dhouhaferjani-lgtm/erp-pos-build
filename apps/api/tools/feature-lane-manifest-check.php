<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

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
 * @param  array{runs: list<string>, ifs: array<string,string>, needs: array<string,list<string>>, jobSoft: array<string,bool>, stepJob: array<string,string>, stepIf: array<string,string>, stepSoft: array<string,bool>, jobSteps: array<string,list<array{label:string,if:string}>>}  $wf
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
        $reasons[] = 'job `if: '.$jobIf.'` excludes PR->dev';
    }

    // 2. step-level `if:` (always-true forms excepted — they skip nothing)
    $alwaysTrue = ['always()', 'success()', '!cancelled()', '! cancelled()'];
    $stepIf = trim((string) ($wf['stepIf'][$stepRun] ?? ''));
    if ($stepRun !== null && $stepIf !== '' && ! in_array($stepIf, $alwaysTrue, true)) {
        $reasons[] = 'the step carries `if: '.$stepIf.'`, which cannot be proven true on PR->dev';
    }

    // 3 + 4. continue-on-error on the job or the step
    if (($wf['jobSoft'][$jobId] ?? false) === true) {
        $reasons[] = 'the job sets `continue-on-error`, so failures cannot block the merge';
    }
    if ($stepRun !== null && ($wf['stepSoft'][$stepRun] ?? false) === true) {
        $reasons[] = 'the step sets `continue-on-error`, so failures cannot block the merge';
    }

    // 5. shell-level soft-fail in the command itself — `|| true`, `; exit 0`,
    // `set +e`. The step still runs and still shows green; it just cannot fail the
    // build. This door was enforced on lanes but not on the checker's own steps,
    // which is exactly the asymmetry N-2 existed to remove.
    if ($stepRun !== null) {
        foreach (['||', ';', '|', 'set +e', '&&'] as $shellSoft) {
            if (str_contains((string) $stepRun, $shellSoft)) {
                $reasons[] = 'the command contains `'.$shellSoft
                    .'`, so a failure of the suite cannot fail the step';
                break;
            }
        }
    }

    // 6. transitive `needs` on a job that is itself gated off PR->dev
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
            $reasons[] = 'the job depends (transitively) on `'.$needed.'`, itself gated off PR->dev';
            break;
        }
        foreach (($wf['needs'][$needed] ?? []) as $next) {
            $queue[] = $next;
        }
    }

    return $reasons;
}

/**
 * Whitespace-normalize a GitHub expression so `if:` strings can be compared for
 * EQUALITY rather than containment (gate-r1 R1-2). Collapses runs of whitespace
 * — YAML folding, indentation, a line break inside `${{ }}` — and trims. Nothing
 * else is normalized: reordering or re-parenthesizing an expression produces a
 * different string on purpose, because this checker does not have a GitHub
 * expression evaluator and must not pretend to.
 */
function normalizeExpression(string $expression): string
{
    return trim((string) preg_replace('/\s+/', ' ', $expression));
}

/**
 * Every CONTEXT REFERENCE in a GitHub expression, in source form.
 *
 * gate-r2 R2-2: the previous rule censused `vars\.NAME` with a regex, and the
 * reviewer transliterated the round-1 bypass into `vars['NEVER_FIRES']` — same
 * second switch, different syntax, checker EXIT=0. A second probe used
 * `needs.<job>.outputs.*`, which is not a `vars` reference at all and is just as
 * effective a permanently-false condition.
 *
 * Censusing one spelling of one context can never be right. The property that
 * matters is: THE ONLY FREE VARIABLES IN A LANE'S GATE ARE THE EVENT CONTEXT AND
 * AT MOST ONE `vars.NAME`. So every context reference is extracted — dot form and
 * index form, for every context GitHub defines — and anything outside that set is
 * rejected by name.
 *
 * @return list<array{context: string, form: string, name: string|null, source: string}>
 */
function contextReferences(string $expression): array
{
    // Every context GitHub exposes in a job-level `if:`, plus the ones it does not
    // (`inputs`, `steps`, `jobs`) so a mistaken one is reported rather than ignored.
    $contexts = 'github|vars|env|secrets|needs|inputs|steps|jobs|job|runner|matrix|strategy';
    $pattern = '/\b('.$contexts.')\s*(?:'
        // .name  — dot access
        .'\.\s*(?<dot>[A-Za-z_][A-Za-z0-9_\-]*)'
        .'|'
        // ['name'] / ["name"] — index access, literal key
        .'\[\s*(?<qi>[\'"])(?<idx>[^\'"]*)\k<qi>\s*\]'
        .'|'
        // [anything else] — a computed key, e.g. vars[format('X_{0}', …)]
        .'\[(?<expr>[^\]]*)\]'
        // gate-r4 R4-1: CASE-INSENSITIVE. GitHub expression contexts are not
        // case-sensitive — `VARS['NEVER_FIRES']` resolves exactly as
        // `vars['NEVER_FIRES']` does — and the reviewer walked the round-2
        // allowlist by simply shouting the context name. The matched context is
        // lowercased below so the rest of this checker compares one spelling;
        // `source` keeps the author's original casing for the error message.
        .')/i';

    if (preg_match_all($pattern, $expression, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $references = [];
    foreach ($matches as $match) {
        if (($match['dot'] ?? '') !== '') {
            $references[] = [
                'context' => strtolower($match[1]),
                'form' => 'dot',
                'name' => $match['dot'],
                'source' => trim($match[0]),
            ];
        } elseif (($match['idx'] ?? '') !== '' || isset($match['idx'])) {
            $references[] = [
                'context' => strtolower($match[1]),
                'form' => 'index',
                'name' => $match['idx'],
                'source' => trim($match[0]),
            ];
        } else {
            $references[] = [
                'context' => strtolower($match[1]),
                'form' => 'computed',
                'name' => null,
                'source' => trim($match[0]),
            ];
        }
    }

    return $references;
}

$apiRoot = dirname(__DIR__);
$repoRoot = dirname($apiRoot, 2);
$manifestPath = $apiRoot.'/tests/feature-lane-manifest.json';
$workflowPath = $repoRoot.'/.github/workflows/ci.yml';
$featureRoot = $apiRoot.'/tests/Feature';

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

require_once $apiRoot.'/vendor/autoload.php';

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$workflowText = (string) file_get_contents($workflowPath);
try {
    $workflowYaml = Yaml::parse($workflowText);
} catch (Throwable $e) {
    // FAIL CLOSED, and with a clean exit code: an unparseable workflow means the
    // lane and --filter checks cannot run at all, which must never look like a pass.
    fwrite(STDERR, "tests/Feature lane manifest — FAILED\n");
    fwrite(STDERR, '  ✗ .github/workflows/ci.yml does not parse as YAML: '.$e->getMessage()."\n");
    exit(1);
}

/**
 * Every LIVE `run:` script in the workflow, plus each job's `if:` expression.
 *
 * Parsed from YAML, never grepped from the file text: a commented-out or deleted
 * step still matches a raw `str_contains`, so a lane could be "verified" against
 * a step that no longer executes.
 *
 * @param  array<string,mixed>  $workflowYaml
 * @return array{runs: list<string>, ifs: array<string,string>, needs: array<string,list<string>>, jobSoft: array<string,bool>, stepJob: array<string,string>, stepIf: array<string,string>, stepSoft: array<string,bool>, jobSteps: array<string,list<array{label:string,if:string}>>}
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
    $jobSteps = [];
    foreach (($workflowYaml['jobs'] ?? []) as $jobId => $job) {
        $jobSteps[$jobId] = [];
        $ifs[$jobId] = (string) ($job['if'] ?? '');
        $jobSoft[$jobId] = ($job['continue-on-error'] ?? false) === true;
        $jobNeeds = $job['needs'] ?? [];
        $needs[$jobId] = array_values(array_map(
            static fn ($n): string => (string) $n,
            is_array($jobNeeds) ? $jobNeeds : [$jobNeeds],
        ));
        foreach (($job['steps'] ?? []) as $index => $step) {
            // EVERY step, `run:` or `uses:`, with its own `if:` — gate-r2 R2-1
            // needs the whole list, not just the scripted ones: a guard on the
            // checkout or setup step removes the lane just as completely.
            $jobSteps[$jobId][] = [
                'label' => (string) ($step['name'] ?? $step['uses'] ?? ('step #'.((int) $index + 1))),
                'if' => trim((string) ($step['if'] ?? '')),
            ];
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
        'jobSteps' => $jobSteps,
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
    foreach (enumerateTestClasses($apiRoot.'/tests/'.$suite) as $relative) {
        $allTestClasses[] = $suite.'/'.$relative;
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
/** @var array<string,true> lane ids whose owning job is guarded by a repository-variable flag */
$gatedLanes = [];
/** @var list<string> jobs owning a flag-gated lane */
$gatedLaneJobs = [];
$deferredGroups = 0;
$deferredClasses = 0;
$excludedGroups = 0;
$excludedClasses = 0;

// ---- A. every group carries an explicit disposition ------------------------
foreach ($byGroup as $group => $members) {
    if (! array_key_exists($group, $groups)) {
        $errors[] = sprintf(
            'UNASSIGNED GROUP "%s" (%d class(es), e.g. %s). Every tests/Feature group must name a CI '
            .'lane, or be excluded/deferred with a reason, in tests/feature-lane-manifest.json. '
            .'Silence is exactly the defect this manifest exists to make impossible.',
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
                '#(^|[\s/])tests/Feature/'.preg_quote($group, '#').'/?$#',
                trim($laneSelector),
            ) === 1;
        if (! $coversGroup) {
            $errors[] = sprintf(
                'GROUP "%s" claims lane "%s", but that lane\'s selector (%s) does not run '
                .'tests/Feature/%s. A lane disposition must name a WHOLE-DIRECTORY selector for this '
                .'group; anything else must carry a `deferred`/`excluded` reason and a ceiling instead.',
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
                .'lane runs may shrink, never grow — put the new class in a lane, or get the lane funded '
                .'(brief §6 F-2). Lowering the ceiling to match a real deletion is fine.',
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
            .'.github/workflows/ci.yml. (Resolved against parsed YAML, not file text — a commented-out '
            .'or deleted step must not keep certifying coverage.)',
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
                .'cannot prove leaves the whole directory gating. Run line: %s. A lane must be the '
                .'selector plus neutral flags only — a narrower PATH, `--list-tests`, `--filter`, '
                .'`|| true` or `; exit 0` all leave the manifest certifying coverage that does not happen.',
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
    // ONE implementation of the six doors, shared with the B3 self-application
    // block below — the two used to be separate copies and had already diverged
    // (the lane path knew the shell-soft door, the self-check did not).
    $laneDefects = gatingDefects($wf, $owningJob, $laneRun);
    if ($laneDefects !== []) {
        $runsOnPrDev = false;
    }

    if ($claimsPrDev !== $runsOnPrDev) {
        $why = $laneDefects === []
            ? ($jobIf === '' ? 'no job if: guard (always runs)' : 'job if: '.$jobIf)
            : implode('; ', $laneDefects);
        $errors[] = sprintf(
            'LANE "%s" claims runs_on_pr_dev=%s but the workflow says %s. (%s)',
            $laneId,
            $claimsPrDev ? 'true' : 'false',
            $runsOnPrDev ? 'true' : 'false',
            $why,
        );
    }

    // ---- B4. FLAG-GATED LANES (O-29 / F-2 execution ruling, 2026-08-21) ------
    // A SEVENTH way a lane stops gating, and the one this session introduces: the
    // lane is real, it runs the whole directory, its job is in the aggregate — and
    // its job carries `if: ${{ vars.X == 'true' }}` for a repository variable that
    // is OFF, so it executes on NO event at all. Every check above passes and the
    // COVERAGE DEBT counter drops to zero while nothing new runs. That is the
    // search-and-replace erasure the N-3 comment above warned about, one level
    // further down, and it is exactly the shape of the self-hosted-runner rollout.
    //
    // So flag-gating must be DECLARED (`execution_gate`), VERIFIED against the job
    // guard, and COUNTED out loud until the flag is flipped. Neither direction is
    // free: a job guarded by a `vars.` expression with no declaration fails, and a
    // declaration that does not match the job's real guard fails too.
    //
    // gate-r1 R1-2: the verification used to be `str_contains($jobIf, $gate)`, and
    // the reviewer walked straight through it. Containment can only ever prove a
    // fragment is PRESENT; it can say nothing about what was ADDED around it. The
    // proven bypass:
    //
    //     if: ${{ vars.NEVER_FIRES == 'true' && vars.SELF_HOSTED_RUNNER_READY == 'true' && (…) }}
    //
    // still CONTAINS the declared gate, so B4 passed, B4a counted the classes as
    // merely "parked", B5 tolerated the skip in the aggregate — and the lane could
    // never execute no matter what the owner flipped. Two independent rules now:
    //
    //   (1) `execution_gate` carries the WHOLE canonical `if:` expression and must
    //       EQUAL the job's real `if:` after whitespace normalization. Equality
    //       cannot be satisfied by addition. A semantically-equivalent reordering
    //       false-blocks; that is the accepted trade — a one-line manifest edit
    //       makes the intended expression explicit and reviewable in one place.
    //   (2) Independently of the manifest — because rule (1) can be satisfied by
    //       editing BOTH files — a lane job's `if:` may reference EXACTLY ONE
    //       distinct `vars.*`. A second repository variable is a second switch
    //       nobody is tracking, and it is the whole substance of the bypass.
    $gate = $lane['execution_gate'] ?? null;

    // gate-r2 R2-2: an ALLOWLIST over every context reference, not a census of one
    // spelling of one context. `github.*` is the event arm and is allowed; a single
    // `vars.NAME` in dot form is the one permitted switch; EVERYTHING else — a
    // second `vars`, `vars['NAME']`, `vars[format(…)]`, `needs.x.outputs.y`,
    // `env.*`, `secrets.*`, `inputs.*`, `steps.*`, `runner.*`, `matrix.*` — is a
    // free variable nobody is tracking, and each one is a way to hold the lane at
    // false forever while every other check reports it merely awaiting one flip.
    $jobVars = [];
    foreach (contextReferences($jobIf) as $reference) {
        if ($reference['context'] === 'github' && $reference['form'] === 'dot') {
            continue;
        }
        if ($reference['context'] === 'vars' && $reference['form'] === 'dot') {
            // Variable NAMES are case-insensitive too, so `vars.FOO` and `vars.foo`
            // are one switch and must not census as two.
            $jobVars[] = 'vars.'.strtoupper((string) $reference['name']);

            continue;
        }
        $errors[] = sprintf(
            'LANE "%s" lives in job "%s", whose `if:` contains the context reference `%s`. A lane gate may '
            .'reference ONLY the event context (`github.*`) and at most one `vars.NAME` in dot form. Any '
            .'other free variable — an index-form `vars[\'NAME\']`, a computed key, `needs.*.outputs.*`, '
            .'`env.*`, `secrets.*` — is a second switch that can hold the lane at false permanently while '
            .'the manifest still reports it merely waiting on the owner. Full `if:`: %s',
            $laneId,
            $owningJob,
            $reference['source'],
            var_export($jobIf, true),
        );
    }
    $jobVars = array_values(array_unique($jobVars));
    $jobIsVarGuarded = $jobVars !== [];
    if ($jobIsVarGuarded && ($gate === null || $gate === '')) {
        $errors[] = sprintf(
            'LANE "%s" lives in job "%s", whose `if:` is guarded by a repository variable (%s), but the '
            .'lane declares no `execution_gate`. A lane that executes on no event until an owner flips a '
            .'flag must say so in the manifest — otherwise it silently erases the coverage-debt count it '
            .'was created to discharge.',
            $laneId,
            $owningJob,
            var_export($jobIf, true),
        );
    }
    if ($jobIsVarGuarded && count($jobVars) > 1) {
        $errors[] = sprintf(
            'LANE "%s" lives in job "%s", whose `if:` references %d distinct repository variables (%s). A '
            .'flag-gated lane may have EXACTLY ONE switch: a second `vars.*` is a second thing that must be '
            .'true for the lane ever to run, and a never-enabled one parks the lane permanently while every '
            .'other check here still reports it merely "pending the owner\'s flip".',
            $laneId,
            $owningJob,
            count($jobVars),
            implode(', ', $jobVars),
        );
    }
    if ($gate !== null) {
        if (! is_string($gate) || $gate === '') {
            $errors[] = sprintf('LANE "%s" declares a non-string/empty `execution_gate`.', $laneId);
        } elseif (normalizeExpression($gate) !== normalizeExpression($jobIf)) {
            $errors[] = sprintf(
                'LANE "%s" declares execution_gate %s, but job "%s" carries `if:` %s. These must be the SAME '
                .'expression (whitespace-normalized): a containment test cannot see an ADDED condition, and '
                .'an added condition is how a lane is parked forever while the manifest reports it merely '
                .'waiting on one flip.',
                $laneId,
                var_export($gate, true),
                $owningJob,
                var_export($jobIf, true),
            );
        } elseif (count($jobVars) === 1) {
            $gatedLanes[$laneId] = true;
            $gatedLaneJobs[] = $owningJob;
        }
    }
}

// ---- B4b. NO STEP IN A LANE JOB MAY CARRY AN `if:` --------------------------
// gate-r2 R2-1. The round-1 fix hardened the JOB guard and left the STEP guard
// exactly where it was: a step-level `if:` was known to `gatingDefects()`, but its
// only consequence there is to force `runs_on_pr_dev` false — which is INERT for
// all 70 gated lanes, because they already declare false. The reviewer added
// `if: ${{ false }}` to one lane step and the checker exited 0.
//
// The consequence is worse than a skipped lane, because it is a false GREEN: when
// the owner flips the variable the JOB runs, the guarded STEP skips, the job
// reports SUCCESS, and `all-checks-pass` prints `ok` for it. A whole group drops
// out of a lane the manifest still certifies, and every signal says the suite ran.
//
// A conditional cannot be evaluated by this checker, and a lane's value is that it
// runs unconditionally, so the rule is absolute rather than clever: a job that owns
// a lane may not carry `if:` on ANY step — setup steps included, since a guard on
// checkout or composer removes the lane just as completely. Only literally-always-
// true forms are permitted, and they are permitted because they skip nothing.
// If a lane job ever genuinely needs a conditional step, that is a reviewed change
// to this rule, made deliberately — which is the whole point.
$alwaysTrueStepIfs = ['always()', 'success()', '!cancelled()', '! cancelled()', '${{ always() }}', '${{ success() }}'];
foreach (array_values(array_unique($resolvedLaneJobs)) as $laneJob) {
    foreach (($wf['jobSteps'][$laneJob] ?? []) as $step) {
        if ($step['if'] === '' || in_array($step['if'], $alwaysTrueStepIfs, true)) {
            continue;
        }
        $errors[] = sprintf(
            'LANE JOB "%s" has a step (%s) carrying `if: %s`. No step in a lane job may be conditional: a '
            .'guarded step SKIPS while the job still reports SUCCESS and the aggregate prints `ok`, so a '
            .'whole group silently drops out of a lane the manifest still certifies — a false green, which '
            .'is worse than a red. Remove the condition, or split the step into a job that owns no lane.',
            $laneJob,
            $step['label'],
            $step['if'],
        );
    }
}

// ---- B4a. how many classes sit in lanes that do not execute yet -------------
$gatedGroups = 0;
$gatedClasses = 0;
foreach ($byGroup as $group => $members) {
    $entry = $groups[$group] ?? null;
    if (! is_array($entry) || ! isset($entry['lane'])) {
        continue;
    }
    if (($gatedLanes[(string) $entry['lane']] ?? false) === true) {
        $gatedGroups++;
        $gatedClasses += count($members);

        // PER-GROUP CEILING SURVIVES THE MOVE. A `deferred` group carries an
        // enforced non-growth ceiling; a group laned into a lane that does not
        // execute yet is in exactly the same position, so it keeps exactly the
        // same ceiling. Without this, a single global gated total would let a
        // deletion in one parked group silently fund a new silent class in
        // another — strictly weaker than the state this replaced. The ceiling
        // is dropped (and `classes` becomes informational, as for any live
        // lane) at the moment the execution gate is flipped.
        if (! isset($entry['classes']) || ! is_int($entry['classes'])) {
            $errors[] = sprintf(
                'GROUP "%s" is laned into flag-gated lane "%s" and must keep its integer `classes` ceiling '
                .'until the gate is flipped.',
                $group,
                (string) $entry['lane'],
            );
        } elseif (count($members) > $entry['classes']) {
            $errors[] = sprintf(
                'PARKED-LANE COVERAGE GREW: group "%s" now holds %d class(es), ceiling is %d. Its lane "%s" '
                .'is wired but parked behind an unflipped execution gate, so a new class here still runs '
                .'nowhere — the ceiling stays enforced until the gate is flipped.',
                $group,
                count($members),
                $entry['classes'],
                (string) $entry['lane'],
            );
        }
    }
}
if ($gatedLanes !== []) {
    // Ceiling with the same shrink-only semantics as `debt_ceiling`: parking
    // classes behind an unflipped flag is a transitional state, and it must cost
    // a deliberate edit to grow — otherwise "wire a lane, leave the flag off"
    // becomes a quieter dumping ground than `deferred` ever was.
    $gatedCeiling = $manifest['gated_ceiling'] ?? null;
    if (! is_int($gatedCeiling)) {
        $errors[] = 'MANIFEST declares flag-gated lane(s) but no integer top-level `gated_ceiling`.';
    } elseif ($gatedClasses > $gatedCeiling) {
        $errors[] = sprintf(
            'GATED-LANE COVERAGE GREW: %d class(es) now sit in lanes parked behind an unflipped execution '
            .'gate, ceiling is %d. Lower the ceiling when a gate is flipped or a class leaves; raising it '
            .'is a deliberate edit.',
            $gatedClasses,
            $gatedCeiling,
        );
    }
}

// ---- B5. the aggregate's skip-tolerance list == exactly the gated lane jobs --
// `all-checks-pass` must tolerate a SKIPPED dependency for flag-gated jobs only
// (GitHub skips every dependent of a skipped job unless the dependent opts out),
// and must NOT tolerate it for anything else. Left unchecked, the opt-out list is
// a one-word way to make any red job non-blocking: add its name and its failure
// becomes "skipped-and-allowed" to a reader, while this checker still certifies
// aggregate membership.
$allowSkipped = null;
$expectedJobsEnv = null;
foreach (($workflowYaml['jobs']['all-checks-pass']['steps'] ?? []) as $aggregateStep) {
    if (isset($aggregateStep['env']['ALLOW_SKIPPED_JOBS'])) {
        $allowSkipped = (string) $aggregateStep['env']['ALLOW_SKIPPED_JOBS'];
    }
    if (isset($aggregateStep['env']['EXPECTED_JOBS'])) {
        $expectedJobsEnv = (string) $aggregateStep['env']['EXPECTED_JOBS'];
    }
}

// ---- B5a. the aggregate's EXPECTED_JOBS == its own `needs:` list ------------
// gate-r1 R1-1: the aggregate now refuses to pass unless the `needs` context it
// parsed contains exactly the jobs it expects — which closes "fails open on empty
// or malformed JSON", but only if EXPECTED_JOBS itself is honest. Left unchecked
// it is one more hand-maintained list that can silently go stale: drop a job from
// it and from `needs` and the gate stops aggregating that job while both files
// still look tidy. Pinning it here means the list cannot drift from `needs`, and
// `needs` cannot drift from the lane set (B2 above).
// Read independently of B2 below (which runs later in this file and re-reads the
// same key): this block must not depend on statement order to be correct.
$aggregateNeedsRaw = $workflowYaml['jobs']['all-checks-pass']['needs'] ?? [];
$aggregateNeedsList = array_values(array_map(
    'strval',
    is_array($aggregateNeedsRaw) ? $aggregateNeedsRaw : [$aggregateNeedsRaw],
));
if ($expectedJobsEnv === null) {
    $errors[] = 'AGGREGATE `all-checks-pass` has no `EXPECTED_JOBS` env on any step. Without it the '
        .'result-evaluation step cannot tell "every dependency succeeded" from "the needs context was '
        .'empty or unparseable" — the fail-open the gate proved (R1-1).';
} else {
    $declaredExpected = array_values(array_filter(
        array_map('trim', explode(',', $expectedJobsEnv)),
        static fn (string $s): bool => $s !== '',
    ));
    $sortedExpected = $declaredExpected;
    $sortedNeeds = $aggregateNeedsList;
    sort($sortedExpected);
    sort($sortedNeeds);
    if ($sortedExpected !== $sortedNeeds) {
        $errors[] = sprintf(
            'AGGREGATE EXPECTED_JOBS MISMATCH: the step declares %d job(s), `needs:` declares %d. The two '
            .'must be identical — EXPECTED_JOBS is what proves the parsed `needs` context is complete, so a '
            .'name missing from it is a dependency the gate silently stops aggregating. Only in needs: %s. '
            .'Only in EXPECTED_JOBS: %s.',
            count($declaredExpected),
            count($aggregateNeedsList),
            json_encode(array_values(array_diff($sortedNeeds, $sortedExpected))),
            json_encode(array_values(array_diff($sortedExpected, $sortedNeeds))),
        );
    }
}
$declaredSkippable = $allowSkipped === null
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $allowSkipped)), static fn (string $s): bool => $s !== ''));
$expectedSkippable = array_values(array_unique($gatedLaneJobs));
sort($declaredSkippable);
sort($expectedSkippable);
if ($declaredSkippable !== $expectedSkippable) {
    $errors[] = sprintf(
        'AGGREGATE SKIP-TOLERANCE MISMATCH: `all-checks-pass` tolerates skipped jobs %s, but the flag-gated '
        .'lane jobs are %s. The two sets must be identical — a name in the tolerance list that is not a '
        .'flag-gated lane job is a job whose failure or disappearance the aggregate would forgive.',
        json_encode($declaredSkippable),
        json_encode($expectedSkippable),
    );
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
    $pullRequest = $workflowYaml['on']['pull_request'] ?? null;

    $prBranches = $pullRequest['branches'] ?? null;
    if (! is_array($prBranches) || ! in_array('dev', $prBranches, true)) {
        $errors[] = sprintf(
            'TRIGGER SET: a lane declares runs_on_pr_dev=true, but the workflow does not start on '
            .'PR->dev. `on.pull_request.branches` = %s. No job guard can rescue a workflow that never '
            .'runs.',
            var_export($prBranches, true),
        );
    }

    // `branches` is not the only way to stop the workflow starting. A
    // `paths-ignore` of everything, or a `types` list without the ordinary PR
    // events, each removes PR->dev just as completely and with one line.
    $pathsIgnore = $pullRequest['paths-ignore'] ?? null;
    if (is_array($pathsIgnore) && (in_array('**', $pathsIgnore, true) || in_array('**/*', $pathsIgnore, true))) {
        $errors[] = sprintf(
            'TRIGGER SET: `on.pull_request.paths-ignore` = %s excludes every path, so no PR starts the '
            .'workflow at all, yet a lane declares runs_on_pr_dev=true.',
            var_export($pathsIgnore, true),
        );
    }

    $prTypes = $pullRequest['types'] ?? null;
    if (is_array($prTypes) && array_intersect(['opened', 'synchronize', 'reopened'], $prTypes) === []) {
        $errors[] = sprintf(
            'TRIGGER SET: `on.pull_request.types` = %s contains none of opened/synchronize/reopened, so '
            .'an ordinary PR->dev never starts the workflow, yet a lane declares runs_on_pr_dev=true.',
            var_export($prTypes, true),
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
            .'membership a package-wide obligation: a gate outside the aggregate does not gate.',
            $jobId,
        );
    }
}

// ---- B3. SELF-APPLICATION --------------------------------------------------
// Everything above protects the lanes. This protects the checker itself. Without
// it the package's entire backend guard surface is removable by exactly the
// remediation an unrelated lane reaches for when `backend-architecture` goes red
// — gate it, soften it, or make it depend on a gated job — while every check here
// still reports OK. The asymmetry this removes: the security lane was protected
// against every door in `gatingDefects()` and the guard protecting IT against
// none. Both sides now run the same helper, so the door set cannot diverge again.
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
            .'and its liveness suite must both actually run.',
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
    //
    // FULL-LINE comments are dropped BEFORE the scan: a doc comment inside a
    // `run:` block that merely mentions the token (the dn-consolidation lane's
    // "Paths, not --filter, so every test in each file is gated.") is prose, not
    // an allowlist, and failing closed on it blocks every PR. Only lines whose
    // first non-space character is `#` are dropped — a trailing comment after
    // code keeps its line, so a real filter sharing a line with a comment is
    // still scanned.
    $scannable = implode("\n", array_filter(
        explode("\n", $run),
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    ));
    foreach (preg_split('/(?:&&|\|\||;|\n)/', $scannable) ?: [$scannable] as $segment) {
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
                    .'uniqueness lint cannot certify an allowlist it cannot read.',
                    trim(substr($segment, max(0, $pos - 20), 60)),
                );

                continue;
            }
            /** @var array<int,string> $m */
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
            .'`Namespace\\Class::method`, so a bare alternation lets any class whose name CONTAINS an '
            .'entry join the lane silently (AnalyticsTest also selects ExpenseAnalyticsTest). '
            .'Use the /\\\\(A|B|C)::/ form.',
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
            .'is undefined — disambiguate or split the lane.',
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
        .'Retiring a CI lane must be a deliberate edit to `debt_ceiling`, not a side effect.',
        $deferredClasses + $excludedClasses,
        $declaredDebtCeiling,
    );
}

// ---- E. FIRST-EXECUTION QUARANTINE (O-29 triage posture) --------------------
// Turning on a lane that has never executed surfaces pre-existing reds in bulk.
// The repo's answer to bulk pre-existing red is a RATCHET, not a waiver: an
// explicit, enumerated, shrink-only list. `tests/quarantine.json` is that list —
// every entry names one class (or one method), the lane it belongs to, a reason
// and the date it was opened; `Tests\TestCase` skips exactly those entries and
// only when AUTOERP_QUARANTINE=1. Absent file = no quarantine at all (strictly
// safer, so its absence is not an error). Present file = every property below is
// enforced, and the total may never exceed the ceiling.
$quarantinePath = $apiRoot.'/tests/quarantine.json';
$quarantineCount = 0;
if (is_file($quarantinePath)) {
    try {
        $quarantine = json_decode((string) file_get_contents($quarantinePath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $quarantine = null;
        $errors[] = 'QUARANTINE tests/quarantine.json does not parse as JSON: '.$e->getMessage();
    }
    if (is_array($quarantine)) {
        $entries = $quarantine['entries'] ?? null;
        $quarantineCeiling = $quarantine['ceiling'] ?? null;
        if (! is_array($entries)) {
            $errors[] = 'QUARANTINE tests/quarantine.json has no `entries` object.';
            $entries = [];
        }
        $quarantineCount = count($entries);
        if (! is_int($quarantineCeiling)) {
            $errors[] = 'QUARANTINE tests/quarantine.json has no integer `ceiling`.';
        } elseif ($quarantineCount > $quarantineCeiling) {
            $errors[] = sprintf(
                'QUARANTINE GREW: %d entr(ies), ceiling is %d. A quarantine list may shrink freely and may '
                .'never grow — fix the test, or raise the ceiling deliberately with the lane\'s baseline.',
                $quarantineCount,
                $quarantineCeiling,
            );
        }
        foreach ($entries as $target => $meta) {
            $target = (string) $target;
            if (preg_match('/^Tests(\\\\[A-Za-z0-9_]+)+(::[A-Za-z0-9_]+)?$/', $target) !== 1) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" is not a `Tests\\…\\SomeTest` class or `…Test::test_method` target.',
                    $target,
                );

                continue;
            }
            [$class, $method] = array_pad(explode('::', $target), 2, null);
            $file = $apiRoot.'/'.str_replace('\\', '/', lcfirst($class)).'.php';
            if (! is_file($file)) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" names a class with no file at %s. A stale entry silently skips '
                    .'nothing while still occupying the ceiling — delete it.',
                    $target,
                    $file,
                );

                continue;
            }

            // gate-r1 R1-5: file existence is NOT identity. The runtime hook matches
            // `Class::method` as a STRING, so `…FooTest::test_does_not_exist` — a
            // method renamed months ago, or a typo — passed validation, skipped
            // nothing, and occupied a ceiling slot forever while the lane went green
            // on a test everyone believed was quarantined. Resolve both halves for
            // real: the class through the autoloader, the method by reflection.
            if (! class_exists($class)) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" does not resolve to a loadable class (file %s exists, so the '
                    .'declared FQCN and the class it actually declares have diverged).',
                    $target,
                    $file,
                );

                continue;
            }
            if ($method !== null && ! method_exists($class, $method)) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" names a method that does not exist on %s. The runtime hook '
                    .'matches on this exact string, so the entry skips NOTHING while still occupying the '
                    .'ceiling — the silent rot this ratchet exists to prevent. Fix the name or delete it.',
                    $target,
                    $class,
                );

                continue;
            }

            if (! is_array($meta) || empty($meta['reason']) || empty($meta['lane']) || empty($meta['opened'])) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" must carry `lane`, `reason` and `opened`.',
                    $target,
                );

                continue;
            }
            $declaredLane = (string) $meta['lane'];
            if (! isset($lanes[$declaredLane])) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" names lane "%s", which is not declared in `lanes`.',
                    $target,
                    $declaredLane,
                );

                continue;
            }

            // The declared lane must be the lane that actually RUNS this target.
            // Otherwise an entry can be filed under a lane whose owner will never
            // see it while the class is skipped in a different lane entirely.
            $targetGroup = null;
            if (preg_match('/^Tests\\\\Feature\\\\([A-Za-z0-9_]+)\\\\/', $class, $groupMatch) === 1) {
                $targetGroup = $groupMatch[1];
            }
            if ($targetGroup === null) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" is not under a `Tests\\Feature\\<Group>` namespace, so no lane '
                    .'owns it. Only Feature-lane targets belong in this ratchet.',
                    $target,
                );
            } elseif (($groups[$targetGroup]['lane'] ?? null) !== $declaredLane) {
                $errors[] = sprintf(
                    'QUARANTINE entry "%s" is filed under lane "%s", but group "%s" is run by %s. File it '
                    .'under the lane that actually skips it, or its owner never sees the debt.',
                    $target,
                    $declaredLane,
                    $targetGroup,
                    var_export($groups[$targetGroup]['lane'] ?? '(no lane — the group is deferred/excluded)', true),
                );
            }
        }
    }
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
    'tests/Feature lane manifest OK — %d Feature classes in %d groups; every group has a disposition; '
    .'every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched '
    ."against %d test classes across all suites.\n",
    count($classes),
    count($byGroup),
    count($allTestClasses),
));

if ($excludedGroups > 0) {
    fwrite(STDOUT, sprintf(
        "  ⚠ EXCLUDED: %d group(s) / %d class(es) are declared unable to run in CI. Each carries a\n"
        ."    reason; relabelling a `deferred` group as `excluded` does NOT remove it from this report.\n",
        $excludedGroups,
        $excludedClasses,
    ));
}

if ($gatedGroups > 0) {
    // Loud on EVERY run, for the same reason the debt line is: these groups have
    // a real, whole-directory, aggregate-member lane — and it executes on NO
    // event until the owner flips the flag. Reported until that day.
    fwrite(STDOUT, sprintf(
        "  ⚠ PARKED BEHIND AN EXECUTION GATE: %d group(s) / %d class(es) are laned but not yet running —\n"
        ."    their job(s) are guarded by a repository-variable flag that is off. Flipping it is the owner\n"
        ."    ops step in docs/handoff/DESIGN-f2-feature-lane-execution-2026-08-21.md §7.\n",
        $gatedGroups,
        $gatedClasses,
    ));
}

if ($quarantineCount > 0) {
    fwrite(STDOUT, sprintf(
        "  ⚠ QUARANTINED: %d test target(s) are skipped when AUTOERP_QUARANTINE=1, each with a lane, a\n"
        ."    reason and an open date in tests/quarantine.json. Shrink-only.\n",
        $quarantineCount,
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
        ."    pending the F-2 CI-budget decision. Some are individually named in a --filter allowlist;\n"
        ."    a NEW class in any of these groups is selected by nothing. Ceilings are enforced above.\n"
        ."    See docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md §M2.\n",
        $deferredGroups,
        $deferredClasses,
    ));
}

exit(0);
