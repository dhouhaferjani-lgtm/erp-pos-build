<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Concerns;

use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Concern: refuse the request if the authenticated user has no
 * `UserCompanyMembership` row for the URL `{companyId}` route segment.
 *
 * api.accounting (round-2 Codex remediation): `CompanyContextMiddleware`
 * validates the `X-Company-Id` header (or falls back to the user's first
 * membership), but Accounting controllers historically routed `{companyId}`
 * via the URL and passed it straight to services that scoped by company_id
 * only. A tenant-A user could therefore hit `/api/v1/companies/{tenant-B}/...`
 * with a sensible `X-Company-Id` and the company-only service scope would
 * happily resolve tenant-B data.
 *
 * This trait closes the route-driven path: every action method that takes
 * `{companyId}` from the URL calls `assertCompanyAccess($request, $companyId)`
 * before invoking the service. The check returns 404 (not 403) to avoid
 * disclosing that the company exists in some other tenant.
 *
 * Used by:
 * - PartnerBalanceController (round-2 fix at 8b464720)
 * - AccountPurposeController (round-3 fix)
 * - OpeningBalanceBatchController (round-3 fix)
 */
trait RequiresCompanyAccess
{
    protected function assertCompanyAccess(Request $request, string $companyId): void
    {
        /** @var User $user */
        $user = $request->user();

        // FU-2a: require an ACTIVE membership, not mere existence — a
        // suspended/revoked member must not retain route-driven company access.
        $hasAccess = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        if (! $hasAccess) {
            throw new NotFoundHttpException('Company not found.');
        }
    }
}
