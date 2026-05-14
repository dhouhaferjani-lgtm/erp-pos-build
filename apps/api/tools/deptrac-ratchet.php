<?php

declare(strict_types=1);

/**
 * Deptrac ratchet wrapper — M3.1, dev deferred backlog 2026-05-14.
 *
 * Deptrac on its own fails CI the moment a single boundary violation exists,
 * which is useless against a 59-violation legacy baseline. This wrapper turns
 * Deptrac into a RATCHET:
 *
 *   - It runs Deptrac, groups violations by category ("LayerFrom on LayerTo").
 *   - It compares each category's count against deptrac.baseline.json.
 *   - DOMAIN LEAKAGE ("ModuleDomain on *" — Domain reaching up into
 *     Application / Infrastructure / Presentation) hard-fails as a BLOCKER if
 *     its count rises above baseline OR a brand-new domain-leakage category
 *     appears. This is the architectural invariant the M3 plan protects.
 *   - Every OTHER category is ratcheted: its count may shrink (good) or hold,
 *     but never grow. The total is also ratcheted so it cannot increase.
 *   - When counts drop, it tells you to re-baseline so the gain is locked in.
 *
 * Usage:
 *   php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
 *   php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json --update-baseline
 *
 * Exit codes: 0 = within baseline (or baseline updated), 1 = regression or error.
 */
$baseDir = dirname(__DIR__);

$options = getopt('', ['config:', 'baseline:', 'update-baseline']);
$configPath = isset($options['config']) && is_string($options['config'])
    ? $options['config']
    : 'deptrac.yaml';
$baselinePath = isset($options['baseline']) && is_string($options['baseline'])
    ? $options['baseline']
    : 'deptrac.baseline.json';
$updateBaseline = array_key_exists('update-baseline', $options);

$configAbs = $configPath[0] === '/' ? $configPath : $baseDir.'/'.$configPath;
$baselineAbs = $baselinePath[0] === '/' ? $baselinePath : $baseDir.'/'.$baselinePath;

if (! is_file($configAbs)) {
    fwrite(STDERR, "deptrac-ratchet: config file not found: {$configAbs}\n");
    exit(1);
}

/**
 * Run Deptrac and return the decoded JSON report.
 *
 * Deptrac exits 1 whenever any violation exists — that is the normal,
 * expected case for a ratcheted baseline, NOT a tooling error. A genuine
 * failure (bad config, parse error) is detected by the absence of a valid
 * "Report" block in the JSON payload.
 *
 * @return array{Report: array<string, int>, files: array<string, array{messages?: array<int, array{message?: string, type?: string}>}>}
 */
function runDeptrac(string $baseDir, string $configAbs): array
{
    $cmd = sprintf(
        '%s %s analyse --config-file=%s --formatter=json --no-progress 2>%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($baseDir.'/vendor/bin/deptrac'),
        escapeshellarg($configAbs),
        escapeshellarg($baseDir.'/storage/deptrac-ratchet-stderr.log'),
    );

    $json = shell_exec($cmd);
    if (! is_string($json) || $json === '') {
        $stderr = @file_get_contents($baseDir.'/storage/deptrac-ratchet-stderr.log');
        fwrite(STDERR, "deptrac-ratchet: Deptrac produced no output.\n");
        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $stderr."\n");
        }
        exit(1);
    }

    /** @var mixed $decoded */
    $decoded = json_decode($json, true);
    if (! is_array($decoded) || ! isset($decoded['Report']) || ! is_array($decoded['Report'])) {
        fwrite(STDERR, "deptrac-ratchet: Deptrac output was not a valid JSON report (config error?).\n");
        fwrite(STDERR, substr($json, 0, 2000)."\n");
        exit(1);
    }

    /** @var array{Report: array<string, int>, files: array<string, array{messages?: array<int, array{message?: string, type?: string}>}>} $decoded */
    return $decoded;
}

/**
 * Group Deptrac violations by "LayerFrom on LayerTo" category.
 *
 * @param  array{files: array<string, array{messages?: array<int, array{message?: string, type?: string}>}>}  $report
 * @return array<string, int>
 */
function categorise(array $report): array
{
    $categories = [];
    foreach ($report['files'] as $file) {
        foreach ($file['messages'] ?? [] as $message) {
            if (($message['type'] ?? '') !== 'error') {
                continue;
            }
            $text = $message['message'] ?? '';
            if (preg_match('/\(([A-Za-z]+) on ([A-Za-z]+)\)\s*$/', $text, $matches) === 1) {
                $key = $matches[1].' on '.$matches[2];
            } else {
                $key = 'Unparsed';
            }
            $categories[$key] = ($categories[$key] ?? 0) + 1;
        }
    }
    ksort($categories);

    return $categories;
}

