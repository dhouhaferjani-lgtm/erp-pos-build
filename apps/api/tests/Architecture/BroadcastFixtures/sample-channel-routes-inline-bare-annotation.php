<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape (Codex round-2 BLOCKER #2, single-line annotation regex):
// non-tenant-named channel with a SINGLE-LINE bare @cross-tenant-by-design
// annotation. The pre-fix regex `[^\r\n]*` captured the closing `*/` as
// non-empty justification, so `trim($matches[1])` returned `'*/'` and the
// channel passed. The new docblock-cleaner strips the closing delimiter
// before the regex runs, so this fixture must fail under bare_annotations.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use Illuminate\Support\Facades\Broadcast;

/** @cross-tenant-by-design */
Broadcast::channel('admin.inline-bare-channel', function () {
    return false;
});
