<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Controller for listing users authorized to approve variance close.
 *
 * GET /api/v1/pos/authorized-managers
 *
 * Returns all users in the current company who hold the
 * `pos.close_shift_with_variance` permission. The POS terminal uses this list
 * to populate the manager-PIN panel when a cashier exceeds the hard variance
 * threshold at shift close.
 *
 * No additional gate beyond authentication — any authenticated POS user may
 * read the list of managers (names + IDs only; no PINs are exposed).
 */
final class AuthorizedManagersController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var Collection<int, User> $users */
        $users = User::query()
            // Offboarding belt: the ACCOUNT must be active too, not just the
            // membership. A deactivated user whose membership row is stale (or
            // hand-edited) must never be offered as an override approver.
            ->where('status', UserStatus::Active->value)
            ->whereHas('companyMemberships', static function (Builder $q) use ($companyId): void {
                $q->whereRaw('company_id = ?', [$companyId])
                    ->whereRaw('status = ?', [MembershipStatus::Active->value]);
            })
            ->permission('pos.close_shift_with_variance')
            ->select('id', 'name')
            ->get();

        return response()->json([
            'data' => $users->map(static fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
            ])->all(),
        ]);
    }
}
