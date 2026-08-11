#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mechanical citation inventory for DPA Wave 3C/3D.
 *
 * Extracts every explicit file:line citation from the dispatch and plan §§0–4,
 * resolves each path on the pinned tree, maps the cited line from the plan's
 * reference tree when possible, and records an enclosing symbol plus source
 * excerpt as the semantic anchor. The command exits non-zero on any unresolved
 * row so M0 and the pre-M2 rerun fail closed.
 */

const PLAN_REFERENCE_SHA = '6626cb373';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: scripts/wave3-citation-inventory.php <output.csv>\n");
    exit(64);
}

$root = dirname(__DIR__);
$output = $argv[1];
$briefPath = $root.'/docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md';
$planPath = $root.'/.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/plan-wave3.md';

$sources = [
    'dispatch' => file_get_contents($briefPath),
    'plan-sections-0-through-4' => file_get_contents($planPath),
];

if (!is_string($sources['dispatch']) || !is_string($sources['plan-sections-0-through-4'])) {
    fwrite(STDERR, "Unable to read the dispatch or authoritative plan.\n");
    exit(66);
}

$planEnd = strpos($sources['plan-sections-0-through-4'], "\n## §5 ");
if ($planEnd === false) {
    fwrite(STDERR, "Unable to locate the end of plan §4.\n");
    exit(65);
}
$sources['plan-sections-0-through-4'] = substr($sources['plan-sections-0-through-4'], 0, $planEnd);

$trackedFiles = trackedFiles($root);
$evidenceFiles = glob($root.'/.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/*') ?: [];
$candidateFiles = array_values(array_filter(
    array_merge($trackedFiles, $evidenceFiles),
    static fn (string $path): bool => is_file($path),
));

$aliases = [
    'createCOGSEntry.php' => 'apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php',
    'Product.php' => 'apps/api/app/Modules/Product/Domain/Product.php',
    'docs/handoff/HANDOVER-dpa-session2-2026-08-09.md' => '/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/HANDOVER-dpa-session2-2026-08-09.md',
    'vendor/.../Foundation/Testing/RefreshDatabase.php' => 'apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php',
];

$pattern = '~(?<![\\w])((?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\\.(?:php|md|tsx|ts|js|mjs|neon|yaml|yml)):(\\d+(?:-\\d+)?(?:[\\/,]\\s*:?\\d+(?:-\\d+)?)*)~';
$rows = [];
$extracted = 0;

foreach ($sources as $sourceName => $contents) {
    preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $index => [$raw, $offset]) {
        $extracted++;
        $citedFile = $matches[1][$index][0];
        $lineSpec = preg_replace('/\\s+/', '', $matches[2][$index][0]);
        $sourceLine = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $resolved = resolvePath($root, $citedFile, $candidateFiles, $aliases);
        $oldStart = (int) preg_replace('/\\D.*$/', '', $lineSpec);
        $oldEnd = citedEnd($lineSpec, $oldStart);
        $status = 'mapped';
        $reason = '';
        $newStart = null;
        $newEnd = null;
        $symbol = null;
        $assertion = null;

        if ($resolved === null) {
            $status = 'unresolved';
            $reason = 'path_not_resolved';
        } else {
            [$newStart, $newEnd] = mapLines($root, $resolved, $oldStart, $oldEnd);
            $absolute = str_starts_with($resolved, '/') ? $resolved : $root.'/'.$resolved;
            $lines = file($absolute, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines) || $newStart < 1 || $newStart > count($lines)) {
                $status = 'unresolved';
                $reason = 'mapped_line_out_of_range';
            } else {
                $symbol = enclosingSymbol($lines, $newStart, $resolved);
                $assertion = semanticAnchor($lines, $newStart, $newEnd, $symbol);
                if ($symbol === '' || $assertion === '') {
                    $status = 'unresolved';
                    $reason = 'missing_symbol_or_semantic_anchor';
                }
            }
        }

        $rows[] = [
            $sourceName,
            (string) $sourceLine,
            $raw,
            $citedFile,
            $lineSpec,
            $resolved ?? '',
            $newStart === null ? '' : lineRange($newStart, $newEnd ?? $newStart),
            $symbol ?? '',
            $assertion ?? '',
            $status,
            $reason,
        ];
    }
}

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create output directory: {$directory}\n");
    exit(73);
}

$handle = fopen($output, 'wb');
if ($handle === false) {
    fwrite(STDERR, "Unable to write {$output}\n");
    exit(73);
}

fputcsv($handle, [
    'source', 'source_line', 'citation', 'cited_file', 'old_line', 'resolved_path',
    'new_line', 'symbol', 'semantic_assertion', 'status', 'reason',
], ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($handle, $row, ',', '"', '\\');
}
fclose($handle);

$unresolved = count(array_filter($rows, static fn (array $row): bool => $row[9] !== 'mapped'));
printf("N_extracted=%d N_mapped=%d unresolved=%d output=%s\n", $extracted, count($rows) - $unresolved, $unresolved, $output);
exit($unresolved === 0 ? 0 : 1);

/** @return list<string> */
function trackedFiles(string $root): array
{
    $output = shell_exec('git -C '.escapeshellarg($root).' ls-files -z');
    if (!is_string($output)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $path): string => $root.'/'.$path,
        explode("\0", $output),
    )));
}

/**
 * @param list<string> $candidates
 * @param array<string, string> $aliases
 */
