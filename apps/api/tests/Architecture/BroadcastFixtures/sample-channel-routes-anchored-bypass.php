<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Bypass shape (Codex round-1 BLOCKER): tenant-named channel with
// @cross-tenant-anchored PHPDoc but body is `return true;`. The
// annotation should NOT bypass the helper-call requirement; the test
// must flag this under tenant_named_without_helper.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Bypass shape: tenant-named channel with rich annotation but no
 * helper call. Pre-fix this passed the architecture test; post-fix it
 * MUST fail under tenant_named_without_helper.
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId; this
 *   pretends to be classified by annotation alone, but the closure body
 *   skips the helper call and returns true unconditionally.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.bypass', function (User $user, string $tenantId, string $companyId) {
    return true;
});
