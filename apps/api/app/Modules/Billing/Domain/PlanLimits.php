<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

/**
 * Defines all plan limit and feature keys.
 *
 * This class provides a central place to define all the limit keys
 * that can be used in plan configurations.
 */
final class PlanLimits
{
    // ========================================================================
    // RESOURCE LIMITS (integers)
    // ========================================================================

    /** Maximum number of companies per tenant */
    public const MAX_COMPANIES = 'max_companies';

    /** Maximum number of locations/warehouses per company */
    public const MAX_LOCATIONS = 'max_locations';

    /** Maximum number of users per tenant */
    public const MAX_USERS = 'max_users';

    /** Maximum number of products in catalog */
    public const MAX_PRODUCTS = 'max_products';

    /** Maximum number of partners (customers + suppliers) */
    public const MAX_PARTNERS = 'max_partners';

    /** Maximum number of documents per month */
    public const MAX_DOCUMENTS_PER_MONTH = 'max_documents_per_month';

    /** Storage quota in bytes */
    public const STORAGE_QUOTA_BYTES = 'storage_quota_bytes';

    /** API requests per hour */
    public const API_REQUESTS_PER_HOUR = 'api_requests_per_hour';

    // ========================================================================
    // USER TIER PRICING (for per-user billing)
    // ========================================================================

    /** Number of users included in base price */
    public const INCLUDED_USERS = 'included_users';

    /** Price per additional user (beyond included) */
    public const PRICE_PER_EXTRA_USER = 'price_per_extra_user';

    // ========================================================================
    // CORE MODULES (booleans)
    // ========================================================================

    /** Sales module: quotes, orders, invoices */
    public const MODULE_SALES = 'module_sales';

    /** Inventory module: stock management, movements */
    public const MODULE_INVENTORY = 'module_inventory';

    /** Treasury module: payments, cash management */
    public const MODULE_TREASURY = 'module_treasury';

    /** Basic accounting: chart of accounts, journal entries */
    public const MODULE_ACCOUNTING = 'module_accounting';

    /** Partners/CRM module: customers, suppliers */
    public const MODULE_PARTNERS = 'module_partners';

    // ========================================================================
    // ADVANCED MODULES (booleans)
    // ========================================================================

    /** Workshop module: work orders, labor tracking */
    public const MODULE_WORKSHOP = 'module_workshop';

    /** Vehicle management: VIN, service history */
    public const MODULE_VEHICLES = 'module_vehicles';

    /** Advanced reporting and analytics */
    public const MODULE_REPORTING = 'module_reporting';

    /** Multi-location inventory management */
    public const MODULE_MULTI_LOCATION = 'module_multi_location';

    /** E-commerce integration */
    public const MODULE_ECOMMERCE = 'module_ecommerce';

    /** HR and payroll (future) */
    public const MODULE_HR = 'module_hr';

    // ========================================================================
    // FEATURES (booleans)
    // ========================================================================

    /** Can create credit notes */
    public const FEATURE_CREDIT_NOTES = 'feature_credit_notes';

    /** Can create delivery notes */
    public const FEATURE_DELIVERY_NOTES = 'feature_delivery_notes';

    /** Can convert documents (quote -> invoice, etc.) */
    public const FEATURE_DOCUMENT_CONVERSION = 'feature_document_conversion';

    /** Can export to PDF */
    public const FEATURE_PDF_EXPORT = 'feature_pdf_export';

    /** Can export to Excel */
    public const FEATURE_EXCEL_EXPORT = 'feature_excel_export';

    /** Email notifications enabled */
    public const FEATURE_EMAIL_NOTIFICATIONS = 'feature_email_notifications';

    /** SMS notifications enabled */
    public const FEATURE_SMS_NOTIFICATIONS = 'feature_sms_notifications';

    /** API access enabled */
    public const FEATURE_API_ACCESS = 'feature_api_access';

