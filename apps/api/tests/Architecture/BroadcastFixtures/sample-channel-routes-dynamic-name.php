<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape: dynamic channel name (variable expression instead of
// string literal). Cannot be statically classified; must fail under
// dynamic_names.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use Illuminate\Support\Facades\Broadcast;

$dynamicChannelName = 'tenant.{tenantId}.company.{companyId}.dynamic';

Broadcast::channel($dynamicChannelName, function () {
    return false;
});
