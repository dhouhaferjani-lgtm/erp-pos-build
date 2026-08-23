<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Application\Services\CompanyFiscalIdentityService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\CompanyHashChain;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\HashChainType;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\Services\CompanyTaxStatusValidationService;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Company\Presentation\Requests\CreateCompanyRequest;
use App\Modules\Company\Presentation\Requests\UpdateCompanyRequest;
use App\Modules\Company\Presentation\Requests\UpdateReceiptSettingsRequest;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Expense\Application\Services\ExpenseCategoryProvisioningService;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    /**
     * Fixed decimal scale for percentage fields. Percentages are NOT monetary
     * values and must never inherit the currency scale (which can be 0 for
     * currencies like JPY, truncating fractional percents).
     */
    private const PERCENT_SCALE = 2;

    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly CompanyTaxStatusValidationService $taxStatusValidationService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CompanyTaxProvisioningService $companyTaxProvisioning,
        private readonly ExpenseCategoryProvisioningService $expenseCategoryProvisioning,
        private readonly CompanyFiscalIdentityService $companyFiscalIdentityService,
        private readonly AuditService $auditService,
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
     * - Seeds the country's default expense categories
     */
    public function store(CreateCompanyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = $user->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $company = DB::transaction(function () use ($tenantId, $user, $validated): Company {
            /** @var Tenant $tenant */
            $tenant = Tenant::findOrFail($tenantId);

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
                'tax_status' => CompanyTaxStatus::REGISTERED,
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
                'pos_stock_policy' => PosStockPolicy::defaultForVertical($tenant->vertical),
            ]);

            // 2. Create default location
            Location::create([
                'company_id' => $company->id,
                'name' => 'Main Location',
                'type' => LocationType::Shop,
                'is_default' => true,
                'is_active' => true,
                // Owner ruling B-3 / A2, 2026-08-23 — see the same flip in
                // TenantProvisioningService::provisionForRegistration(). A
                // company created inside an existing tenant gets the same
                // POS-ready Main Location as a company created at registration.
                'pos_enabled' => true,
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
            $this->chartOfAccountsService->seedForCompany($company);

            // 5.5. Seed the country's default expense categories.
            // Gate finding I-2 (register G-3): this second-company path seeded
            // the chart but no expense categories, so every expense on a
            // non-first company fell to the GeneralExpense catch-all. Must run
            // AFTER step 5 (categories link to class-6 accounts) and is
            // idempotent (firstOrCreate). COA failure now aborts this transaction,
            // so the former chart-absent no-op branch is unreachable.
            $this->expenseCategoryProvisioning->provisionForCompany($company);

            // 6. Provision country tax configurations and set company default tax
            $this->companyTaxProvisioning->provisionForCompany($company);

            return $company;
        });

        // W-8 F-5: several NOT-NULL columns (default_target_margin,
        // default_minimum_margin, …) are supplied by DATABASE defaults and are
        // not part of the create() payload, so the in-memory model carries null
        // for them. Re-read the committed row before serializing, otherwise the
        // response either 500s or reports nulls the database does not hold.
        $company->refresh();

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

        $fiscalIdentityAttributes = [];
        foreach (['legal_name', 'tax_id', 'registration_number', 'vat_number'] as $attribute) {
            if (array_key_exists($attribute, $validated)) {
                $value = $validated[$attribute];
                $fiscalIdentityAttributes[$attribute] = is_string($value) ? $value : null;
            }
        }

        $fiscalIdentityChanges = $this->companyFiscalIdentityService->changedFields(
            $company,
            $fiscalIdentityAttributes,
        );

        if ($fiscalIdentityChanges !== [] && ! $user->can('settings.fiscal.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => __('company.identity.fiscal_permission_required'),
                ],
            ], 403);
        }

        // Validate tax status change if present
        if (isset($validated['tax_status'])) {
            $newStatus = CompanyTaxStatus::from($validated['tax_status']);
            $this->taxStatusValidationService->validateTaxStatusChange($company, $newStatus);
        }

        DB::transaction(function () use ($company, $fiscalIdentityChanges, $user, $validated): void {
            $company->update($validated);

            if ($fiscalIdentityChanges !== []) {
                $this->auditService->record(
                    companyId: $company->id,
                    userId: $user->id,
                    eventType: 'company.fiscal_identity_updated',
                    aggregateType: 'company',
                    aggregateId: $company->id,
                    payload: ['changes' => $fiscalIdentityChanges],
                );
            }
        });

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
                // Existing fields
                'sales_order_expiry_days' => $settings->salesOrderExpiryDays,
                'ecommerce_cart_expiry_minutes' => $settings->ecommerceCartExpiryMinutes,
                'marketplace_order_expiry_hours' => $settings->marketplaceOrderExpiryHours,
                'customer_return_expiry_days' => $settings->customerReturnExpiryDays,
                'high_value_alert_threshold' => $settings->highValueAlertThreshold,
                'inventory_count_trigger_threshold' => $settings->inventoryCountTriggerThreshold,
                'auto_reserve_on_sales_order' => $settings->autoReserveOnSalesOrder,

                // Refund-policy: return-window
                'customer_history_window_days' => $settings->customerHistoryWindowDays,
                'out_of_window_policy' => $settings->outOfWindowPolicy,

                // Refund-policy: manager override
                'manager_override_threshold_amount' => $settings->managerOverrideThresholdAmount,
                'manager_override_threshold_percent' => $settings->managerOverrideThresholdPercent,
                'manager_override_required_for_no_receipt' => $settings->managerOverrideRequiredForNoReceipt,

                // Refund-policy: destinations + proration
                'allowed_refund_destinations' => $settings->allowedRefundDestinations,
                'proration_strategy' => $settings->prorationStrategy,

                // Refund-policy: voucher defaults
                'voucher_default_expiry_days' => $settings->voucherDefaultExpiryDays,
                'voucher_transferable_default' => $settings->voucherTransferableDefault,
                'voucher_cash_refund_allowed' => $settings->voucherCashRefundAllowed,

                // Refund-policy: daily caps
                'daily_refund_cap_per_cashier' => $settings->dailyRefundCapPerCashier,
                'daily_refund_cap_override_allowed' => $settings->dailyRefundCapOverrideAllowed,

                // Refund-policy: customer-history privacy
                'customer_history_search_max_per_cashier_per_day' => $settings->customerHistorySearchMaxPerCashierPerDay,
                'customer_history_search_alert_thresholds' => $settings->customerHistorySearchAlertThresholds,

                // Refund-policy: voucher rate limits
                'voucher_lookup_per_terminal_per_day' => $settings->voucherLookupPerTerminalPerDay,
                'voucher_lookup_per_cashier_per_day' => $settings->voucherLookupPerCashierPerDay,
                'voucher_lookup_failed_per_tenant_per_hour_alert' => $settings->voucherLookupFailedPerTenantPerHourAlert,
                'voucher_lookup_failed_per_tenant_per_hour_block' => $settings->voucherLookupFailedPerTenantPerHourBlock,
                'voucher_failed_attempts_auto_void' => $settings->voucherFailedAttemptsAutoVoid,

                // Refund-policy: goodwill controls
                'goodwill_named_customer_threshold' => $settings->goodwillNamedCustomerThreshold,
                'goodwill_four_eyes_threshold' => $settings->goodwillFourEyesThreshold,
                'goodwill_daily_issuance_cap_per_user' => $settings->goodwillDailyIssuanceCapPerUser,
                'goodwill_bearer_default_off' => $settings->goodwillBearerDefaultOff,
            ],
        ]);
    }

    /**
     * Update reservation settings for a company.
     */
    public function updateReservationSettings(string $companyId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        $validated = $request->validate([
            // Existing fields
            'sales_order_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'ecommerce_cart_expiry_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'marketplace_order_expiry_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'customer_return_expiry_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'high_value_alert_threshold' => ['sometimes', 'numeric', 'min:0'],
            'inventory_count_trigger_threshold' => ['sometimes', 'numeric', 'min:0'],
            'auto_reserve_on_sales_order' => ['sometimes', 'boolean'],

            // Refund-policy: return-window
            'customer_history_window_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'out_of_window_policy' => ['sometimes', 'string', 'in:refuse,voucher_only'],

            // Refund-policy: manager override
            'manager_override_threshold_amount' => ['sometimes', 'numeric', 'min:0'],
            'manager_override_threshold_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'manager_override_required_for_no_receipt' => ['sometimes', 'boolean'],

            // Refund-policy: destinations + proration
            'allowed_refund_destinations' => ['sometimes', 'array'],
            'allowed_refund_destinations.*' => ['string', 'in:original_payment,cash,store_voucher'],
            'proration_strategy' => ['sometimes', 'string', 'in:proportional,largest_first,cashier_choice'],

            // Refund-policy: voucher defaults
            'voucher_default_expiry_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'voucher_transferable_default' => ['sometimes', 'boolean'],
            'voucher_cash_refund_allowed' => ['sometimes', 'boolean'],

            // Refund-policy: daily caps
            'daily_refund_cap_per_cashier' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'daily_refund_cap_override_allowed' => ['sometimes', 'boolean'],

            // Refund-policy: customer-history privacy
            'customer_history_search_max_per_cashier_per_day' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'customer_history_search_alert_thresholds' => ['sometimes', 'array'],
            'customer_history_search_alert_thresholds.rejected_specificity_per_hour' => ['sometimes', 'integer', 'min:0'],
            'customer_history_search_alert_thresholds.same_partner_per_day' => ['sometimes', 'integer', 'min:0'],
            'customer_history_search_alert_thresholds.cross_company_immediate' => ['sometimes', 'boolean'],

            // Refund-policy: voucher rate limits
            'voucher_lookup_per_terminal_per_day' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'voucher_lookup_per_cashier_per_day' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'voucher_lookup_failed_per_tenant_per_hour_alert' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'voucher_lookup_failed_per_tenant_per_hour_block' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'voucher_failed_attempts_auto_void' => ['sometimes', 'integer', 'min:1', 'max:100'],

            // Refund-policy: goodwill controls
            'goodwill_named_customer_threshold' => ['sometimes', 'numeric', 'min:0'],
            'goodwill_four_eyes_threshold' => ['sometimes', 'numeric', 'min:0'],
            'goodwill_daily_issuance_cap_per_user' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'goodwill_bearer_default_off' => ['sometimes', 'boolean'],
        ]);

        // Get current settings and merge with updates
        $currentSettings = $company->getReservationSettings();

        // Resolve decimal scale from the company's currency (no CompanyContext bind required)
        /** @var string $companyCurrency */
        $companyCurrency = $company->currency;
        $scale = $this->scaleResolver->getScale($companyCurrency);

        $newSettings = new ReservationSettings(
            // Existing fields
            salesOrderExpiryDays: $validated['sales_order_expiry_days'] ?? $currentSettings->salesOrderExpiryDays,
            ecommerceCartExpiryMinutes: $validated['ecommerce_cart_expiry_minutes'] ?? $currentSettings->ecommerceCartExpiryMinutes,
            marketplaceOrderExpiryHours: $validated['marketplace_order_expiry_hours'] ?? $currentSettings->marketplaceOrderExpiryHours,
            customerReturnExpiryDays: $validated['customer_return_expiry_days'] ?? $currentSettings->customerReturnExpiryDays,
            highValueAlertThreshold: $validated['high_value_alert_threshold'] ?? $currentSettings->highValueAlertThreshold,
            inventoryCountTriggerThreshold: $validated['inventory_count_trigger_threshold'] ?? $currentSettings->inventoryCountTriggerThreshold,
            autoReserveOnSalesOrder: $validated['auto_reserve_on_sales_order'] ?? $currentSettings->autoReserveOnSalesOrder,

            // Refund-policy: return-window
            customerHistoryWindowDays: $validated['customer_history_window_days'] ?? $currentSettings->customerHistoryWindowDays,
            outOfWindowPolicy: $validated['out_of_window_policy'] ?? $currentSettings->outOfWindowPolicy,

            // Refund-policy: manager override
            managerOverrideThresholdAmount: isset($validated['manager_override_threshold_amount'])
                ? CurrencyScale::bcformatStrict((string) $validated['manager_override_threshold_amount'], $scale)
                : $currentSettings->managerOverrideThresholdAmount,
            // Percentage — NOT a monetary value, so it must NOT inherit the currency
            // scale (a 0-decimal currency like JPY would truncate 10.5 → 10). Fixed
            // 2-decimal scale per the percent field type.
            managerOverrideThresholdPercent: isset($validated['manager_override_threshold_percent'])
                ? CurrencyScale::bcformatStrict((string) $validated['manager_override_threshold_percent'], self::PERCENT_SCALE)
                : $currentSettings->managerOverrideThresholdPercent,
            managerOverrideRequiredForNoReceipt: $validated['manager_override_required_for_no_receipt'] ?? $currentSettings->managerOverrideRequiredForNoReceipt,

            // Refund-policy: destinations + proration
            allowedRefundDestinations: $validated['allowed_refund_destinations'] ?? $currentSettings->allowedRefundDestinations,
            prorationStrategy: $validated['proration_strategy'] ?? $currentSettings->prorationStrategy,

            // Refund-policy: voucher defaults
            voucherDefaultExpiryDays: $validated['voucher_default_expiry_days'] ?? $currentSettings->voucherDefaultExpiryDays,
            voucherTransferableDefault: $validated['voucher_transferable_default'] ?? $currentSettings->voucherTransferableDefault,
            voucherCashRefundAllowed: $validated['voucher_cash_refund_allowed'] ?? $currentSettings->voucherCashRefundAllowed,

            // Refund-policy: daily caps
            dailyRefundCapPerCashier: array_key_exists('daily_refund_cap_per_cashier', $validated)
                ? (isset($validated['daily_refund_cap_per_cashier'])
                    ? CurrencyScale::bcformatStrict((string) $validated['daily_refund_cap_per_cashier'], $scale)
                    : null)
                : $currentSettings->dailyRefundCapPerCashier,
            dailyRefundCapOverrideAllowed: $validated['daily_refund_cap_override_allowed'] ?? $currentSettings->dailyRefundCapOverrideAllowed,

            // Refund-policy: customer-history privacy
            customerHistorySearchMaxPerCashierPerDay: $validated['customer_history_search_max_per_cashier_per_day'] ?? $currentSettings->customerHistorySearchMaxPerCashierPerDay,
            customerHistorySearchAlertThresholds: isset($validated['customer_history_search_alert_thresholds'])
                ? [
                    'rejected_specificity_per_hour' => (int) ($validated['customer_history_search_alert_thresholds']['rejected_specificity_per_hour'] ?? $currentSettings->customerHistorySearchAlertThresholds['rejected_specificity_per_hour']),
                    'same_partner_per_day' => (int) ($validated['customer_history_search_alert_thresholds']['same_partner_per_day'] ?? $currentSettings->customerHistorySearchAlertThresholds['same_partner_per_day']),
                    'cross_company_immediate' => (bool) ($validated['customer_history_search_alert_thresholds']['cross_company_immediate'] ?? $currentSettings->customerHistorySearchAlertThresholds['cross_company_immediate']),
                ]
                : $currentSettings->customerHistorySearchAlertThresholds,

            // Refund-policy: voucher rate limits
            voucherLookupPerTerminalPerDay: $validated['voucher_lookup_per_terminal_per_day'] ?? $currentSettings->voucherLookupPerTerminalPerDay,
            voucherLookupPerCashierPerDay: $validated['voucher_lookup_per_cashier_per_day'] ?? $currentSettings->voucherLookupPerCashierPerDay,
            voucherLookupFailedPerTenantPerHourAlert: $validated['voucher_lookup_failed_per_tenant_per_hour_alert'] ?? $currentSettings->voucherLookupFailedPerTenantPerHourAlert,
            voucherLookupFailedPerTenantPerHourBlock: $validated['voucher_lookup_failed_per_tenant_per_hour_block'] ?? $currentSettings->voucherLookupFailedPerTenantPerHourBlock,
            voucherFailedAttemptsAutoVoid: $validated['voucher_failed_attempts_auto_void'] ?? $currentSettings->voucherFailedAttemptsAutoVoid,

            // Refund-policy: goodwill controls
            goodwillNamedCustomerThreshold: isset($validated['goodwill_named_customer_threshold'])
                ? CurrencyScale::bcformatStrict((string) $validated['goodwill_named_customer_threshold'], $scale)
                : $currentSettings->goodwillNamedCustomerThreshold,
            goodwillFourEyesThreshold: isset($validated['goodwill_four_eyes_threshold'])
                ? CurrencyScale::bcformatStrict((string) $validated['goodwill_four_eyes_threshold'], $scale)
                : $currentSettings->goodwillFourEyesThreshold,
            goodwillDailyIssuanceCapPerUser: array_key_exists('goodwill_daily_issuance_cap_per_user', $validated)
                ? (isset($validated['goodwill_daily_issuance_cap_per_user'])
                    ? CurrencyScale::bcformatStrict((string) $validated['goodwill_daily_issuance_cap_per_user'], $scale)
                    : null)
                : $currentSettings->goodwillDailyIssuanceCapPerUser,
            goodwillBearerDefaultOff: $validated['goodwill_bearer_default_off'] ?? $currentSettings->goodwillBearerDefaultOff,
        );

        $company->update([
            'reservation_settings' => $newSettings->toArray(),
        ]);

        return response()->json([
            'data' => [
                // Existing fields
                'sales_order_expiry_days' => $newSettings->salesOrderExpiryDays,
                'ecommerce_cart_expiry_minutes' => $newSettings->ecommerceCartExpiryMinutes,
                'marketplace_order_expiry_hours' => $newSettings->marketplaceOrderExpiryHours,
                'customer_return_expiry_days' => $newSettings->customerReturnExpiryDays,
                'high_value_alert_threshold' => $newSettings->highValueAlertThreshold,
                'inventory_count_trigger_threshold' => $newSettings->inventoryCountTriggerThreshold,
                'auto_reserve_on_sales_order' => $newSettings->autoReserveOnSalesOrder,

                // Refund-policy: return-window
                'customer_history_window_days' => $newSettings->customerHistoryWindowDays,
                'out_of_window_policy' => $newSettings->outOfWindowPolicy,

                // Refund-policy: manager override
                'manager_override_threshold_amount' => $newSettings->managerOverrideThresholdAmount,
                'manager_override_threshold_percent' => $newSettings->managerOverrideThresholdPercent,
                'manager_override_required_for_no_receipt' => $newSettings->managerOverrideRequiredForNoReceipt,

                // Refund-policy: destinations + proration
                'allowed_refund_destinations' => $newSettings->allowedRefundDestinations,
                'proration_strategy' => $newSettings->prorationStrategy,

                // Refund-policy: voucher defaults
                'voucher_default_expiry_days' => $newSettings->voucherDefaultExpiryDays,
                'voucher_transferable_default' => $newSettings->voucherTransferableDefault,
                'voucher_cash_refund_allowed' => $newSettings->voucherCashRefundAllowed,

                // Refund-policy: daily caps
                'daily_refund_cap_per_cashier' => $newSettings->dailyRefundCapPerCashier,
                'daily_refund_cap_override_allowed' => $newSettings->dailyRefundCapOverrideAllowed,

                // Refund-policy: customer-history privacy
                'customer_history_search_max_per_cashier_per_day' => $newSettings->customerHistorySearchMaxPerCashierPerDay,
                'customer_history_search_alert_thresholds' => $newSettings->customerHistorySearchAlertThresholds,

                // Refund-policy: voucher rate limits
                'voucher_lookup_per_terminal_per_day' => $newSettings->voucherLookupPerTerminalPerDay,
                'voucher_lookup_per_cashier_per_day' => $newSettings->voucherLookupPerCashierPerDay,
                'voucher_lookup_failed_per_tenant_per_hour_alert' => $newSettings->voucherLookupFailedPerTenantPerHourAlert,
                'voucher_lookup_failed_per_tenant_per_hour_block' => $newSettings->voucherLookupFailedPerTenantPerHourBlock,
                'voucher_failed_attempts_auto_void' => $newSettings->voucherFailedAttemptsAutoVoid,

                // Refund-policy: goodwill controls
                'goodwill_named_customer_threshold' => $newSettings->goodwillNamedCustomerThreshold,
                'goodwill_four_eyes_threshold' => $newSettings->goodwillFourEyesThreshold,
                'goodwill_daily_issuance_cap_per_user' => $newSettings->goodwillDailyIssuanceCapPerUser,
                'goodwill_bearer_default_off' => $newSettings->goodwillBearerDefaultOff,
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
                'receipt_header' => $company->receipt_header,
                'receipt_footer' => $company->receipt_footer,
                'receipt_thank_you' => $company->receipt_thank_you,
                'receipt_show_vat_breakdown' => $company->receipt_show_vat_breakdown,
                'receipt_show_fiscal_info' => $company->receipt_show_fiscal_info,
                'receipt_show_payment_details' => $company->receipt_show_payment_details,
                'receipt_show_customer' => $company->receipt_show_customer,
            ],
        ]);
    }

    /**
     * Update receipt customization settings for the company.
     */
    public function updateReceiptSettings(UpdateReceiptSettingsRequest $request, string $companyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $company = Company::where('tenant_id', $user->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $company->update($validated);

        return response()->json([
            'data' => [
                'auto_print_receipts' => $company->auto_print_receipts,
                'receipt_logo' => $company->receipt_logo,
                'receipt_header' => $company->receipt_header,
                'receipt_footer' => $company->receipt_footer,
                'receipt_thank_you' => $company->receipt_thank_you,
                'receipt_show_vat_breakdown' => $company->receipt_show_vat_breakdown,
                'receipt_show_fiscal_info' => $company->receipt_show_fiscal_info,
                'receipt_show_payment_details' => $company->receipt_show_payment_details,
                'receipt_show_customer' => $company->receipt_show_customer,
            ],
            'message' => 'Receipt settings updated successfully',
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
            // W-8 F-5: these two columns are NOT NULL with a DATABASE default, so
            // they are `string` here — but ONLY on a model that has actually been
            // read back from the database. Callers must never hand this formatter
            // a freshly created, unrefreshed model: `(string) null` is `""`, which
            // defeats bcformatOrNull()'s null guard and makes bcformatStrict()
            // throw. store() refreshes for exactly this reason.
            'default_target_margin' => CurrencyScale::bcformatOrNull(
                (string) $company->default_target_margin,
                2
            ),
            'default_minimum_margin' => CurrencyScale::bcformatOrNull(
                (string) $company->default_minimum_margin,
                2
            ),
            'default_max_discount_percent' => CurrencyScale::bcformatOrNull(
                $company->default_max_discount_percent !== null ? (string) $company->default_max_discount_percent : null,
                2
            ),
            'discount_floor_mode' => $company->discount_floor_mode->value,
            'price_entry_mode' => $company->price_entry_mode->value,
            'created_at' => $company->created_at->toIso8601String(),
            'updated_at' => $company->updated_at->toIso8601String(),
        ];
    }
}