    /** Webhook integrations enabled */
    public const FEATURE_WEBHOOKS = 'feature_webhooks';

    /** Custom branding (logo, colors) */
    public const FEATURE_CUSTOM_BRANDING = 'feature_custom_branding';

    /** Priority support */
    public const FEATURE_PRIORITY_SUPPORT = 'feature_priority_support';

    /** Audit trail / activity log */
    public const FEATURE_AUDIT_TRAIL = 'feature_audit_trail';

    /** Data backup and restore */
    public const FEATURE_BACKUP = 'feature_backup';

    /** Multi-currency support */
    public const FEATURE_MULTI_CURRENCY = 'feature_multi_currency';

    /** Landed cost calculation */
    public const FEATURE_LANDED_COST = 'feature_landed_cost';

    /** Margin analysis */
    public const FEATURE_MARGIN_ANALYSIS = 'feature_margin_analysis';

    /** Bank reconciliation */
    public const FEATURE_BANK_RECONCILIATION = 'feature_bank_reconciliation';

    /** Fiscal compliance (NF525, ZATCA, etc.) */
    public const FEATURE_FISCAL_COMPLIANCE = 'feature_fiscal_compliance';

    // ========================================================================
    // DEFAULT LIMITS BY PLAN TIER
    // ========================================================================

    /**
     * Get default limits for the trial plan.
     *
     * @return array<string, mixed>
     */
    public static function trial(): array
    {
        return [
            // Resource limits
            self::MAX_COMPANIES => 1,
            self::MAX_LOCATIONS => 1,
            self::MAX_USERS => 2,
            self::MAX_PRODUCTS => 50,
            self::MAX_PARTNERS => 20,
            self::MAX_DOCUMENTS_PER_MONTH => 30,
            self::STORAGE_QUOTA_BYTES => 500 * 1024 * 1024, // 500 MB
            self::API_REQUESTS_PER_HOUR => 100,

            // Core modules
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => false,
            self::MODULE_PARTNERS => true,

            // Advanced modules
            self::MODULE_WORKSHOP => false,
            self::MODULE_VEHICLES => false,
            self::MODULE_REPORTING => false,
            self::MODULE_MULTI_LOCATION => false,
            self::MODULE_ECOMMERCE => false,
            self::MODULE_HR => false,

            // Features
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => false,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => false,
            self::FEATURE_API_ACCESS => false,
            self::FEATURE_WEBHOOKS => false,
            self::FEATURE_CUSTOM_BRANDING => false,
            self::FEATURE_PRIORITY_SUPPORT => false,
            self::FEATURE_AUDIT_TRAIL => false,
            self::FEATURE_BACKUP => false,
            self::FEATURE_MULTI_CURRENCY => false,
            self::FEATURE_LANDED_COST => false,
            self::FEATURE_MARGIN_ANALYSIS => false,
            self::FEATURE_BANK_RECONCILIATION => false,
            self::FEATURE_FISCAL_COMPLIANCE => false,
        ];
    }

    /**
     * Get default limits for the starter plan.
     *
     * @return array<string, mixed>
     */
    public static function starter(): array
    {
        return [
            // Resource limits
            self::MAX_COMPANIES => 1,
            self::MAX_LOCATIONS => 1,
            self::MAX_USERS => 3,
            self::MAX_PRODUCTS => 500,
            self::MAX_PARTNERS => 100,
            self::MAX_DOCUMENTS_PER_MONTH => 100,
            self::STORAGE_QUOTA_BYTES => 2 * 1024 * 1024 * 1024, // 2 GB
            self::API_REQUESTS_PER_HOUR => 500,
            self::INCLUDED_USERS => 3,
            self::PRICE_PER_EXTRA_USER => 0, // Not available

            // Core modules
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => true,
            self::MODULE_PARTNERS => true,

            // Advanced modules
            self::MODULE_WORKSHOP => false,
            self::MODULE_VEHICLES => false,
            self::MODULE_REPORTING => false,
            self::MODULE_MULTI_LOCATION => false,
            self::MODULE_ECOMMERCE => false,
            self::MODULE_HR => false,

            // Features
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => true,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => false,
            self::FEATURE_API_ACCESS => false,
            self::FEATURE_WEBHOOKS => false,
            self::FEATURE_CUSTOM_BRANDING => false,
            self::FEATURE_PRIORITY_SUPPORT => false,
            self::FEATURE_AUDIT_TRAIL => true,
            self::FEATURE_BACKUP => false,
            self::FEATURE_MULTI_CURRENCY => false,
            self::FEATURE_LANDED_COST => false,
            self::FEATURE_MARGIN_ANALYSIS => false,
            self::FEATURE_BANK_RECONCILIATION => false,
            self::FEATURE_FISCAL_COMPLIANCE => false,
        ];
    }

