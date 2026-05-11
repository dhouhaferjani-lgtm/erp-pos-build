<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape: non-tenant-named channel with bare @cross-tenant-by-design
// (no justification text). Must fail under bare_annotations.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use Illuminate\Support\Facades\Broadcast;

/**
 * Bare annotation — no justification.
 *
 * @cross-tenant-by-design
 */
Broadcast::channel('admin.bare-system-channel', function () {
    return false;
});
