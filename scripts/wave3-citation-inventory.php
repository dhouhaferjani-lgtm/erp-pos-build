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

if ($argv[1] === '--self-test') {
    $checks = [
        anchorsCoherent("'source_id' => \$movementId,", "'source_id' => \$movementId,", 'statement'),
        !anchorsCoherent("'source_id' => \$movementId,", 'JournalLine::create([', 'statement'),
        anchorsCoherent('// GL ORDERING DEPENDENCY', '// GL ORDERING DEPENDENCY', 'comment'),
        !anchorsCoherent('if ($product->is_physical) {', 'return;', 'statement'),
    ];
    if (in_array(false, $checks, true)) {
        fwrite(STDERR, "semantic drift self-test: FAIL\n");
        exit(1);
    }
    echo "semantic drift self-test: PASS\n";
    exit(0);
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
    // Plan §0.7's prefix is shared; the table row explicitly concerns the treasury partial index.
    '2026_07_12_100000' => 'apps/api/database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php',
];

$pattern = '~(?<![\\w])((?:(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\\.(?:php|md|tsx|ts|js|mjs|neon|yaml|yml))|(?:[A-Z][A-Za-z0-9_]{2,})|(?<=`)(?:\\d{4}(?:_\\d{2}){2}_\\d{6}|[a-z][A-Za-z0-9_]{2,})):(\\d+(?:-\\d+)?(?:[\\/,]\\s*:?\\d+(?:-\\d+)?)*)~';
$rows = [];
$extracted = 0;

