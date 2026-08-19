<?php

declare(strict_types=1);

/**
 * Feature-lane manifest checker (enforcement Package 2, deliverable 2(b)).
 *
 * THE DEFECT THIS CLOSES
 * ----------------------
 * `tests/Feature` reaches CI three ways, and all three are silent when they miss:
 *
 *   1. Whole-directory runs — `tests/Feature/Security` (backend-test),
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
    $stepJob = [];
    $stepIf = [];
    foreach (($workflowYaml['jobs'] ?? []) as $jobId => $job) {
        $ifs[$jobId] = (string) ($job['if'] ?? '');
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
        }
    }

    return [
        'runs' => $runs,
        'ifs' => $ifs,
        'needs' => $needs,
        'stepJob' => $stepJob,
        'stepIf' => $stepIf,
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
$deferredGroups = 0;
$deferredClasses = 0;

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
    $owningJob = null;
    foreach ($wf['runs'] as $run) {
        if (str_contains($run, $selector)) {
            $owningJob = $wf['stepJob'][$run] ?? null;
            break;
        }
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
    $selectorStepIf = '';
    foreach ($wf['stepIf'] as $run => $stepIf) {
        if (str_contains($run, $selector)) {
            $selectorStepIf = $stepIf;
            break;
        }
    }
    if ($selectorStepIf !== '') {
        $runsOnPrDev = false;
    }

    // …and GitHub skips a job whose dependency was skipped, so a `needs:` on a
    // gated job removes the lane from PR->dev through a second door.
    $skippedNeed = null;
    foreach (($wf['needs'][$owningJob] ?? []) as $needed) {
        $neededIf = $wf['ifs'][(string) $needed] ?? '';
        if ($neededIf !== '' && ! str_contains($neededIf, "base_ref == 'dev'")) {
            $skippedNeed = (string) $needed;
            $runsOnPrDev = false;
            break;
        }
    }
    if ($claimsPrDev !== $runsOnPrDev) {
        $why = $jobIf === '' ? 'no job if: guard (always runs)' : 'job if: ' . $jobIf;
        if ($selectorStepIf !== '') {
            $why .= '; the lane STEP carries `if: ' . $selectorStepIf . '`, which skips it';
        }
        if ($skippedNeed !== null) {
            $why .= '; the job `needs: ' . $skippedNeed . '`, which is itself gated off PR->dev';
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
    if (preg_match('/\bphpunit\b|\bartisan\s+test\b/', $run) !== 1) {
        continue;
    }
    $offset = 0;
    while (($pos = strpos($run, '--filter', $offset)) !== false) {
        // …and even inside a test-runner script, a `pnpm`/`turbo` invocation on
        // the same command line owns its own `--filter`.
        $lineStart = strrpos(substr($run, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = substr($run, $lineStart, $pos - $lineStart);
        if (preg_match('/\b(pnpm|npm|yarn|turbo)\b/', $before) === 1) {
            $offset = $pos + 8;

            continue;
        }
        $offset = $pos + 8;
        $rest = substr($run, $offset);
        if (preg_match('/^(?:=|[ \t]+)(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s]+))/s', $rest, $m) !== 1) {
            $errors[] = sprintf(
                'UNPARSEABLE --filter in a `run:` step (context: %s). Fail closed: the anchoring and '
                . 'uniqueness lint cannot certify an allowlist it cannot read.',
                trim(substr($run, max(0, $pos - 20), 60)),
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
