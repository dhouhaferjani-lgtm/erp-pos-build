<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Scanners;

use App\Application\Sweep\Scanners\ClusterResolver;
use Tests\TestCase;

/**
 * Verifies ClusterResolver fallback semantics. Codex Phase 1 review #2
 * flagged that unmatched modules silently fell back to api.identity-company,
 * misclassifying real modules (BatchExpiry, Expense, Product, …). The
 * resolver now maps unmatched paths to a synthetic api.unmapped cluster,
 * which is visible in the inventory and surfaces in the generate command's
 * stderr warning so triage can re-classify them.
 */
class ClusterResolverTest extends TestCase
{
    public function test_known_module_resolves_to_its_cluster(): void
    {
        $resolver = new ClusterResolver(ClusterResolver::defaultModuleToClusterMap(), 'api.unmapped');

        $this->assertSame(
            'api.treasury',
            $resolver->resolve('apps/api/app/Modules/Treasury/Presentation/Requests/Foo.php'),
        );
    }

    public function test_unknown_module_falls_back_to_api_unmapped_not_identity_company(): void
    {
        // Codex Phase 1 review #2: api.identity-company was the silent
        // fallback, which silently misclassified real modules. The default
        // resolver must now route to api.unmapped instead.
        $resolver = new ClusterResolver(ClusterResolver::defaultModuleToClusterMap(), 'api.unmapped');

        $this->assertSame(
            'api.unmapped',
            $resolver->resolve('apps/api/app/Modules/Wibble/Presentation/Foo.php'),
        );
    }

    public function test_path_without_modules_segment_falls_back(): void
    {
        $resolver = new ClusterResolver(ClusterResolver::defaultModuleToClusterMap(), 'api.unmapped');

        $this->assertSame(
            'api.unmapped',
            $resolver->resolve('apps/api/app/Http/Controllers/Foo.php'),
        );
    }

    public function test_default_fallback_constant_is_api_unmapped(): void
    {
        $this->assertSame('api.unmapped', ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID);
    }

    /**
     * Triage round added in 2026-05-04: 5 modules previously routed to the
     * api.unmapped fallback are now mapped to existing clusters per master
     * plan Section 6 adjacency rules. Pin each so a regression cannot silently
     * undo the triage.
     *
     * @dataProvider provideTriagedModuleMappings
     */
    public function test_triaged_modules_resolve_to_their_assigned_cluster(string $modulePath, string $expectedClusterId): void
    {
        $resolver = new ClusterResolver(ClusterResolver::defaultModuleToClusterMap(), 'api.unmapped');

        $this->assertSame($expectedClusterId, $resolver->resolve($modulePath));
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    public static function provideTriagedModuleMappings(): iterable
    {
        yield 'BatchExpiry → api.inventory' => [
            'apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php',
            'api.inventory',
        ];
        yield 'Expense → api.accounting' => [
            'apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseCategoryController.php',
            'api.accounting',
        ];
        yield 'Coupon → api.pricing' => [
            'apps/api/app/Modules/Coupon/Application/Services/CouponApplicationService.php',
            'api.pricing',
        ];
        yield 'Product → api.catalog' => [
            'apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php',
            'api.catalog',
        ];
        yield 'Billing → api.super-admin-context (super-admin route group)' => [
            'apps/api/app/Modules/Billing/Presentation/Controllers/AdminBillingController.php',
            'api.super-admin-context',
        ];
        yield 'Marketplace → api.marketplace (added 2026-05-09 — own cluster, not folded into platform-integration)' => [
            'apps/api/app/Modules/Marketplace/Application/Listeners/SyncListingOnPriceChange.php',
            'api.marketplace',
        ];
        yield 'Marketplace controllers → api.marketplace (forward-looking pin for the rest of the module surface)' => [
            'apps/api/app/Modules/Marketplace/Presentation/Controllers/MarketplaceListingController.php',
            'api.marketplace',
        ];
    }
}
