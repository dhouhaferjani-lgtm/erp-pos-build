<?php

declare(strict_types=1);

namespace App\Shared\Architecture;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a controller method that legitimately operates across tenants.
 *
 * Architecture-test gates (Section 3 of the master plan) inspect controller
 * methods via reflection and skip tenant-scoping checks when this attribute
 * is present. The required reason becomes the audit-trail breadcrumb that
 * justifies the cross-tenant access; review and tooling read it via the
 * public readonly $reason.
 *
 * Example:
 *
 *     class SuperAdminController
 *     {
 *         #[CrossTenantRoute(reason: "Super-admin manages all tenants from one panel")]
 *         public function updateExtras(string $id, Request $request) { ... }
 *     }
 *
 * Only TARGET_METHOD — never class-level — so each cross-tenant action
 * carries its own justification and tooling can pinpoint the exact route.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class CrossTenantRoute
{
    public function __construct(
        public readonly string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'CrossTenantRoute::$reason must not be blank — every cross-tenant route needs a justification for the audit trail.',
            );
        }
    }
}
