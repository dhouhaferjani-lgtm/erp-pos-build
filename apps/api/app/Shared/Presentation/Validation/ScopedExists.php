<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Tenant-scoped exists validation rule factory for FormRequests.
 *
 * Replaces bare `exists:<table>,<column>` validator strings throughout
 * the Presentation tier so cross-tenant ids cannot satisfy a foreign-key
 * validation. Three factories cover the three valid scoping patterns
 * defined in the master plan; cross-tenant access requires
 * `#[CrossTenantRoute]` on the controller method (Section 2).
 *
 * Pure factory by design — never calls auth() / app() / service location.
 * Callers obtain tenantId / companyId from CompanyContext (or auth) and
 * pass them in explicitly. See UpdateCouponRequest for the canonical
 * FormRequest injection pattern.
 */
final class ScopedExists
{
    private function __construct() {}

    public static function tenantAndCompany(
        string $table,
        string $tenantId,
        string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);
    }

    public static function tenant(
        string $table,
        string $tenantId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId);
    }

    public static function company(
        string $table,
        string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('company_id', $companyId);
    }
}
