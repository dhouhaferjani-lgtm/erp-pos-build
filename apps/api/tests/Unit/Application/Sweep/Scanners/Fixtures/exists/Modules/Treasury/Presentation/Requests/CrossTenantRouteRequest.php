<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Treasury\Presentation\Requests;

/**
 * Scanner fixture (skip case). #[CrossTenantRoute] on the rules() method
 * means the scanner must skip the bare-exists rule inside.
 *
 * The attribute import path is intentionally bogus — the visitor matches
 * on attribute short-name, not on a resolvable class. We don't even need
 * `use App\Shared\Architecture\CrossTenantRoute;` for the visitor to
 * recognize it.
 */
class CrossTenantRouteRequest
{
    /**
     * @return array<string, mixed>
     */
    #[\CrossTenantRoute(reason: 'super-admin route, intentional cross-tenant lookup')]
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
        ];
    }
}
