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

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$workflow = (string) file_get_contents($workflowPath);

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
    }
    if (($isExcluded || $isDeferred) && empty($entry['reason'])) {
        $errors[] = sprintf('GROUP "%s" is %s with no `reason` string.', $group, $isExcluded ? 'excluded' : 'deferred');
    }
    if ($isDeferred) {
        $deferredGroups++;
        $deferredClasses += count($members);
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
    if (! str_contains($workflow, $selector)) {
        $errors[] = sprintf(
            'LANE "%s" is a FICTION: its selector %s does not appear in .github/workflows/ci.yml.',
            $laneId,
            var_export($selector, true),
        );
    }
}

// ---- C + D. surviving --filter allowlists ----------------------------------
$basenames = [];
foreach ($allTestClasses as $relative) {
    $basenames[] = substr(basename($relative), 0, -4); // strip ".php"
}
$basenameCounts = array_count_values($basenames);

preg_match_all('/--filter=(["\'])(.*?)\1/s', $workflow, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    $raw = $match[2];

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
    fwrite(STDOUT, sprintf(
        "  ⚠ COVERAGE DEBT: %d group(s) / %d class(es) run in NO CI lane on any event, pending the\n"
        . "    F-2 CI-budget decision. See docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md §M2.\n",
        $deferredGroups,
        $deferredClasses,
    ));
}

exit(0);
