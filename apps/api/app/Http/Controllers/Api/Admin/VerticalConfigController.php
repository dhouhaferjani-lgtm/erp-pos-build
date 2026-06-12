<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ModuleName;
use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\VerticalConfig;
use App\Services\AdminAuditService;
use App\Services\CompanyConfigService;
use App\Services\VerticalConfigService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin management of per-vertical module lists.
 *
 * Effective values are DB-first (central vertical_configs overrides) with
 * per-field fallback to config/verticals.php, resolved by
 * VerticalConfigService. Writes are validated against the ModuleName enum
 * at this boundary (T1 review hard requirement) and audit-logged.
 */
class VerticalConfigController extends Controller
{
    public function __construct(
        private readonly VerticalConfigService $verticalConfigService,
        private readonly CompanyConfigService $companyConfigService,
        private readonly AdminAuditService $auditService
    ) {}

    /**
     * List every vertical with its effective module configuration.
     */
    #[CrossTenantRoute(reason: 'Super-admin vertical-config panel: reads central vertical_configs overrides (fleet-wide, not tenant data) to render effective module lists per vertical; mounted under auth:sanctum-admin + super_admin (EnsureSuperAdmin).')]
    public function index(): JsonResponse
    {
        /** @var array<int, string> $overriddenVerticals */
        $overriddenVerticals = VerticalConfig::query()
            ->pluck('vertical')
            ->all();

        $data = array_map(
            fn (Vertical $vertical): array => $this->verticalItem(
                $vertical,
                in_array($vertical->value, $overriddenVerticals, true)
            ),
            Vertical::cases()
        );

        return response()->json([
            'data' => $data,
            'available_modules' => ModuleName::values(),
        ]);
    }

    /**
     * Upsert the central module-list override for a vertical.
     */
    #[CrossTenantRoute(reason: 'Super-admin vertical-config write: upserts the central vertical_configs override and busts tenant_config caches for every tenant of that vertical (central tenant-directory query, id column only); audit-logged via AdminAuditService; mounted under auth:sanctum-admin + super_admin (EnsureSuperAdmin).')]
    public function update(Request $request, string $vertical): JsonResponse
    {
        $verticalEnum = Vertical::tryFrom($vertical);

        if ($verticalEnum === null) {
            return response()->json([
                'error' => "Unknown vertical '{$vertical}'. Valid values: "
                    .implode(', ', Vertical::values()),
                'valid_verticals' => Vertical::values(),
            ], 422);
        }

        $request->validate([
            'default_modules' => ['present', 'array'],
            'default_modules.*' => ['required', 'string'],
            'compatible_extras' => ['present', 'array'],
            'compatible_extras.*' => ['required', 'string'],
        ]);

        /** @var array<int, string> $defaultModules */
        $defaultModules = $request->input('default_modules');
        /** @var array<int, string> $compatibleExtras */
        $compatibleExtras = $request->input('compatible_extras');

        // DB write boundary must enforce the ModuleName enum (T1 review):
        // an unknown module name silently fails-closed in the frontend gate.
        $invalidModules = array_values(array_filter(
            array_unique(array_merge($defaultModules, $compatibleExtras)),
            static fn (string $module): bool => ModuleName::tryFrom($module) === null
        ));

        if ($invalidModules !== []) {
            return response()->json([
                'error' => 'Invalid module names: '.implode(', ', $invalidModules),
                'valid_modules' => ModuleName::values(),
            ], 422);
        }

        $oldValues = [
            'default_modules' => $this->verticalConfigService->getDefaultModules($verticalEnum),
            'compatible_extras' => $this->verticalConfigService->getCompatibleExtras($verticalEnum),
        ];

        VerticalConfig::query()->updateOrCreate(
            ['vertical' => $verticalEnum->value],
            [
                'default_modules' => $defaultModules,
                'compatible_extras' => $compatibleExtras,
            ]
        );

        // The VerticalConfigObserver already busts the per-vertical override
        // cache on save; this explicit call keeps the controller correct even
        // if the observer registration ever changes (defense in depth).
        $this->verticalConfigService->invalidateVertical($verticalEnum);

        // Every tenant on this vertical caches its merged config for 24h —
        // bust those so the change is visible immediately, not after TTL
        // expiry. CompanyConfigService owns the key and the tenant fanout.
        $this->companyConfigService->invalidateForVertical($verticalEnum);

        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->log(
            admin: $admin,
            action: 'update_vertical_config',
            entityType: 'vertical_config',
            entityId: $verticalEnum->value,
            oldValues: $oldValues,
            newValues: [
                'default_modules' => $defaultModules,
                'compatible_extras' => $compatibleExtras,
            ],
            notes: 'Vertical module configuration updated by admin'
        );

        return response()->json([
            'data' => $this->verticalItem($verticalEnum, true),
        ]);
    }

    /**
     * Build the per-vertical item shape shared by index() and update().
     *
     * @return array{vertical: string, label: string, product: string, default_modules: array<int, string>, compatible_extras: array<int, string>, is_overridden: bool}
     */
    private function verticalItem(Vertical $vertical, bool $isOverridden): array
    {
        return [
            'vertical' => $vertical->value,
            'label' => $this->verticalConfigService->getLabel($vertical),
            'product' => $this->verticalConfigService->getProduct($vertical),
            'default_modules' => $this->verticalConfigService->getDefaultModules($vertical),
            'compatible_extras' => $this->verticalConfigService->getCompatibleExtras($vertical),
            'is_overridden' => $isOverridden,
        ];
    }
}
