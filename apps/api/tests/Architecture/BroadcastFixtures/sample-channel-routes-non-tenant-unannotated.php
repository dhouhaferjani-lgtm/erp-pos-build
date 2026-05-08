<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape: non-tenant-named channel with NO @cross-tenant-by-design
// annotation at all. Must fail under non_tenant_without_by_design.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('global.unannotated-channel', function () {
    return false;
});
