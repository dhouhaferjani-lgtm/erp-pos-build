<?php

declare(strict_types=1);

// Self-test fixture for BroadcastChannelTenantContextTest::test_classification_logic_catches_known_bypass_shapes.
// Round-3 NICE-TO-HAVE coverage: the outer closure has the canonical
// helper-call gate, but defines a nested anonymous class whose method
// body contains an irrelevant `return true;` AND an unrelated nested
// closure with its own returns. The narrowed scan must:
//
//   - Recognize the OUTER closure's `return $user->canAccessCompanyChannel(...)`
//     as the gate.
//   - NOT classify the nested-scope `return true;` as a violation of
//     the outer gate (different scope; not the outer closure's
//     responsibility).
//
// Expected: zero violations (this is a valid gate; nested scope is
// noise the analyzer must ignore).
//
// NOT loaded at runtime — the test reads this file directly via PhpParser.

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.company.{companyId}.nested-scope-noise', function (User $user, string $tenantId, string $companyId) {
    // Nested anonymous class — its method body's `return true;` belongs
    // to a different scope and must NOT be considered by the outer
    // closure's gate analysis.
    $unused = new class
    {
        public function helper(): bool
        {
            return true;
        }
    };

    // Nested closure — same scope-isolation argument.
    $unusedCb = function () {
        return true;
    };

    // Outer return — the actual gate. This is what the analyzer should
    // classify on.
    return $user->canAccessCompanyChannel($tenantId, $companyId);
});
