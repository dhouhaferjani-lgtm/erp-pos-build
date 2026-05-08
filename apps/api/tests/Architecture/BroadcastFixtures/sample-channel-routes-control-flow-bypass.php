<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape (Codex round-2 BLOCKER #1, control-flow blindness):
// tenant-named channel where the helper-call exists in dead/unreachable
// control flow but the actual return value is `true`. The pre-fix
// `closureCallsAllowedAuthHelperOnFirstParam()` accepted ANY descendant
// helper-call regardless of return-path. The new
// `closureGatesViaAllowedAuthHelper()` requires every Return_ to be
// either a helper-call or a denial literal — this fixture must fail
// under tenant_named_without_helper.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.company.{companyId}.control-flow-bypass', function (User $user, string $tenantId, string $companyId) {
    if (false) {
        // Helper exists in the AST but is on a dead path.
        return $user->canAccessCompanyChannel($tenantId, $companyId);
    }

    // The actual returned value is unconditionally true — no real gate.
    return true;
});
