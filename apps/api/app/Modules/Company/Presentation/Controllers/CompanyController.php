<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\CompanyHashChain;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\HashChainType;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\Services\CompanyTaxStatusValidationService;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Presentation\Requests\CreateCompanyRequest;
use App\Modules\Company\Presentation\Requests\UpdateCompanyRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly CompanyTaxStatusValidationService $taxStatusValidationService,
    ) {}

    /**
     * Create a new company.
     *
     * This endpoint:
     * - Creates the company with the provided details
     * - Creates a default location for the company
     * - Creates a UserCompanyMembership with 'owner' role for the creating user
     * - Initializes hash chains for all fiscal document types
     * - Seeds chart of accounts based on country
     */
    public function store(CreateCompanyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = $user->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $company = DB::transaction(function () use ($tenantId, $user, $validated): Company {
            // 1. Create the company
            $company = Company::create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'legal_name' => $validated['legal_name'] ?? null,
                'country_code' => $validated['country_code'],
                'currency' => $validated['currency'],
                'locale' => $validated['locale'],
                'timezone' => $validated['timezone'],
                'tax_id' => $validated['tax_id'] ?? null,
                'registration_number' => $validated['registration_number'] ?? null,
                'vat_number' => $validated['vat_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'website' => $validated['website'] ?? null,
                'address_street' => $validated['address_street'] ?? null,
                'address_street_2' => $validated['address_street_2'] ?? null,
                'address_city' => $validated['address_city'] ?? null,
                'address_state' => $validated['address_state'] ?? null,
                'address_postal_code' => $validated['address_postal_code'] ?? null,
                'status' => CompanyStatus::Active,
                'fiscal_year_start_month' => 1,
                'date_format' => 'Y-m-d',
                'invoice_prefix' => 'INV-',
                'invoice_next_number' => 1,
                'quote_prefix' => 'QT-',
                'quote_next_number' => 1,
                'sales_order_prefix' => 'SO-',
                'sales_order_next_number' => 1,
                'purchase_order_prefix' => 'PO-',
                'purchase_order_next_number' => 1,
                'delivery_note_prefix' => 'DN-',
                'delivery_note_next_number' => 1,
                'receipt_prefix' => 'REC-',
                'receipt_next_number' => 1,
                'is_headquarters' => true,
            ]);

            // 2. Create default location
            Location::create([
                'company_id' => $company->id,
                'name' => 'Main Location',
                'type' => LocationType::Shop,
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,
                'address_country' => $validated['country_code'],
                'address_street' => $validated['address_street'] ?? null,
                'address_city' => $validated['address_city'] ?? null,
                'address_postal_code' => $validated['address_postal_code'] ?? null,
            ]);

            // 3. Create owner membership for the creating user
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
                'is_primary' => UserCompanyMembership::where('user_id', $user->id)->count() === 0,
                'accepted_at' => now(),
            ]);

            // 4. Initialize hash chains for all fiscal document types
            foreach (HashChainType::cases() as $chainType) {
                $genesisHash = hash('sha256', $company->id.'|'.$chainType->value.'|genesis');
                CompanyHashChain::create([
                    'company_id' => $company->id,
                    'chain_type' => $chainType,
                    'sequence_number' => 0,
                    'hash' => $genesisHash,
                    'previous_hash' => null,
                    'document_id' => null,
                    'document_type' => 'genesis',
                    'payload_hash' => hash('sha256', 'genesis'),
                ]);
            }

            // 5. Seed chart of accounts based on country
            try {
                $this->chartOfAccountsService->seedForCompany($company);
            } catch (\RuntimeException $e) {
                // Log warning but don't fail company creation
                // Country might not have a seeder yet
                Log::warning("Could not seed COA for company {$company->id}: ".$e->getMessage());
            }

            return $company;
        });

        return response()->json([
            'data' => $this->formatCompany($company),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Get a specific company.
     */
    public function show(string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        return response()->json([
            'data' => $this->formatCompany($company),
        ]);
    }

    /**
     * Update a company.
     */
    public function update(UpdateCompanyRequest $request, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Validate tax status change if present
        if (isset($validated['tax_status'])) {
            $newStatus = CompanyTaxStatus::from($validated['tax_status']);
            $this->taxStatusValidationService->validateTaxStatusChange($company, $newStatus);
        }

        $company->update($validated);

        return response()->json([
            'data' => $this->formatCompany($company),
            'message' => 'Company updated successfully',
        ]);
    }

    /**
     * Get reservation settings for a company.
     */
    public function getReservationSettings(string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        $settings = $company->getReservationSettings();

        return response()->json([
            'data' => [
                'sales_order_expiry_days' => $settings->salesOrderExpiryDays,
                'ecommerce_cart_expiry_minutes' => $settings->ecommerceCartExpiryMinutes,
                'marketplace_order_expiry_hours' => $settings->marketplaceOrderExpiryHours,
                'customer_return_expiry_days' => $settings->customerReturnExpiryDays,
                'high_value_alert_threshold' => $settings->highValueAlertThreshold,
                'inventory_count_trigger_threshold' => $settings->inventoryCountTriggerThreshold,
                'auto_reserve_on_sales_order' => $settings->autoReserveOnSalesOrder,
            ],
        ]);
    }

    /**
     * Update reservation settings for a company.
     */
    public function updateReservationSettings(string $companyId, \Illuminate\Http\Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        $validated = $request->validate([
            'sales_order_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'ecommerce_cart_expiry_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'marketplace_order_expiry_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'customer_return_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'high_value_alert_threshold' => ['sometimes', 'numeric', 'min:0'],
            'inventory_count_trigger_threshold' => ['sometimes', 'numeric', 'min:0'],
            'auto_reserve_on_sales_order' => ['sometimes', 'boolean'],
        ]);

        // Get current settings and merge with updates
        $currentSettings = $company->getReservationSettings();

        $newSettings = new \App\Modules\Company\Domain\ValueObjects\ReservationSettings(
            salesOrderExpiryDays: $validated['sales_order_expiry_days'] ?? $currentSettings->salesOrderExpiryDays,
            ecommerceCartExpiryMinutes: $validated['ecommerce_cart_expiry_minutes'] ?? $currentSettings->ecommerceCartExpiryMinutes,
            marketplaceOrderExpiryHours: $validated['marketplace_order_expiry_hours'] ?? $currentSettings->marketplaceOrderExpiryHours,
            customerReturnExpiryDays: $validated['customer_return_expiry_days'] ?? $currentSettings->customerReturnExpiryDays,
            highValueAlertThreshold: $validated['high_value_alert_threshold'] ?? $currentSettings->highValueAlertThreshold,
            inventoryCountTriggerThreshold: $validated['inventory_count_trigger_threshold'] ?? $currentSettings->inventoryCountTriggerThreshold,
            autoReserveOnSalesOrder: $validated['auto_reserve_on_sales_order'] ?? $currentSettings->autoReserveOnSalesOrder,
        );

        $company->update([
            'reservation_settings' => $newSettings->toArray(),
        ]);

        return response()->json([
            'data' => [
                'sales_order_expiry_days' => $newSettings->salesOrderExpiryDays,
                'ecommerce_cart_expiry_minutes' => $newSettings->ecommerceCartExpiryMinutes,
                'marketplace_order_expiry_hours' => $newSettings->marketplaceOrderExpiryHours,
                'customer_return_expiry_days' => $newSettings->customerReturnExpiryDays,
                'high_value_alert_threshold' => $newSettings->highValueAlertThreshold,
                'inventory_count_trigger_threshold' => $newSettings->inventoryCountTriggerThreshold,
                'auto_reserve_on_sales_order' => $newSettings->autoReserveOnSalesOrder,
            ],
            'message' => 'Reservation settings updated successfully',
        ]);
    }

    /**
     * Get POS settings for the company.
     */
    public function getPOSSettings(string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'auto_print_receipts' => $company->auto_print_receipts,
                'receipt_logo' => $company->receipt_logo,
                'receipt_footer' => $company->receipt_footer,
            ],
        ]);
    }

    /**
     * Format company data for response.
     *
     * @return array<string, mixed>
     */
    private function formatCompany(Company $company): array
    {
        return [
            'id' => $company->id,
            'tenant_id' => $company->tenant_id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'code' => $company->code,
            'country_code' => $company->country_code,
            'tax_id' => $company->tax_id,
            'registration_number' => $company->registration_number,
            'vat_number' => $company->vat_number,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'address_street' => $company->address_street,
            'address_street_2' => $company->address_street_2,
            'address_city' => $company->address_city,
            'address_state' => $company->address_state,
            'address_postal_code' => $company->address_postal_code,
            'currency' => $company->currency,
            'locale' => $company->locale,
            'timezone' => $company->timezone,
            'status' => $company->status->value,
            'default_tax_rate' => $company->default_tax_rate,
            'default_tax_configuration_id' => $company->default_tax_configuration_id,
            'tax_status' => $company->tax_status->value,
            'created_at' => $company->created_at->toIso8601String(),
            'updated_at' => $company->updated_at->toIso8601String(),
        ];
    }
}
