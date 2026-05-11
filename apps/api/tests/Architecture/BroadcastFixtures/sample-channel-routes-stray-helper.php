<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape (Codex round-1 NICE-TO-HAVE #1): tenant-named channel
// where the helper-method-name appears in the body but the call's
// receiver is a stray variable, NOT the closure's first parameter. The
// receiver-binding check must reject this and flag the channel under
// tenant_named_without_helper.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.company.{companyId}.stray', function (User $user, string $tenantId, string $companyId) {
    // The first parameter is $user; the helper call is on $somethingElse,
    // which is NOT the user variable. This must NOT count as a valid
    // helper-call binding for tenant-anchoring.
    $somethingElse = new stdClass;

    return $somethingElse->canAccessChannel($tenantId, $companyId);
});
