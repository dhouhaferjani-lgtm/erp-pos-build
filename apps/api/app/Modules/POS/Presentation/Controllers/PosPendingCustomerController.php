<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\POS\Presentation\Resources\PosCustomerAliasResource;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class PosPendingCustomerController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();
        $payload = $this->validatePayload($request);
        $clientCustomerUuid = $payload['client_customer_uuid'];

        $crossCompanyAlias = PosCustomerAlias::query()
            ->where('tenant_id', $tenantId)
            ->where('client_customer_uuid', $clientCustomerUuid)
            ->where('company_id', '<>', $companyId)
            ->first();

        if ($crossCompanyAlias instanceof PosCustomerAlias) {
            return response()->json([
                'error' => [
                    'code' => 'POS_CUSTOMER_ALIAS_COMPANY_CONFLICT',
                    'message' => 'The pending customer UUID is already resolved for another company.',
                ],
            ], 409);
        }

        $existingAlias = PosCustomerAlias::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('client_customer_uuid', $clientCustomerUuid)
            ->first();

        if ($existingAlias instanceof PosCustomerAlias) {
            $partner = $this->findScopedPartner($tenantId, $companyId, $existingAlias->server_partner_id);

            if (! $partner instanceof Partner) {
                return response()->json([
                    'error' => [
                        'code' => 'POS_CUSTOMER_ALIAS_STALE',
                        'message' => 'The pending customer alias no longer points to a customer in this company.',
                    ],
                ], 409);
            }

            return response()->json([
                'data' => (new PosCustomerAliasResource($existingAlias))->toArray($request),
            ]);
        }

        $alias = DB::transaction(function () use ($tenantId, $companyId, $clientCustomerUuid, $payload): PosCustomerAlias {
            $partner = Partner::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'type' => PartnerType::Customer,
                'name' => $payload['name'],
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
                'tax_status' => PartnerTaxStatus::NON_REGISTERED,
                'receivable_balance' => '0.0000',
                'credit_balance' => '0.0000',
                'payable_balance' => '0.0000',
                'is_active' => true,
            ]);

            return PosCustomerAlias::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'client_customer_uuid' => $clientCustomerUuid,
                'server_partner_id' => $partner->id,
            ]);
        });

        return response()->json([
            'data' => (new PosCustomerAliasResource($alias))->toArray($request),
        ], 201);
    }

    /**
     * @return array{client_customer_uuid: string, name: string, phone?: string|null, email?: string|null}
     */
    private function validatePayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'client_customer_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
        ]);

        /** @var array{client_customer_uuid: string, name: string, phone?: string|null, email?: string|null} $validated */
        $validated = $validator->validate();

        return $validated;
    }

    private function findScopedPartner(string $tenantId, string $companyId, string $partnerId): ?Partner
    {
        /** @var Partner|null $partner */
        $partner = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($partnerId)
            ->first();

        return $partner;
    }
}
