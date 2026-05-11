<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Positive control: a properly classified channel pair. Should produce
// ZERO violations.
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Tenant-named channel — classified by helper-call on the user parameter.
 *
 * @cross-tenant-anchored Channel name embeds tenantId + companyId; auth
 *   callback delegates to User::canAccessCompanyChannel. Annotation is
 *   documentation; the helper-call is the load-bearing gate.
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.example', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});

/**
 * Non-tenant-named channel — classified by @cross-tenant-by-design.
 *
 * @cross-tenant-by-design Genuinely fleet-wide notification channel for
 *   super-admin operations; no per-tenant scoping by design.
 */
Broadcast::channel('admin.system-status', function () {
    return false;
});
