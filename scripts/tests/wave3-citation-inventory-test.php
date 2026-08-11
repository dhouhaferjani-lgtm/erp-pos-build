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

    $expectedAnchors = [
        'DeliveredQuantityResolver:399-402' => ['521-523', 'statement'],
        'InvoicedBeforeDeliveryScanner:84' => ['95-100', 'statement'],
        'DeliveryConfirmationModal:74' => ['74', 'statement'],
        'UndeliveredGoodsLineScanner:100' => ['127', 'statement'],
        'InvoiceController.php:892' => ['403', 'statement'],
        'InvoiceController.php:910-919' => ['674', 'statement'],
        'ReturnScrapWriteOffService.php:218-227' => ['217-226', 'comment'],
        'ReturnScrapWriteOffService.php:216-227' => ['217-226', 'comment'],
        'GeneralLedgerService.php:4444' => ['4708', 'statement'],
        'DocumentPostingService.php:605' => ['403', 'statement'],
        'DocumentPostingService.php:616-620' => ['79-95', 'statement'],
        'DocumentPostingService.php:621-624' => ['79', 'statement'],
        'DocumentPostingService.php:623-624' => ['79', 'statement'],
        'DocumentPostingService.php:626-630' => ['81-99', 'statement'],
        'SalesOrderToInvoiceConverter:525-540' => ['111-118', 'statement'],
        'SalesOrderToDeliveryNoteConverter:566' => ['572-574', 'statement'],
        'DeliveryNoteService.php:243' => ['256-259', 'statement'],
        'SalesOrderService.php:118' => ['124-127', 'statement'],
        'GoodsReceiptService.php:543-552' => ['582-593', 'statement'],
        'PosCoreReceiptProjection.php:1767-1787' => ['1861-1887', 'statement'],
    ];
    foreach ($expectedAnchors as $citation => [$expectedLine, $expectedKind]) {
        $row = current(array_filter(
            $rows,
            static fn (array $candidate): bool => $candidate['citation'] === $citation,
        ));
        if (!is_array($row) || $row['new_line'] !== $expectedLine || ($row['anchor_kind'] ?? null) !== $expectedKind) {
            throw new RuntimeException("Incorrect semantic relocation for {$citation}; expected {$expectedLine}.");
        }
    }

    $expectedManualRelocations = [
        'InvoiceController.php:892',
        'InvoiceController.php:910-919',
        'InvoicedBeforeDeliveryScanner:84',
        'UndeliveredGoodsLineScanner:100',
        'DeliveryConfirmationModal:74',
        'DocumentPostingService.php:605',
        'DocumentPostingService.php:616-620',
        'DocumentPostingService.php:621-624',
        'DocumentPostingService.php:623-624',
        'DocumentPostingService.php:626-630',
        'SalesOrderToInvoiceConverter:525-540',
        'SalesOrderToDeliveryNoteConverter:566',
        'DeliveryNoteService.php:243',
        'SalesOrderService.php:118',
        'GoodsReceiptService.php:543-552',
        'PosCoreReceiptProjection.php:1767-1787',
    ];
    $actualManualRelocations = array_values(array_unique(array_column(array_filter(
        $rows,
        static fn (array $row): bool => ($row['status'] ?? null) === 'relocated',
    ), 'citation')));
    sort($expectedManualRelocations);
    sort($actualManualRelocations);
    if ($actualManualRelocations !== $expectedManualRelocations) {
        throw new RuntimeException('Manual relocation table is not fully pinned: '.json_encode($actualManualRelocations));
    }

    foreach ([
        'DeliveredQuantityResolver.php:185-199' => 'hasGoodsIssued()',
        'StockAdjustmentDocumentService.php:336' => 'correct()',
        'ReceiptReturnService.php:1250' => 'restoreStock()',
    ] as $citation => $expectedSymbol) {
        $row = current(array_filter($rows, static fn (array $candidate): bool => $candidate['citation'] === $citation));
        if (!is_array($row) || $row['symbol'] !== $expectedSymbol) {
            throw new RuntimeException("Docblock symbol mismatch for {$citation}; expected {$expectedSymbol}.");
        }
    }

    foreach ($rows as $row) {
        if (!in_array(($row['status'] ?? null), ['mapped', 'relocated'], true)) {
            throw new RuntimeException('Unresolved row: '.json_encode($row, JSON_THROW_ON_ERROR));
        }
        if (str_contains((string) $row['symbol'], 'file scope')) {
            throw new RuntimeException('File-scope symbol passed validation: '.$row['citation']);
        }
        if (preg_match('/anchors the cited behavior at `?\s*[{});]+\s*`?$/', (string) $row['semantic_assertion']) === 1) {
            throw new RuntimeException('Punctuation-only semantic anchor passed validation: '.$row['citation']);
        }
        if (!in_array(($row['anchor_kind'] ?? null), ['statement', 'comment', 'document'], true)) {
            throw new RuntimeException('Unclassified semantic anchor: '.$row['citation']);
        }
        $resolved = (string) $row['resolved_path'];
        $absolute = str_starts_with($resolved, '/') ? $resolved : $root.'/'.$resolved;
        $source = file($absolute, FILE_IGNORE_NEW_LINES);
        $anchorLine = (int) preg_replace('/\D.*$/', '', (string) $row['new_line']);
        $anchorText = is_array($source) && $anchorLine > 0 ? trim((string) ($source[$anchorLine - 1] ?? '')) : '';
        $isComment = preg_match('/^(?:\/\*|\*|\/\/|\{\/\*)/', $anchorText) === 1;
        $isPunctuation = preg_match('/^[{}\]();,]+$/', $anchorText) === 1
            || preg_match('/^(?:<>|<\/>|}>|<)$/', $anchorText) === 1;
        if ($row['anchor_kind'] === 'statement' && ($anchorText === '' || $isComment || $isPunctuation)) {
            throw new RuntimeException('Statement row records a non-statement address: '.$row['citation']);
        }
        if ($row['anchor_kind'] === 'comment' && ! $isComment) {
            throw new RuntimeException('Comment row records a non-comment address: '.$row['citation']);
        }
        if (preg_match('/`\s*(?:<>|}>|<\/?>)\s*`$/', (string) $row['semantic_assertion']) === 1) {
            throw new RuntimeException('Markup punctuation passed code-anchor validation: '.$row['citation']);
        }
    }

    if ($exitCode !== 0) {
        throw new RuntimeException('Inventory exited non-zero: '.implode("\n", $lines));
    }

    exec(
        'cd '.escapeshellarg($root).' && php scripts/wave3-citation-inventory.php --self-test 2>&1',
        $selfTestLines,
        $selfTestExit,
    );
    if ($selfTestExit !== 0) {
        throw new RuntimeException('Semantic-drift self-test failed: '.implode("\n", $selfTestLines));
    }

    echo 'wave3 citation inventory regression: PASS ('.count($rows)." rows)\n";
} finally {
    @unlink($output);
}
