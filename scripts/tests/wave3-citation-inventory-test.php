#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$output = tempnam(sys_get_temp_dir(), 'wave3-citations-');
if ($output === false) {
    throw new RuntimeException('Unable to allocate test output.');
}

try {
    $command = sprintf(
        'cd %s && php scripts/wave3-citation-inventory.php %s 2>&1',
        escapeshellarg($root),
        escapeshellarg($output),
    );
    exec($command, $lines, $exitCode);

    $handle = fopen($output, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Inventory did not create its CSV.');
    }

    $header = fgetcsv($handle, null, ',', '"', '\\');
    if (!is_array($header)) {
        throw new RuntimeException('Inventory CSV has no header.');
    }

    $rows = [];
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    $citations = array_column($rows, 'citation');
    foreach ([
        'SalesOrderToInvoiceConverter:334',
        'InvoicedBeforeDeliveryScanner:84',
        'DeliveryConfirmationModal:74',
    ] as $required) {
        if (!in_array($required, $citations, true)) {
            throw new RuntimeException("Missing extensionless citation: {$required}");
        }
    }

    foreach ($rows as $row) {
        if (($row['status'] ?? null) !== 'mapped') {
            throw new RuntimeException('Unresolved row: '.json_encode($row, JSON_THROW_ON_ERROR));
        }
        if (str_contains((string) $row['symbol'], 'file scope')) {
            throw new RuntimeException('File-scope symbol passed validation: '.$row['citation']);
        }
        if (preg_match('/anchors the cited behavior at `?\s*[{});]+\s*`?$/', (string) $row['semantic_assertion']) === 1) {
            throw new RuntimeException('Punctuation-only semantic anchor passed validation: '.$row['citation']);
        }
    }

    if ($exitCode !== 0) {
        throw new RuntimeException('Inventory exited non-zero: '.implode("\n", $lines));
    }

    echo 'wave3 citation inventory regression: PASS ('.count($rows)." rows)\n";
} finally {
    @unlink($output);
}
