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

$pattern = '~(?<![\\w])((?:(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\\.(?:php|md|tsx|ts|js|mjs|neon|yaml|yml))|(?:[A-Z][A-Za-z0-9_]{2,})):(\\d+(?:-\\d+)?(?:[\\/,]\\s*:?\\d+(?:-\\d+)?)*)~';
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
            $reference = referenceContext($root, $resolved, $oldStart);
            if (is_array($lines) && $reference !== null) {
                [$expectedSymbol, $oldSymbolLine] = $reference;
                $mappedSymbol = enclosingSymbol($lines, $newStart, $resolved);
                if ($expectedSymbol !== '' && $mappedSymbol !== $expectedSymbol) {
                    $newSymbolLine = locateSymbolLine($lines, $expectedSymbol);
                    if ($newSymbolLine !== null) {
                        $offset = max(0, $oldStart - $oldSymbolLine);
                        $newStart = min(count($lines), $newSymbolLine + $offset);
                        $newEnd = min(count($lines), $newStart + max(0, $oldEnd - $oldStart));
                    }
                }
            }

            [$resolved, $newStart, $newEnd, $relocated] = applyRelocation($citedFile, $lineSpec, $resolved, $newStart, $newEnd);
            if ($relocated) {
                $status = 'relocated';
            }
            $absolute = str_starts_with($resolved, '/') ? $resolved : $root.'/'.$resolved;
            $lines = file($absolute, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines) || $newStart < 1 || $newStart > count($lines)) {
                $status = 'unresolved';
                $reason = 'mapped_line_out_of_range';
            } else {
                $symbol = enclosingSymbol($lines, $newStart, $resolved);
                $assertion = semanticAnchor($lines, $newStart, $newEnd, $symbol);
                if ($symbol === '' || $assertion === '' || !hasExecutableAnchor($lines, $newStart, $newEnd, $resolved)) {
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

$unresolved = count(array_filter($rows, static fn (array $row): bool => $row[9] === 'unresolved'));
$relocated = count(array_filter($rows, static fn (array $row): bool => $row[9] === 'relocated'));
printf("N_extracted=%d N_mapped=%d relocated=%d unresolved=%d output=%s\n", $extracted, count($rows) - $unresolved, $relocated, $unresolved, $output);
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
        static fn (string $candidate): bool => basename($candidate) === $basename
            || pathinfo($candidate, PATHINFO_FILENAME) === $basename,
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

/** @return array{string, int}|null */
function referenceContext(string $root, string $resolved, int $oldLine): ?array
{
    if (str_starts_with($resolved, '/') || str_starts_with($resolved, '.superpowers/') || str_starts_with($resolved, 'apps/api/vendor/')) {
        return null;
    }

    $contents = shell_exec(
        'git -C '.escapeshellarg($root).' show '.escapeshellarg(PLAN_REFERENCE_SHA.':'.$resolved).' 2>/dev/null',
    );
    if (!is_string($contents) || $contents === '') {
        return null;
    }

    $lines = preg_split('/\\R/', $contents);
    if (!is_array($lines) || $oldLine > count($lines)) {
        return null;
    }

    $symbol = enclosingSymbol($lines, $oldLine, $resolved);
    $symbolLine = locateSymbolLine($lines, $symbol) ?? $oldLine;

    return [$symbol, $symbolLine];
}

/** @param list<string> $lines */
function locateSymbolLine(array $lines, string $symbol): ?int
{
    if ($symbol === '') {
        return null;
    }
    $name = str_ends_with($symbol, '()') ? substr($symbol, 0, -2) : $symbol;
    foreach ($lines as $index => $line) {
        if (str_ends_with($symbol, '()') && preg_match('/^\\s*(?:(?:public|protected|private|static|final|abstract)\\s+)*function\\s+'.preg_quote($name, '/').'\\s*\\(/', $line) === 1) {
            return $index + 1;
        }
        if (!str_ends_with($symbol, '()') && preg_match('/^\\s*(?:(?:final|abstract|readonly)\\s+)*(?:class|enum|interface|trait)\\s+'.preg_quote($name, '/').'\\b/', $line) === 1) {
            return $index + 1;
        }
    }

    return null;
}

/** @return array{string, int, int, bool} */
function applyRelocation(string $citedFile, string $lineSpec, string $resolved, int $start, int $end): array
{
    $key = $citedFile.':'.$lineSpec;
    $relocations = [
        // 3E centralized the former InvoiceController physical-line loop.
        'InvoiceController.php:892' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 403, 403,
        ],
        // T25f deleted the second controller traversal and delegates to the gate.
        'InvoiceController.php:910-919' => [
            'apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php', 669, 674,
        ],
        // The old resolver range guarded null product ids; retain that executable guard.
        'DeliveredQuantityResolver:399-402' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveredQuantityResolver.php', 521, 523,
        ],
        // These scanners were added after the plan reference and need current semantic addresses.
        'InvoicedBeforeDeliveryScanner:84' => [
            'apps/api/app/Modules/Compliance/Services/InvoicedBeforeDeliveryScanner.php', 92, 100,
        ],
        'UndeliveredGoodsLineScanner:100' => [
            'apps/api/app/Modules/Compliance/Services/UndeliveredGoodsLineScanner.php', 127, 127,
        ],
        // The cited FE concern is the current parseFloat call, not a mapped JSX fragment.
        'DeliveryConfirmationModal:74' => [
            'apps/web/src/features/documents/components/DeliveryConfirmationModal.tsx', 74, 74,
        ],
        // Comment-only citations are carried onto the executable behavior they explain.
        'WorkOrderInvoiceDeliveryExemptionTest:40-44' => [
            'apps/api/tests/Feature/Document/WorkOrderInvoiceDeliveryExemptionTest.php', 97, 101,
        ],
        'RefundService.php:1094-1099' => [
            'apps/api/app/Modules/Document/Domain/Services/RefundService.php', 1141, 1143,
        ],
        'GeneralLedgerService.php:3248-3255' => [
            'apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php', 3521, 3522,
        ],
        'PosCoreReceiptProjection.php:1913-1922' => [
            'apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php', 2068, 2070,
        ],
        'ReturnScrapWriteOffService.php:218-227' => [
            'apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php', 214, 214,
        ],
    ];

    if (isset($relocations[$key])) {
        return [...$relocations[$key], true];
    }

    return [$resolved, $start, $end, false];
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

    for ($index = $line - 1; $index >= 0; $index--) {
        if (preg_match('/^\\s*(?:(?:public|protected|private|static|final|abstract)\\s+)*function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $lines[$index], $match)) {
            return $match[1].'()';
        }
        if (preg_match('/^\\s*(?:(?:final|abstract|readonly)\\s+)*(?:class|enum|interface|trait)\\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
            return $match[1];
        }
    }

    for ($index = $line; $index < min(count($lines), $line + 100); $index++) {
        if (preg_match('/^\\s*(?:(?:public|protected|private|static|final|abstract)\\s+)*function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $lines[$index], $match)) {
            return $match[1].'()';
        }
        if (preg_match('/^\\s*(?:(?:final|abstract|readonly)\\s+)*(?:class|enum|interface|trait)\\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
            return $match[1];
        }
    }

    for ($index = $line - 1; $index >= 0; $index--) {
        if (preg_match('/(?:Route|Schedule)::([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
            return strtolower(pathinfo($path, PATHINFO_FILENAME)).' '.$match[1].' registration';
        }
    }

    if (str_ends_with($path, '.neon') || str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')) {
        return basename($path).' configuration';
    }

    return '';
}

/** @param list<string> $lines */
function semanticAnchor(array $lines, int $start, int $end, string $symbol): string
{
    $anchor = executableAnchor($lines, $start, $end);
    if ($symbol === '' || $anchor === null) {
        return '';
    }

    $text = preg_replace('/\\s+/', ' ', trim($anchor)) ?? '';
    $text = mb_substr($text, 0, 280);
    if (str_starts_with($symbol, 'section ')) {
        return $symbol.' states the mapped obligation: `'.$text.'`';
    }
    if (preg_match('/^(?:\\*|\/\\/|\/\\*)/', trim($anchor)) === 1) {
        return $symbol.' documents the cited invariant: `'.trim(preg_replace('/^(?:\\*|\/\\/|\/\\*)\\s*/', '', $text) ?? $text).'`';
    }
    if (preg_match('/\\bthrow\\b/', $text) === 1) {
        return $symbol.' refuses the cited condition by throwing at `'.$text.'`';
    }
    if (preg_match('/\\breturn\\b/', $text) === 1) {
        return $symbol.' returns the cited result at `'.$text.'`';
    }
    if (preg_match('/\\bcase\\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $match) === 1) {
        return $symbol.' defines enum case '.$match[1].' at `'.$text.'`';
    }
    if (preg_match('/\\bfunction\\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $match) === 1) {
        return $symbol.' declares callable '.$match[1].' at `'.$text.'`';
    }
    if (preg_match('/(?:->|::)([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $text, $match) === 1) {
        return $symbol.' invokes '.$match[1].'() for the cited behavior at `'.$text.'`';
    }
    if (preg_match('/\\$([A-Za-z_][A-Za-z0-9_]*)\\s*=/', $text, $match) === 1) {
        return $symbol.' assigns $'.$match[1].' for the cited behavior at `'.$text.'`';
    }
    if (preg_match('/^if\\s*\\(/', $text) === 1) {
        return $symbol.' guards the cited behavior with `'.$text.'`';
    }

    return $symbol.' performs the mapped domain/configuration statement `'.$text.'`';
}

/** @param list<string> $lines */
function hasExecutableAnchor(array $lines, int $start, int $end, string $path): bool
{
    return executableAnchor($lines, $start, $end) !== null;
}

/** @param list<string> $lines */
function executableAnchor(array $lines, int $start, int $end): ?string
{
    $to = min(count($lines), max($end, $start + 6));
    for ($line = $start; $line <= $to; $line++) {
        $value = trim($lines[$line - 1]);
        if ($value === '' || preg_match('/^[{}\\]();,]+$/', $value) === 1) {
            continue;
        }

        if (preg_match('/^(?:\/\*|\*|\/\/)/', $value) === 1
            || preg_match('/^(?:<>|<\/>|}>|<)$/', $value) === 1) {
            continue;
        }

        return $value;
    }

    for ($line = max(1, $start - 2); $line < $start; $line++) {
        $value = trim($lines[$line - 1]);
        if ($value !== ''
            && preg_match('/^[{}\\]();,]+$/', $value) !== 1
            && preg_match('/^(?:\/\*|\*|\/\/)/', $value) !== 1
            && preg_match('/^(?:<>|<\/>|}>|<)$/', $value) !== 1) {
            return $value;
        }
    }

    return null;
}

function lineRange(int $start, int $end): string
{
    return $start === $end ? (string) $start : $start.'-'.$end;
}