    /**
     * Get default limits for the growth/professional plan.
     *
     * @return array<string, mixed>
     */
    public static function growth(): array
    {
        return [
            // Resource limits
            self::MAX_COMPANIES => 2,
            self::MAX_LOCATIONS => 3,
            self::MAX_USERS => 10,
            self::MAX_PRODUCTS => 5000,
            self::MAX_PARTNERS => 500,
            self::MAX_DOCUMENTS_PER_MONTH => 500,
            self::STORAGE_QUOTA_BYTES => 10 * 1024 * 1024 * 1024, // 10 GB
            self::API_REQUESTS_PER_HOUR => 2000,
            self::INCLUDED_USERS => 5,
            self::PRICE_PER_EXTRA_USER => 15.00, // Per user per month

            // Core modules
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => true,
            self::MODULE_PARTNERS => true,

            // Advanced modules
            self::MODULE_WORKSHOP => true,
            self::MODULE_VEHICLES => true,
            self::MODULE_REPORTING => true,
            self::MODULE_MULTI_LOCATION => false,
            self::MODULE_ECOMMERCE => false,
            self::MODULE_HR => false,

            // Features
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => true,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => true,
            self::FEATURE_API_ACCESS => true,
            self::FEATURE_WEBHOOKS => false,
            self::FEATURE_CUSTOM_BRANDING => true,
            self::FEATURE_PRIORITY_SUPPORT => false,
            self::FEATURE_AUDIT_TRAIL => true,
            self::FEATURE_BACKUP => true,
            self::FEATURE_MULTI_CURRENCY => true,
            self::FEATURE_LANDED_COST => true,
            self::FEATURE_MARGIN_ANALYSIS => true,
            self::FEATURE_BANK_RECONCILIATION => false,
            self::FEATURE_FISCAL_COMPLIANCE => false,
        ];
    }

    /**
     * Get default limits for the business plan.
     *
     * @return array<string, mixed>
     */
    public static function business(): array
    {
        return [
            // Resource limits
            self::MAX_COMPANIES => 5,
            self::MAX_LOCATIONS => 10,
            self::MAX_USERS => 25,
            self::MAX_PRODUCTS => 50000,
            self::MAX_PARTNERS => 5000,
            self::MAX_DOCUMENTS_PER_MONTH => 2000,
            self::STORAGE_QUOTA_BYTES => 50 * 1024 * 1024 * 1024, // 50 GB
            self::API_REQUESTS_PER_HOUR => 10000,
            self::INCLUDED_USERS => 10,
            self::PRICE_PER_EXTRA_USER => 12.00, // Per user per month

            // Core modules
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => true,
            self::MODULE_PARTNERS => true,

            // Advanced modules
            self::MODULE_WORKSHOP => true,
            self::MODULE_VEHICLES => true,
            self::MODULE_REPORTING => true,
            self::MODULE_MULTI_LOCATION => true,
            self::MODULE_ECOMMERCE => true,
            self::MODULE_HR => false,

            // Features
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => true,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => true,
            self::FEATURE_API_ACCESS => true,
            self::FEATURE_WEBHOOKS => true,
            self::FEATURE_CUSTOM_BRANDING => true,
            self::FEATURE_PRIORITY_SUPPORT => true,
            self::FEATURE_AUDIT_TRAIL => true,
            self::FEATURE_BACKUP => true,
            self::FEATURE_MULTI_CURRENCY => true,
            self::FEATURE_LANDED_COST => true,
            self::FEATURE_MARGIN_ANALYSIS => true,
            self::FEATURE_BANK_RECONCILIATION => true,
            self::FEATURE_FISCAL_COMPLIANCE => true,
        ];
    }

