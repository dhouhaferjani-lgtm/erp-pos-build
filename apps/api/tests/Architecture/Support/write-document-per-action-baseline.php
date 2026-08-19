<?php

declare(strict_types=1);
use Tests\Architecture\Support\DocumentPerActionWriteScanner;

/**
 * BOOTSTRAP-ONLY baseline writer for the document-per-action cementing guard.
 *
 *   php tests/Architecture/Support/write-document-per-action-baseline.php
 *
 * Writes every CURRENT violation key, sorted and de-duplicated, to
 * tests/Architecture/baselines/document-per-action-baseline.json.
 *
 * This is NOT part of CI and NOT part of any normal fix flow. The baseline is a
 * SHRINK-ONLY ratchet: entries leave it as the DPA remediation program closes
 * violators, and an entry may never be ADDED — the CI anti-growth check compares
 * the working baseline's key set against the blob named by the owner-set
 * repository variable DPA_BASELINE_PROTECTED_BLOB and fails on any added key, so
 * "land a new violation plus its freshly regenerated baseline entry" cannot pass.
 * Re-running this writer to absorb a new violation therefore produces a RED
 * build, by design. A genuine need to grow the baseline is an owner decision on
 * the record, followed by an owner variable + pin-tag update.
 */
$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';
require __DIR__.'/DocumentPerActionWriteScanner.php';

$scanner = new DocumentPerActionWriteScanner;
$sites = $scanner->scan([$root.'/app'], $root.'/');

$keys = [];
foreach ($sites as $site) {
    if ($site['classification'] === 'violation') {
        $keys[$site['key']] = true;
    }
}

$keys = array_keys($keys);
sort($keys, SORT_STRING);

$target = $root.'/tests/Architecture/baselines/document-per-action-baseline.json';
if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0o755, true);
}

file_put_contents($target, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDERR, sprintf(
    "[dpa-baseline] wrote %d baseline entries to %s\n",
    count($keys),
    'tests/Architecture/baselines/document-per-action-baseline.json',
));
