<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Infrastructure\Repositories\UserRepository;
use App\Modules\POS\Domain\Enums\ApprovalScope;
use App\Modules\POS\Domain\Enums\OperatorApprovalDecision;
use Illuminate\Support\Facades\Hash;

final class PinVerifier
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function verify(string $userId, string $pin): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        if ($user->pos_pin === null) {
            return false;
        }

        return Hash::check($pin, $user->pos_pin);
    }

    public function verifyForApproval(
        string $userId,
        string $pin,
        string $tenantId,
        string $companyId,
        ApprovalScope $approvalScope,
    ): OperatorApprovalDecision {
        $user = $this->userRepository->findById($userId);

        if ($user === null || $user->pos_pin === null || $user->tenant_id !== $tenantId) {
            return OperatorApprovalDecision::ScopeMismatch;
        }

        $hasCompanyScope = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->exists();

        if (! $hasCompanyScope) {
            return OperatorApprovalDecision::ScopeMismatch;
        }

        if (! $user->hasPermissionTo($approvalScope->permissionName())) {
            return OperatorApprovalDecision::PermissionDenied;
        }

        if (! Hash::check($pin, $user->pos_pin)) {
            return OperatorApprovalDecision::InvalidPin;
        }

        return OperatorApprovalDecision::Approved;
    }
}
