<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PinVerifier;
use App\Modules\POS\Domain\Enums\ApprovalScope;
use App\Modules\POS\Domain\Enums\OperatorApprovalDecision;
use App\Modules\POS\Domain\Events\ManagerOverrideAuthorized;
use App\Modules\POS\Domain\OperatorApproval;
use App\Modules\POS\Presentation\Requests\VerifyManagerPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Controller for verifying a manager's POS PIN.
 *
 * POST /api/v1/pos/verify-manager-pin
 *
 * Used by the POS terminal to confirm a manager's identity (and variance-close permission)
 * before allowing a cashier to close a shift with a variance above the hard threshold.
 *
 * Security design:
 * - Rate-limited to 3 attempts per 30 s per (IP + target user_id).
 * - Always returns { valid: false } for any failure reason — no info leak.
 * - Same-tenant guard prevents cross-tenant manager lookups.
 * - Permission check (pos.close_shift_with_variance) happens AFTER rate-limiting to
 *   avoid timing-based enumeration of permission state.
 */
final class ManagerPinController extends Controller
{
    public function __construct(
        private readonly PinVerifier $pinVerifier,
        private readonly CompanyContext $companyContext,
    ) {}

    public function verify(VerifyManagerPinRequest $request): JsonResponse
    {
        $userId = $request->string('user_id')->toString();
        $pin = $request->string('pin')->toString();
        $companyId = $request->string('company_id')->toString();
        $terminalId = $request->string('terminal_id')->toString();
        $approvalScope = ApprovalScope::from($request->string('approval_scope')->toString());
        $caller = $request->user();

        if (! $caller instanceof User) {
            return response()->json(['data' => ['valid' => false]]);
        }

        // Rate limiting: 3 attempts per 30 seconds per (tenant + terminal + user + approval scope).
        $key = 'verify-manager-pin:'.$caller->tenant_id.':'.$terminalId.':'.$userId.':'.$approvalScope->value;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'error' => [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => "Too many attempts. Try again in {$seconds} seconds.",
                ],
            ], 429);
        }
        RateLimiter::hit($key, 30);

        // Verify the target user exists + holds the permission (BEFORE checking PIN, to avoid
        // even a constant-time PIN check on an unauthorised user — but after rate-limiting to
        // avoid enumeration via timing).
        $manager = User::find($userId);
        if ($manager === null) {
            return response()->json([
                'data' => ['valid' => false],
            ]);
        }

        // Same-tenant guard.
        if ($manager->tenant_id !== $caller->tenant_id) {
            return response()->json([
                'data' => ['valid' => false],
            ]);
        }

        $decision = $this->pinVerifier->verifyForApproval(
            userId: $userId,
            pin: $pin,
            tenantId: $caller->tenant_id,
            companyId: $companyId,
            approvalScope: $approvalScope,
        );

        if ($decision !== OperatorApprovalDecision::Approved) {
            return response()->json([
                'data' => [
                    'valid' => false,
                    'failure_code' => $decision->value,
                ],
            ]);
        }

        RateLimiter::clear($key);

        // Privileged action — a successful verification is a manager
        // authorising a cashier to exceed a limit. Leave a timestamped
        // audit trail (actor = caller, target = manager). verify() does
        // no DB writes, so a direct event() dispatch is correct — there
        // is no surrounding transaction to roll back.
        event(new ManagerOverrideAuthorized(
            managerId: $userId,
            callerId: $caller->id,
            companyId: $this->companyContext->requireCompany()->id,
            verifiedAt: now()->toIso8601String(),
        ));

        OperatorApproval::query()->create([
            'tenant_id' => $caller->tenant_id,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'supervisor_user_id' => $userId,
            'cashier_user_id' => $caller->id,
            'approval_scope' => $approvalScope,
            'target_event_type' => $request->string('target_event_type')->toString(),
            'target_reference_id' => $request->string('target_reference_id')->toString(),
            'reason' => $request->string('reason')->toString(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'valid' => true,
                'user_id' => $userId,
                'user_name' => $manager->name,
                'approval_scope' => $approvalScope->value,
                'company_id' => $companyId,
                'terminal_id' => $terminalId,
            ],
        ]);
    }
}