foreach ($sources as $sourceName => $contents) {
    preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $index => [$raw, $offset]) {
        $extracted++;
        $citedFile = $matches[1][$index][0];
        $lineSpec = preg_replace('/\\s+/', '', $matches[2][$index][0]);
        $sourceLine = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $annotation = substr($contents, $offset + strlen($raw), 40);
        $requiredAnchorKind = preg_match('/^`?\s*\((comment|docblock)\)/', $annotation, $kindMatch) === 1
            ? 'comment'
            : null;
        $resolved = resolvePath($root, $citedFile, $candidateFiles, $aliases);
        $oldStart = (int) preg_replace('/\\D.*$/', '', $lineSpec);
        $oldEnd = citedEnd($lineSpec, $oldStart);
        $status = 'mapped';
        $reason = '';
        $newStart = null;
        $newEnd = null;
        $symbol = null;
        $assertion = null;
        $anchorKind = null;

        if ($resolved === null) {
            $status = 'unresolved';
            $reason = 'path_not_resolved';
        } else {
            [$newStart, $newEnd] = mapLines($root, $resolved, $oldStart, $oldEnd);
            $absolute = str_starts_with($resolved, '/') ? $resolved : $root.'/'.$resolved;
            $lines = file($absolute, FILE_IGNORE_NEW_LINES);
            $reference = referenceContext($root, $resolved, $oldStart, $oldEnd);
            $expectedAnchorKind = $reference[2] ?? null;
            $expectedAnchorText = $reference[3] ?? null;
            if (is_array($lines) && $reference !== null) {
                [$expectedSymbol, $oldSymbolLine] = $reference;
                $mappedSymbol = enclosingSymbol($lines, $newStart, $resolved);
                if ($expectedAnchorKind !== 'comment' && $expectedSymbol !== '' && $mappedSymbol !== $expectedSymbol) {
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
                $anchor = locateSemanticAnchor(
                    $lines,
                    $newStart,
                    $newEnd,
                    $requiredAnchorKind ?? ($relocated ? 'statement' : $expectedAnchorKind),
                    $resolved,
                );
                if ($anchor === null) {
                    $status = 'unresolved';
                    $reason = 'missing_symbol_or_semantic_anchor';
                } else {
                    [$newStart, $newEnd, $anchorText, $anchorKind] = $anchor;
                    $symbol = symbolForAnchor($lines, $newStart, $newEnd, $resolved, $anchorKind);
                    $assertion = semanticAssertion($anchorText, $symbol, $anchorKind);
                    $relocationPin = $relocated ? relocationExpectation($citedFile, $lineSpec) : null;
                    if ($relocated && ($relocationPin === null
                        || $symbol !== $relocationPin[0]
                        || !str_contains($anchorText, $relocationPin[1]))) {
                        $status = 'unresolved';
                        $reason = 'relocation_semantic_pin_mismatch';
                    } elseif ($requiredAnchorKind !== null && $anchorKind !== $requiredAnchorKind) {
                        $status = 'unresolved';
                        $reason = 'required_anchor_kind_mismatch';
                    } elseif (! $relocated
                        && $requiredAnchorKind === null
                        && is_string($expectedAnchorText)
                        && !anchorsCoherent($expectedAnchorText, $anchorText, $expectedAnchorKind ?? $anchorKind)) {
                        $status = 'unresolved';
                        $reason = 'semantic_drift_requires_relocation';
                    } elseif ($symbol === '' || $assertion === '') {
                        $status = 'unresolved';
                        $reason = 'missing_symbol_or_semantic_anchor';
                    }
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
            $anchorKind ?? '',
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
    'new_line', 'symbol', 'semantic_assertion', 'anchor_kind', 'status', 'reason',
], ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($handle, $row, ',', '"', '\\');
}
fclose($handle);

$unresolved = count(array_filter($rows, static fn (array $row): bool => $row[10] === 'unresolved'));
$relocated = count(array_filter($rows, static fn (array $row): bool => $row[10] === 'relocated'));
$extensionless = count(array_filter($rows, static fn (array $row): bool => !str_contains($row[3], '.')));
$fileScope = count(array_filter($rows, static fn (array $row): bool => str_contains($row[7], 'file scope')));
$commentAnchors = count(array_filter($rows, static fn (array $row): bool => $row[9] === 'comment'));
printf("N_extracted=%d N_mapped=%d relocated=%d unresolved=%d output=%s\n", $extracted, count($rows) - $unresolved, $relocated, $unresolved, $output);
printf("csv_rows=%d extensionless=%d relocated=%d file_scope=%d comment_anchors=%d\n", count($rows), $extensionless, $relocated, $fileScope, $commentAnchors);
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

    if (preg_match('/^\d{4}(?:_\d{2}){2}_\d{6}$/', $cited) === 1) {
        $prefixMatches = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => str_starts_with(basename($candidate), $cited.'_'),
        ));
        if (count($prefixMatches) === 1) {
            return relativePath($root, $prefixMatches[0]);
        }
        return null;
    }

    if (preg_match('/^[a-z][A-Za-z0-9_]{2,}$/', $cited) === 1) {
        $symbolMatches = array_values(array_filter(
            $candidates,
            static function (string $candidate) use ($cited): bool {
                if (!str_ends_with($candidate, '.php')) {
                    return false;
                }
                $contents = file_get_contents($candidate);
                return is_string($contents)
                    && preg_match('/\bfunction\s+'.preg_quote($cited, '/').'\s*\(/', $contents) === 1;
            },
        ));
        if (count($symbolMatches) === 1) {
            return relativePath($root, $symbolMatches[0]);
        }
        return null;
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

/** @return array{string, int, string, string}|null */
function referenceContext(string $root, string $resolved, int $oldStart, int $oldEnd): ?array
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
    if (!is_array($lines) || $oldStart > count($lines)) {
        return null;
    }

    $anchor = locateSemanticAnchor($lines, $oldStart, $oldEnd, null, $resolved);
    if ($anchor === null) {
        return null;
    }
    [$anchorStart, $anchorEnd, $anchorText, $anchorKind] = $anchor;
    $symbol = symbolForAnchor($lines, $anchorStart, $anchorEnd, $resolved, $anchorKind);
    $symbolLine = locateSymbolLine($lines, $symbol) ?? $anchorStart;

    return [$symbol, $symbolLine, $anchorKind, $anchorText];
}

/** @param list<string> $lines */
function locateSymbolLine(array $lines, string $symbol): ?int
{
    if ($symbol === '') {
        return null;
    }
    $name = str_ends_with($symbol, '()') ? substr($symbol, 0, -2) : $symbol;
    foreach ($lines as $index => $line) {
        if (str_ends_with($symbol, '()') && preg_match('/^\\s*(?:(?:export|public|protected|private|static|final|abstract)\\s+)*function\\s+'.preg_quote($name, '/').'\\s*\\(/', $line) === 1) {
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
        // These scanners were added after the plan reference and need current semantic addresses.
        'InvoicedBeforeDeliveryScanner:84' => [
            'apps/api/app/Modules/Compliance/Services/InvoicedBeforeDeliveryScanner.php', 95, 100,
        ],
        'UndeliveredGoodsLineScanner:100' => [
            'apps/api/app/Modules/Compliance/Services/UndeliveredGoodsLineScanner.php', 127, 127,
        ],
        // The cited FE concern is the current parseFloat call, not a mapped JSX fragment.
        'DeliveryConfirmationModal:74' => [
            'apps/web/src/features/documents/components/DeliveryConfirmationModal.tsx', 74, 74,
        ],
        // T25f centralized the deleted DocumentPostingService traversal.
        'DocumentPostingService.php:605' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 403, 403,
        ],
        'validateDeliveryCompliance:605' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 403, 403,
        ],
        'DocumentPostingService.php:616-620' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 79, 95,
        ],
        'DocumentPostingService.php:621-624' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 79, 79,
        ],
        'DocumentPostingService.php:623-624' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 79, 79,
        ],
        'DocumentPostingService.php:626-630' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryComplianceGate.php', 81, 99,
        ],
        // T25f moved the scoped product-copy twin into the shared factory.
        'SalesOrderToInvoiceConverter:525-540' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryNoteFromDocumentFactory.php', 111, 118,
        ],
        // Integrated 3A/3B semantic successors whose code shape intentionally changed.
        'SalesOrderToDeliveryNoteConverter:566' => [
            'apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php', 572, 574,
        ],
        'DeliveryNoteService.php:243' => [
            'apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php', 256, 259,
        ],
        'SalesOrderService.php:118' => [
            'apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php', 124, 127,
        ],
        'GoodsReceiptService.php:543-552' => [
            'apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php', 582, 593,
        ],
        'PosCoreReceiptProjection.php:1767-1787' => [
            'apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php', 1861, 1887,
        ],
    ];

    if (isset($relocations[$key])) {
        return [...$relocations[$key], true];
    }

    return [$resolved, $start, $end, false];
}