/** A category is domain leakage when the Domain tier reaches up the stack. */
function isDomainLeakage(string $category): bool
{
    return str_starts_with($category, 'ModuleDomain on ');
}

$report = runDeptrac($baseDir, $configAbs);
$current = categorise($report);
$currentTotal = array_sum($current);

echo "=== Deptrac ratchet ===\n";
echo "Config:   {$configPath}\n";
echo "Baseline: {$baselinePath}\n\n";
echo "Deptrac report: {$report['Report']['Violations']} violations, ";
echo "{$report['Report']['Allowed']} allowed, {$report['Report']['Uncovered']} uncovered.\n\n";

if ($updateBaseline) {
    $payload = [
        'generated_at' => date('Y-m-d'),
        'note' => 'Regenerate with: php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json --update-baseline',
        'total' => $currentTotal,
        'categories' => $current,
    ];
    file_put_contents(
        $baselineAbs,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
    echo "Baseline written to {$baselinePath} ({$currentTotal} violations across ".count($current)." categories).\n";
    exit(0);
}

if (! is_file($baselineAbs)) {
    fwrite(STDERR, "deptrac-ratchet: baseline file not found: {$baselineAbs}\n");
    fwrite(STDERR, "Generate it once with --update-baseline.\n");
    exit(1);
}

/** @var mixed $baselineRaw */
$baselineRaw = json_decode((string) file_get_contents($baselineAbs), true);
if (! is_array($baselineRaw) || ! isset($baselineRaw['categories']) || ! is_array($baselineRaw['categories'])) {
    fwrite(STDERR, "deptrac-ratchet: baseline file is malformed: {$baselineAbs}\n");
    exit(1);
}
/** @var array<string, int> $baselineCategories */
$baselineCategories = $baselineRaw['categories'];
$baselineTotal = is_int($baselineRaw['total'] ?? null) ? $baselineRaw['total'] : array_sum($baselineCategories);

$allCategories = array_unique([...array_keys($current), ...array_keys($baselineCategories)]);
sort($allCategories);

$blockers = [];
$ratchetFailures = [];
$improvements = [];

printf("%-46s %8s %8s   %s\n", 'Category', 'baseline', 'current', 'status');
printf("%s\n", str_repeat('-', 78));
foreach ($allCategories as $category) {
    $baseCount = $baselineCategories[$category] ?? 0;
    $curCount = $current[$category] ?? 0;
    $domainLeak = isDomainLeakage($category);

    if ($curCount > $baseCount) {
        $status = $domainLeak ? 'BLOCKER (+'.($curCount - $baseCount).')' : 'RATCHET (+'.($curCount - $baseCount).')';
        if ($domainLeak) {
            $blockers[] = $category;
        } else {
            $ratchetFailures[] = $category;
        }
    } elseif ($curCount < $baseCount) {
        $status = 'improved (-'.($baseCount - $curCount).')';
        $improvements[] = $category;
    } else {
        $status = $domainLeak ? 'held (domain)' : 'held';
    }

    printf("%-46s %8d %8d   %s\n", $category, $baseCount, $curCount, $status);
}
printf("%s\n", str_repeat('-', 78));
printf("%-46s %8d %8d\n\n", 'TOTAL', $baselineTotal, $currentTotal);

$failed = false;

if ($blockers !== []) {
    $failed = true;
    echo "BLOCKER — new Domain-tier leakage (Domain must not depend on Application/Infrastructure/Presentation):\n";
    foreach ($blockers as $category) {
        echo "  - {$category}\n";
    }
    echo "\n";
}

if ($ratchetFailures !== []) {
    $failed = true;
    echo "RATCHET REGRESSION — these categories grew above baseline:\n";
    foreach ($ratchetFailures as $category) {
        echo "  - {$category}\n";
    }
    echo "\n";
}

if ($currentTotal > $baselineTotal) {
    $failed = true;
    echo "RATCHET REGRESSION — total violations rose from {$baselineTotal} to {$currentTotal}.\n\n";
}

if ($improvements !== []) {
    echo 'Improvements detected in: '.implode(', ', $improvements)."\n";
    echo "Lock them in: php tools/deptrac-ratchet.php --config={$configPath} --baseline={$baselinePath} --update-baseline\n\n";
}

if ($failed) {
    echo "RESULT: FAIL — architecture boundary regression. See above.\n";
    exit(1);
}

echo "RESULT: PASS — no boundary regression against baseline.\n";
exit(0);