function resolvePath(string $root, string $cited, array $candidates, array $aliases): ?string
{
    if (isset($aliases[$cited])) {
        return $aliases[$cited];
    }

    $direct = [
        $cited,
        'apps/api/'.$cited,
        'apps/api/app/Modules/'.$cited,
        'apps/web/'.$cited,
        '.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/'.$cited,
    ];
    foreach ($direct as $relative) {
        if (is_file($root.'/'.$relative)) {
            return $relative;
        }
    }

    $basename = basename($cited);
    $matches = array_values(array_filter(
        $candidates,
        static fn (string $candidate): bool => basename($candidate) === $basename,
    ));
    if (count($matches) === 1) {
        return relativePath($root, $matches[0]);
    }

    if (count($matches) > 1) {
        $suffixMatches = array_values(array_filter(
            $matches,
            static fn (string $candidate): bool => str_ends_with(str_replace('\\', '/', $candidate), '/'.$cited),
        ));
        if (count($suffixMatches) === 1) {
            return relativePath($root, $suffixMatches[0]);
        }

        $preferred = array_values(array_filter(
            $matches,
            static fn (string $candidate): bool => str_contains($candidate, '/apps/api/app/')
                || str_contains($candidate, '/apps/api/tests/')
                || str_contains($candidate, '/apps/api/database/'),
        ));
        if (count($preferred) === 1) {
            return relativePath($root, $preferred[0]);
        }
    }

    return null;
}

function relativePath(string $root, string $absolute): string
{
    $prefix = rtrim($root, '/').'/';
    return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
}

function citedEnd(string $lineSpec, int $start): int
{
    preg_match_all('/\\d+/', $lineSpec, $numbers);
    $values = array_map('intval', $numbers[0]);
    return $values === [] ? $start : max($values);
}

/** @return array{int, int} */
function mapLines(string $root, string $resolved, int $oldStart, int $oldEnd): array
{
    if (str_starts_with($resolved, '/') || str_starts_with($resolved, '.superpowers/') || str_starts_with($resolved, 'apps/api/vendor/')) {
        return [$oldStart, $oldEnd];
    }

    $existsAtReference = shell_exec(
        'git -C '.escapeshellarg($root).' cat-file -e '.escapeshellarg(PLAN_REFERENCE_SHA.':'.$resolved).' 2>/dev/null; printf $?',
    );
    if (trim((string) $existsAtReference) !== '0') {
        return [$oldStart, $oldEnd];
    }

    $diff = shell_exec(
        'git -C '.escapeshellarg($root).' diff --unified=0 '.escapeshellarg(PLAN_REFERENCE_SHA).' -- '.escapeshellarg($resolved),
    );
    if (!is_string($diff) || $diff === '') {
        return [$oldStart, $oldEnd];
    }

    return [mapOneLine($diff, $oldStart), mapOneLine($diff, $oldEnd)];
}

function mapOneLine(string $diff, int $oldLine): int
{
    $delta = 0;
    if (!preg_match_all('/^@@ -(\\d+)(?:,(\\d+))? \\+(\\d+)(?:,(\\d+))? @@/m', $diff, $hunks, PREG_SET_ORDER)) {
        return $oldLine;
    }

    foreach ($hunks as $hunk) {
        $oldStart = (int) $hunk[1];
        $oldCount = isset($hunk[2]) && $hunk[2] !== '' ? (int) $hunk[2] : 1;
        $newStart = (int) $hunk[3];
        $newCount = isset($hunk[4]) && $hunk[4] !== '' ? (int) $hunk[4] : 1;
        if ($oldLine < $oldStart) {
            break;
        }
        if ($oldCount > 0 && $oldLine < $oldStart + $oldCount) {
            $offset = min($oldLine - $oldStart, max(0, $newCount - 1));
            return max(1, $newStart + $offset);
        }
        $delta += $newCount - $oldCount;
    }

    return max(1, $oldLine + $delta);
}

/** @param list<string> $lines */
function enclosingSymbol(array $lines, int $line, string $path): string
{
    if (str_ends_with($path, '.md')) {
        for ($index = $line - 1; $index >= 0; $index--) {
            if (preg_match('/^#{1,6}\\s+(.+)$/', $lines[$index], $match)) {
                return 'section '.trim($match[1]);
            }
        }

        return 'document scope';
    }

    for ($index = $line - 1; $index >= max(0, $line - 250); $index--) {
        if (preg_match('/\\bfunction\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $lines[$index], $match)) {
            return $match[1].'()';
        }
        if (preg_match('/\\b(?:class|enum|interface|trait)\\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
            return $match[1];
        }
    }

    return basename($path).' file scope';
}

/** @param list<string> $lines */
function semanticAnchor(array $lines, int $start, int $end, string $symbol): string
{
    $last = min(count($lines), max($start, min($end, $start + 4)));
    $excerpt = [];
    for ($line = $start; $line <= $last; $line++) {
        $value = trim($lines[$line - 1]);
        if ($value !== '') {
            $excerpt[] = $value;
        }
    }
    $text = trim(implode(' ', $excerpt));
    $text = preg_replace('/\\s+/', ' ', $text) ?? '';
    if ($text === '') {
        $text = 'blank-line boundary immediately within the named symbol';
    }

    return $symbol.' anchors the cited behavior at `'.mb_substr($text, 0, 320).'`';
}

function lineRange(int $start, int $end): string
{
    return $start === $end ? (string) $start : $start.'-'.$end;
}