/** @return array{string, string}|null */
function relocationExpectation(string $citedFile, string $lineSpec): ?array
{
    $pins = [
        'InvoiceController.php:892' => ['hasPhysicalLines()', 'PhysicalLinePredicate::forLine'],
        'InvoiceController.php:910-919' => ['post()', '$deliveryStatus = $this->deliveryComplianceGate->evaluate'],
        'InvoicedBeforeDeliveryScanner:84' => ['scan()', "->whereHas('lines'"],
        'UndeliveredGoodsLineScanner:100' => ['scan()', "->where('reference_type', 'Document')"],
        'DeliveryConfirmationModal:74' => ['DeliveryConfirmationModal()', 'parseFloat(amount)'],
        'DocumentPostingService.php:605' => ['hasPhysicalLines()', 'PhysicalLinePredicate::forLine'],
        'validateDeliveryCompliance:605' => ['hasPhysicalLines()', 'PhysicalLinePredicate::forLine'],
        'DocumentPostingService.php:616-620' => ['evaluate()', 'linkedDeliveryNoteIdsFor'],
        'DocumentPostingService.php:621-624' => ['evaluate()', 'linkedDeliveryNoteIdsFor'],
        'DocumentPostingService.php:623-624' => ['evaluate()', 'linkedDeliveryNoteIdsFor'],
        'DocumentPostingService.php:626-630' => ['evaluate()', 'if ($noteIds === [])'],
        'SalesOrderToInvoiceConverter:525-540' => ['createDraftFrom()', '$product = Product::query()'],
        'SalesOrderToDeliveryNoteConverter:566' => ['hasPhysicalProducts()', 'PhysicalLinePredicate::forLine'],
        'DeliveryNoteService.php:243' => ['issueStock()', 'PhysicalLinePredicate::physicalProductFor'],
        'SalesOrderService.php:118' => ['confirmAndReserveStock()', 'PhysicalLinePredicate::physicalProductFor'],
        'GoodsReceiptService.php:543-552' => ['processReceiptLines()', '$pendingGlPostings[] = ['],
        'PosCoreReceiptProjection.php:1767-1787' => ['decrementStock()', 'StockMovement::query()->create(['],
    ];

    return $pins[$citedFile.':'.$lineSpec] ?? null;
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
        if (preg_match('/^\\s*(?:(?:export|public|protected|private|static|final|abstract)\\s+)*function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $lines[$index], $match)) {
            return $match[1].'()';
        }
        if (preg_match('/^\\s*(?:(?:final|abstract|readonly)\\s+)*(?:class|enum|interface|trait)\\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
            return $match[1];
        }
    }

    for ($index = $line; $index < min(count($lines), $line + 100); $index++) {
        if (preg_match('/^\\s*(?:(?:export|public|protected|private|static|final|abstract)\\s+)*function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $lines[$index], $match)) {
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

/**
 * @param list<string> $lines
 * @return array{int, int, string, string}|null
 */
function locateSemanticAnchor(array $lines, int $start, int $end, ?string $preferredKind, string $path): ?array
{
    if (str_ends_with($path, '.md')) {
        for ($line = $start; $line <= min(count($lines), max($start, $end)); $line++) {
            if (trim($lines[$line - 1]) !== '') {
                return [$line, $line, trim($lines[$line - 1]), 'document'];
            }
        }

        return null;
    }

    $start = max(1, $start);
    $end = min(count($lines), max($start, $end));
    $first = trim($lines[$start - 1]);
    $firstCommentLine = null;
    $commentCount = 0;
    $statementCount = 0;
    $classificationEnd = $preferredKind === 'comment'
        ? min(count($lines), max($end, $start + 8))
        : $end;
    for ($line = $start; $line <= $classificationEnd; $line++) {
        $value = trim($lines[$line - 1]);
        if (isCommentLine($value)) {
            $firstCommentLine ??= $line;
            $commentCount++;
        } elseif (isStatementLine($value)) {
            $statementCount++;
        }
    }
    $commentPreferred = $preferredKind === 'comment'
        || ($preferredKind === null && (isCommentLine($first) || ($commentCount > 0 && $statementCount === 0)));

    if ($commentPreferred) {
        $commentStart = $firstCommentLine ?? $start;
        $commentFirst = trim($lines[$commentStart - 1]);
        if (str_starts_with($commentFirst, '//') && $commentStart > 1 && str_starts_with(trim($lines[$commentStart - 2]), '//')) {
            $commentStart--;
        }

        $commentLines = [];
        $commentEnd = $commentStart - 1;
        for ($line = $commentStart; $line <= $classificationEnd; $line++) {
            $value = trim($lines[$line - 1]);
            if ($value === '') {
                continue;
            }
            if (!isCommentLine($value)) {
                break;
            }
            $commentLines[] = $value;
            $commentEnd = $line;
        }
        if ($commentLines !== []) {
            return [$commentStart, $commentEnd, implode(' ', $commentLines), 'comment'];
        }
    }

    if (isStatementLine($first)) {
        return [$start, max($start, $end), $first, 'statement'];
    }

    for ($line = $start - 1; $line >= max(1, $start - 2); $line--) {
        $value = trim($lines[$line - 1]);
        if (isStatementLine($value)) {
            return [$line, $line, $value, 'statement'];
        }
    }

    $to = min(count($lines), max($end, $start + 6));
    for ($line = $start + 1; $line <= $to; $line++) {
        $value = trim($lines[$line - 1]);
        if (isStatementLine($value)) {
            return [$line, max($line, $end), $value, 'statement'];
        }
    }

    return null;
}

function isCommentLine(string $value): bool
{
    return preg_match('/^(?:\/\*|\*|\/\/|\{\/\*)/', $value) === 1;
}

function isStatementLine(string $value): bool
{
    return $value !== ''
        && !isCommentLine($value)
        && preg_match('/^[{}\]();,]+$/', $value) !== 1
        && preg_match('/^(?:<>|<\/>|}>|<)$/', $value) !== 1;
}

/** @param list<string> $lines */
function symbolForAnchor(array $lines, int $start, int $end, string $path, string $anchorKind): string
{
    if ($anchorKind === 'comment') {
        $value = trim($lines[$start - 1]);
        if (str_starts_with($value, '*') || str_starts_with($value, '/*')) {
            for ($index = $end; $index < min(count($lines), $end + 100); $index++) {
                if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $lines[$index], $match)) {
                    return $match[1].'()';
                }
                if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|enum|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/', $lines[$index], $match)) {
                    return $match[1];
                }
            }
        }
    }

    return enclosingSymbol($lines, $start, $path);
}

function semanticAssertion(string $anchor, string $symbol, string $anchorKind): string
{
    if ($symbol === '') {
        return '';
    }

    $text = mb_substr(preg_replace('/\s+/', ' ', trim($anchor)) ?? '', 0, 280);
    if ($anchorKind === 'document') {
        return $symbol.' states the mapped obligation: `'.$text.'`';
    }
    if ($anchorKind === 'comment') {
        $clean = trim(preg_replace('/^(?:(?:\/\*+)|(?:\*+)|(?:\/\/)|(?:\{\/\*))\s*/', '', $text) ?? $text);
        return $symbol.' documents the cited invariant: `'.$clean.'`';
    }
    if (preg_match('/\bthrow\b/', $text) === 1) {
        return $symbol.' refuses the cited condition by throwing at `'.$text.'`';
    }
    if (preg_match('/\breturn\b/', $text) === 1) {
        return $symbol.' returns the cited result at `'.$text.'`';
    }
    if (preg_match('/\bcase\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $match) === 1) {
        return $symbol.' defines enum case '.$match[1].' at `'.$text.'`';
    }
    if (preg_match('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $match) === 1) {
        return $symbol.' declares callable '.$match[1].' at `'.$text.'`';
    }
    if (preg_match('/(?:->|::)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $text, $match) === 1) {
        return $symbol.' invokes '.$match[1].'() for the cited behavior at `'.$text.'`';
    }
    if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=/', $text, $match) === 1) {
        return $symbol.' assigns $'.$match[1].' for the cited behavior at `'.$text.'`';
    }
    if (preg_match('/^if\s*\(/', $text) === 1) {
        return $symbol.' guards the cited behavior with `'.$text.'`';
    }

    return $symbol.' performs the mapped domain/configuration statement `'.$text.'`';
}

function anchorsCoherent(string $reference, string $current, string $kind): bool
{
    $referenceTokens = semanticTokens($reference);
    $currentTokens = semanticTokens($current);
    if ($referenceTokens === [] || $currentTokens === []) {
        return trim($reference) === trim($current);
    }

    $overlap = array_intersect($referenceTokens, $currentTokens);
    $containment = count($overlap) / min(count($referenceTokens), count($currentTokens));
    $threshold = $kind === 'comment' ? 0.35 : 0.34;

    return $containment >= $threshold;
}

/** @return list<string> */
function semanticTokens(string $text): array
{
    preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', strtolower($text), $matches);
    $stop = [
        'and', 'are', 'but', 'for', 'from', 'function', 'not', 'null', 'return',
        'static', 'that', 'the', 'this', 'true', 'false', 'where', 'with',
    ];

    return array_values(array_unique(array_filter(
        $matches[0],
        static fn (string $token): bool => !in_array($token, $stop, true),
    )));
}

function lineRange(int $start, int $end): string
{
    return $start === $end ? (string) $start : $start.'-'.$end;
}
