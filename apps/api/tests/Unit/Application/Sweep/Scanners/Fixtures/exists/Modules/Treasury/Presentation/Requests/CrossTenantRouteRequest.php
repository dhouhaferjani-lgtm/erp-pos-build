<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Treasury\Presentation\Requests;

use App\Shared\Architecture\CrossTenantRoute;

/**
 * Scanner fixture (skip case). #[CrossTenantRoute] on the rules() method
 * means the scanner must skip the bare-exists rule inside.
 *
 * The visitor matches on attribute short-name, but we resolve the import
 * to the production class so PHPStan doesn't flag attribute.notFound.
 */
class CrossTenantRouteRequest
{
    /**
     * @return array<string, list<string>>
     */
    #[CrossTenantRoute(reason: 'super-admin route, intentional cross-tenant lookup')]
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
        ];
    }
}