    /**
     * Get default limits for the enterprise plan.
     *
     * @return array<string, mixed>
     */
    public static function enterprise(): array
    {
        return [
            // Resource limits (effectively unlimited)
            self::MAX_COMPANIES => 100,
            self::MAX_LOCATIONS => 100,
            self::MAX_USERS => 500,
            self::MAX_PRODUCTS => PHP_INT_MAX,
            self::MAX_PARTNERS => PHP_INT_MAX,
            self::MAX_DOCUMENTS_PER_MONTH => PHP_INT_MAX,
            self::STORAGE_QUOTA_BYTES => 500 * 1024 * 1024 * 1024, // 500 GB
            self::API_REQUESTS_PER_HOUR => 100000,
            self::INCLUDED_USERS => 25,
            self::PRICE_PER_EXTRA_USER => 10.00, // Per user per month

            // All modules enabled
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => true,
            self::MODULE_PARTNERS => true,
            self::MODULE_WORKSHOP => true,
            self::MODULE_VEHICLES => true,
            self::MODULE_REPORTING => true,
            self::MODULE_MULTI_LOCATION => true,
            self::MODULE_ECOMMERCE => true,
            self::MODULE_HR => true,

            // All features enabled
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => true,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => true,
            self::FEATURE_API_ACCESS => true,
            self::FEATURE_WEBHOOKS => true,
            self::FEATURE_CUSTOM_BRANDING => true,
            self::FEATURE_PRIORITY_SUPPORT => true,
            self::FEATURE_AUDIT_TRAIL => true,
            self::FEATURE_BACKUP => true,
            self::FEATURE_MULTI_CURRENCY => true,
            self::FEATURE_LANDED_COST => true,
            self::FEATURE_MARGIN_ANALYSIS => true,
            self::FEATURE_BANK_RECONCILIATION => true,
            self::FEATURE_FISCAL_COMPLIANCE => true,
        ];
    }

    /**
     * Get unlimited limits (for internal/demo accounts).
     *
     * @return array<string, mixed>
     */
    public static function unlimited(): array
    {
        return [
            // No limits
            self::MAX_COMPANIES => PHP_INT_MAX,
            self::MAX_LOCATIONS => PHP_INT_MAX,
            self::MAX_USERS => PHP_INT_MAX,
            self::MAX_PRODUCTS => PHP_INT_MAX,
            self::MAX_PARTNERS => PHP_INT_MAX,
            self::MAX_DOCUMENTS_PER_MONTH => PHP_INT_MAX,
            self::STORAGE_QUOTA_BYTES => PHP_INT_MAX,
            self::API_REQUESTS_PER_HOUR => PHP_INT_MAX,
            self::INCLUDED_USERS => PHP_INT_MAX,
            self::PRICE_PER_EXTRA_USER => 0,

            // All modules enabled
            self::MODULE_SALES => true,
            self::MODULE_INVENTORY => true,
            self::MODULE_TREASURY => true,
            self::MODULE_ACCOUNTING => true,
            self::MODULE_PARTNERS => true,
            self::MODULE_WORKSHOP => true,
            self::MODULE_VEHICLES => true,
            self::MODULE_REPORTING => true,
            self::MODULE_MULTI_LOCATION => true,
            self::MODULE_ECOMMERCE => true,
            self::MODULE_HR => true,

            // All features enabled
            self::FEATURE_CREDIT_NOTES => true,
            self::FEATURE_DELIVERY_NOTES => true,
            self::FEATURE_DOCUMENT_CONVERSION => true,
            self::FEATURE_PDF_EXPORT => true,
            self::FEATURE_EXCEL_EXPORT => true,
            self::FEATURE_EMAIL_NOTIFICATIONS => true,
            self::FEATURE_SMS_NOTIFICATIONS => true,
            self::FEATURE_API_ACCESS => true,
            self::FEATURE_WEBHOOKS => true,
            self::FEATURE_CUSTOM_BRANDING => true,
            self::FEATURE_PRIORITY_SUPPORT => true,
            self::FEATURE_AUDIT_TRAIL => true,
            self::FEATURE_BACKUP => true,
            self::FEATURE_MULTI_CURRENCY => true,
            self::FEATURE_LANDED_COST => true,
            self::FEATURE_MARGIN_ANALYSIS => true,
            self::FEATURE_BANK_RECONCILIATION => true,
            self::FEATURE_FISCAL_COMPLIANCE => true,
        ];
    }

    /**
     * Get limits for a plan code.
     *
     * @return array<string, mixed>
     */
    public static function forPlan(string $code): array
    {
        return match ($code) {
            'trial' => self::trial(),
            'starter' => self::starter(),
            'growth', 'professional' => self::growth(),
            'business' => self::business(),
            'enterprise' => self::enterprise(),
            'unlimited', 'internal' => self::unlimited(),
            default => self::trial(),
        };
    }

    /**
     * Get all resource limit keys.
     *
     * @return array<string>
     */
    public static function resourceLimitKeys(): array
    {
        return [
            self::MAX_COMPANIES,
            self::MAX_LOCATIONS,
            self::MAX_USERS,
            self::MAX_PRODUCTS,
            self::MAX_PARTNERS,
            self::MAX_DOCUMENTS_PER_MONTH,
            self::STORAGE_QUOTA_BYTES,
            self::API_REQUESTS_PER_HOUR,
        ];
    }

    /**
     * Get all module keys.
     *
     * @return array<string>
     */
    public static function moduleKeys(): array
    {
        return [
            self::MODULE_SALES,
            self::MODULE_INVENTORY,
            self::MODULE_TREASURY,
            self::MODULE_ACCOUNTING,
            self::MODULE_PARTNERS,
            self::MODULE_WORKSHOP,
            self::MODULE_VEHICLES,
            self::MODULE_REPORTING,
            self::MODULE_MULTI_LOCATION,
            self::MODULE_ECOMMERCE,
            self::MODULE_HR,
        ];
    }

    /**
     * Get all feature keys.
     *
     * @return array<string>
     */
    public static function featureKeys(): array
    {
        return [
            self::FEATURE_CREDIT_NOTES,
            self::FEATURE_DELIVERY_NOTES,
            self::FEATURE_DOCUMENT_CONVERSION,
            self::FEATURE_PDF_EXPORT,
            self::FEATURE_EXCEL_EXPORT,
            self::FEATURE_EMAIL_NOTIFICATIONS,
            self::FEATURE_SMS_NOTIFICATIONS,
            self::FEATURE_API_ACCESS,
            self::FEATURE_WEBHOOKS,
            self::FEATURE_CUSTOM_BRANDING,
            self::FEATURE_PRIORITY_SUPPORT,
            self::FEATURE_AUDIT_TRAIL,
            self::FEATURE_BACKUP,
            self::FEATURE_MULTI_CURRENCY,
            self::FEATURE_LANDED_COST,
            self::FEATURE_MARGIN_ANALYSIS,
            self::FEATURE_BANK_RECONCILIATION,
            self::FEATURE_FISCAL_COMPLIANCE,
        ];
    }
}
