declare namespace App.Enums {
export type Product = 'izipos' | 'otospex';
export type Vertical = 'mechanic' | 'pharmacy' | 'restaurant' | 'coffee_shop' | 'retail' | 'fashion' | 'body_shop' | 'parts_retailer' | 'car_glass' | 'tire_shop' | 'service_station' | 'parapharmacy';
}
declare namespace App.Modules.Accounting.Domain.Enums {
export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
export type JournalEntryStatus = 'draft' | 'posted' | 'reversed';
export type OpeningBatchStatus = 'DRAFT' | 'VALIDATED' | 'LOCKED';
export type OpeningBatchType = 'ACCOUNTING' | 'INVENTORY' | 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS';
export type OpeningImportRowStatus = 'PENDING' | 'VALID' | 'INVALID' | 'SKIPPED' | 'POSTED';
export type SystemAccountPurpose = 'bank' | 'cash' | 'customer_receivable' | 'supplier_advance' | 'inventory' | 'uninvoiced_revenue' | 'supplier_payable' | 'customer_advance' | 'vat_collected' | 'vat_deductible' | 'product_revenue' | 'service_revenue' | 'cost_of_goods_sold' | 'purchase_expenses' | 'office_expense' | 'travel_expense' | 'meals_expense' | 'utilities_expense' | 'general_expense' | 'retained_earnings' | 'opening_balance_equity' | 'payment_tolerance_expense' | 'payment_tolerance_income' | 'sales_return' | 'realized_fx_gain' | 'realized_fx_loss' | 'sales_discount';
}
declare namespace App.Modules.BatchExpiry.Domain.Enums {
export type ExpiryStatus = 'ok' | 'approaching' | 'warning' | 'critical' | 'expired';
}
declare namespace App.Modules.Billing.Domain.Enums {
export type InvoiceStatus = 'draft' | 'pending' | 'sent' | 'paid' | 'partially_paid' | 'overdue' | 'cancelled' | 'refunded';
export type PaymentProviderCode = 'stripe' | 'paypal' | 'klarna' | 'sepa_transfer' | 'flouci' | 'click_to_pay' | 'konnect' | 'manual' | 'bank_transfer' | 'cash' | 'check';
export type PaymentStatus = 'pending' | 'processing' | 'requires_action' | 'succeeded' | 'failed' | 'cancelled' | 'refunded' | 'partially_refunded';
export type SubscriptionStatus = 'trial' | 'active' | 'past_due' | 'unpaid' | 'paused' | 'cancelling' | 'cancelled' | 'expired';
}
declare namespace App.Modules.Company.Domain.Enums {
export type CompanyStatus = 'active' | 'suspended' | 'closed';
export type DocumentReviewStatus = 'pending' | 'in_review' | 'approved' | 'rejected' | 'expired';
export type HashChainType = 'invoice' | 'credit_note' | 'delivery_note' | 'return_note' | 'receipt' | 'payment' | 'journal_entry' | 'z_report';
export type LocationType = 'shop' | 'warehouse' | 'office' | 'mobile';
export type MembershipRole = 'owner' | 'admin' | 'manager' | 'accountant' | 'cashier' | 'technician' | 'viewer';
export type MembershipStatus = 'active' | 'pending' | 'suspended' | 'revoked';
export type PeriodStatus = 'open' | 'closed' | 'locked';
export type SequenceType = 'invoice' | 'credit_note' | 'quote' | 'sales_order' | 'purchase_order' | 'delivery_note' | 'receipt' | 'journal_entry';
export type VerificationStatus = 'pending' | 'submitted' | 'in_review' | 'verified' | 'rejected';
export type VerificationTier = 'basic' | 'standard' | 'enhanced' | 'certified';
}
declare namespace App.Modules.Document.Application.DTOs {
export type DocumentData = {
id: string;
tenant_id: string;
partner_id: string;
partner_name: string | null;
partner_email: string | null;
vehicle_context: App.Modules.Document.Application.DTOs.VehicleContextData | null;
type: string;
fiscal_category: string;
fiscal_status: string;
status: string;
is_sealed: boolean;
is_fiscal: boolean;
document_number: string;
document_date: string;
due_date: string | null;
valid_until: string | null;
currency: string;
subtotal: string | null;
discount_amount: string | null;
tax_amount: string | null;
total: string | null;
balance_due: string | null;
outstanding_amount: string | null;
payment_status: string | null;
fulfillment_status: string | null;
amount_paid: string | null;
amount_residual: string | null;
notes: string | null;
internal_notes: string | null;
reference: string | null;
external_document_number: string | null;
external_document_date: string | null;
source_document_id: string | null;
source_document_number: string | null;
source_document_type: string | null;
converted_to_order_id: string | null;
converted_at: string | null;
fully_delivered: boolean;
fully_invoiced: boolean;
goods_received: boolean;
delivery_note_ids: Array<any>;
invoice_ids: Array<any>;
lines: Array<any>;
payments: Array<any>;
created_at: string;
updated_at: string;
};
export type VehicleContextData = {
vehicle_id: string;
snapshot: Array<any> | null;
mileage: number | null;
display: string | null;
additional_data: Array<any> | null;
};
}
declare namespace App.Modules.Document.Domain.Enums {
export type CreditNoteReason = 'return' | 'price_adjustment' | 'billing_error' | 'damaged_goods' | 'service_issue' | 'other';
export type DeliveryStatus = 'not_delivered' | 'partially_delivered' | 'fully_delivered';
export type DocumentStatus = 'draft' | 'confirmed' | 'posted' | 'paid' | 'received' | 'cancelled';
export type DocumentType = 'quote' | 'sales_order' | 'purchase_order' | 'invoice' | 'credit_note' | 'delivery_note' | 'return_note' | 'expense';
export type FiscalCategory = 'NON_FISCAL' | 'FISCAL_RECEIPT' | 'TAX_INVOICE' | 'CREDIT_NOTE' | 'DELIVERY_NOTE' | 'RETURN_NOTE';
export type FiscalStatus = 'DRAFT' | 'SEALED' | 'VOIDED';
export type FulfillmentStatus = 'not_fulfilled' | 'partially_fulfilled' | 'fulfilled' | 'not_applicable';
export type PaymentStatus = 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid';
export type RefundMethod = 'original_payment' | 'store_credit' | 'exchange' | 'none';
export type ReturnCondition = 'unopened' | 'used' | 'damaged' | 'unusable';
export type ReturnReason = 'defective' | 'wrong_item' | 'customer_regret' | 'damaged_in_transit' | 'warranty' | 'exchange' | 'other';
}
declare namespace App.Modules.Identity.Application.DTOs {
export type AuthUserData = {
id: string;
tenantId: string;
name: string;
email: string;
phone: string | null;
status: string;
locale: string | null;
timezone: string | null;
roles: Array<any>;
permissions: Array<any>;
emailVerified: boolean;
};
export type LoginData = {
email: string;
password: string;
deviceName: string | null;
deviceId: string | null;
platform: string | null;
platformVersion: string | null;
appVersion: string | null;
};
export type LoginResponseData = {
user: App.Modules.Identity.Application.DTOs.AuthUserData;
token: string;
tokenType: string;
deviceId: string | null;
};
export type UserData = {
id: string;
name: string;
email: string;
phone: string | null;
status: string;
locale: string | null;
timezone: string | null;
roles: Array<any>;
emailVerifiedAt: string | null;
lastLoginAt: string | null;
lastLoginIp: string | null;
createdAt: string;
updatedAt: string;
};
}
declare namespace App.Modules.Identity.Domain.Enums {
export type UserStatus = 'active' | 'inactive' | 'suspended' | 'pending_verification';
}
declare namespace App.Modules.Import.Domain.Enums {
export type ImportStatus = 'pending' | 'validating' | 'validated' | 'importing' | 'completed' | 'failed';
export type ImportType = 'partners' | 'products' | 'stock_levels' | 'opening_balances' | 'product_images';
}
declare namespace App.Modules.Inventory.Application.DTOs {
export type StockLevelData = {
id: string;
location_id: string;
location_name: string;
quantity: string;
reserved: string;
available: string;
incoming: string;
projected_available: string;
min_quantity: string | null;
max_quantity: string | null;
is_below_minimum: boolean;
};
}
declare namespace App.Modules.Inventory.Domain.Enums {
export type AssignmentStatus = 'pending' | 'in_progress' | 'completed' | 'overdue';
export type CountingExecutionMode = 'parallel' | 'sequential';
export type CountingScopeType = 'product_location' | 'product' | 'location' | 'category' | 'full_inventory';
export type CountingStatus = 'draft' | 'scheduled' | 'count_1_in_progress' | 'count_1_completed' | 'count_2_in_progress' | 'count_2_completed' | 'count_3_in_progress' | 'count_3_completed' | 'pending_review' | 'finalized' | 'cancelled';
export type ItemResolutionMethod = 'pending' | 'auto_all_match' | 'auto_counters_agree' | 'third_count_decisive' | 'manual_override';
export type MovementReason = 'goods_receipt' | 'customer_return' | 'adjustment_positive' | 'transfer_in' | 'production_output' | 'opening_balance' | 'delivery' | 'supplier_return' | 'adjustment_negative' | 'transfer_out' | 'damage' | 'expiry' | 'write_off' | 'consumption';
export type MovementType = 'receipt' | 'issue' | 'transfer_in' | 'transfer_out' | 'adjustment' | 'opening';
export type ReleaseReason = 'delivered' | 'cancelled' | 'expired' | 'manual_release' | 'converted' | 'order_modified' | 'insufficient_stock';
export type ReservationSource = 'sales_order' | 'ecommerce_cart' | 'marketplace_order' | 'manual_hold' | 'customer_return_pending' | 'quality_check' | 'transfer_pending';
}
declare namespace App.Modules.Loyalty.Application.DTOs {
export type EarningConditionsData = {
min_purchase_amount: string | null;
max_purchase_amount: string | null;
min_quantity: number | null;
max_quantity: number | null;
product_ids: Array<any> | null;
category_ids: Array<any> | null;
tier_ids: Array<any> | null;
company_ids: Array<any> | null;
time_start: string | null;
time_end: string | null;
day_of_week: Array<any> | null;
first_purchase: boolean | null;
new_customer: boolean | null;
custom: Array<any> | null;
};
export type EarningRuleData = {
id: string;
program_id: string;
name: string;
rule_type: App.Modules.Loyalty.Domain.Enums.EarningRuleType;
priority: number;
is_active: boolean;
conditions: App.Modules.Loyalty.Application.DTOs.EarningConditionsData;
reward_value: string;
reward_type: string;
start_date: string | null;
end_date: string | null;
max_earn_per_transaction: string | null;
max_earn_per_day: string | null;
created_at: string;
updated_at: string | null;
};
export type EnrollmentData = {
id: string;
program_id: string;
member_id: string;
current_balance: string;
lifetime_earned: string;
lifetime_redeemed: string;
current_tier_id: string | null;
tier_qualified_at: string | null;
status: string;
enrolled_at: string;
last_transaction_at: string | null;
created_at: string;
updated_at: string | null;
};
export type LoyaltyMemberData = {
id: string;
tenant_id: string;
customer_id: string | null;
phone: string;
email: string | null;
first_name: string | null;
last_name: string | null;
date_of_birth: string | null;
status: string;
enrollment_date: string;
external_id: string | null;
created_at: string;
updated_at: string | null;
};
export type LoyaltyProgramData = {
id: string;
tenant_id: string;
company_ids: Array<any> | null;
name: string;
program_type: App.Modules.Loyalty.Domain.Enums.ProgramType;
status: App.Modules.Loyalty.Domain.Enums.ProgramStatus;
currency: string | null;
start_date: string | null;
end_date: string | null;
terms_and_conditions: string | null;
metadata: Array<any> | null;
created_at: string;
updated_at: string | null;
};
export type QualifyingItemsData = {
product_ids: Array<any> | null;
category_ids: Array<any> | null;
excluded_product_ids: Array<any> | null;
excluded_category_ids: Array<any> | null;
min_price: string | null;
max_price: string | null;
all_products: boolean | null;
};
export type RewardData = {
id: string;
program_id: string;
name: string;
description: string | null;
reward_type: App.Modules.Loyalty.Domain.Enums.RewardType;
points_cost: string;
reward_value: string | null;
qualifying_items: App.Modules.Loyalty.Application.DTOs.QualifyingItemsData | null;
max_discount: string | null;
min_order_value: string | null;
tier_ids: Array<any> | null;
is_active: boolean;
quantity_available: number | null;
quantity_per_member: number | null;
start_date: string | null;
end_date: string | null;
created_at: string;
updated_at: string | null;
};
export type StampCardData = {
id: string;
program_id: string;
name: string;
stamps_required: number;
stamps_per_item: number;
qualifying_items: App.Modules.Loyalty.Application.DTOs.QualifyingItemsData;
reward_id: string;
max_active_cards: number | null;
expiry_days: number | null;
created_at: string;
updated_at: string | null;
};
export type TierBenefitsData = {
discount_percent: string | null;
free_shipping: Array<any> | null;
priority_support: Array<any> | null;
exclusive_rewards: Array<any> | null;
bonus_points_multiplier: string | null;
birthday_bonus: Array<any> | null;
extended_expiry_days: number | null;
welcome_bonus: string | null;
custom_benefits: Array<any> | null;
};
export type TierData = {
id: string;
program_id: string;
name: string;
level: number;
icon: string | null;
color: string | null;
qualification_type: App.Modules.Loyalty.Domain.Enums.QualificationType;
qualification_threshold: string;
qualification_period_months: number | null;
earning_multiplier: string;
benefits: App.Modules.Loyalty.Application.DTOs.TierBenefitsData | null;
created_at: string;
updated_at: string | null;
};
export type TransactionData = {
id: string;
enrollment_id: string;
transaction_type: App.Modules.Loyalty.Domain.Enums.TransactionType;
amount: string;
balance_before: string;
balance_after: string;
order_id: string | null;
order_line_id: string | null;
reward_id: string | null;
earning_rule_id: string | null;
description: string | null;
metadata: Array<any> | null;
created_by: string | null;
created_at: string;
expires_at: string | null;
};
}
declare namespace App.Modules.Loyalty.Domain.Enums {
export type EarningRuleType = 'spend' | 'item' | 'category' | 'quantity' | 'visit' | 'threshold' | 'time';
export type ProgramStatus = 'draft' | 'active' | 'paused' | 'archived';
export type ProgramType = 'points' | 'stamps' | 'visits' | 'cashback' | 'hybrid';
export type QualificationType = 'spend' | 'points_earned' | 'visits' | 'manual';
export type RewardType = 'free_item' | 'discount_amount' | 'discount_percent' | 'choice' | 'credit' | 'external';
export type TransactionType = 'earn' | 'redeem' | 'adjust' | 'expire' | 'transfer_in' | 'transfer_out' | 'bonus' | 'refund';
}
declare namespace App.Modules.Partner.Application.DTOs {
export type PartnerData = {
id: string;
name: string;
type: App.Modules.Partner.Domain.Enums.PartnerType;
code: string | null;
email: string | null;
phone: string | null;
country_code: string | null;
vat_number: string | null;
notes: string | null;
created_at: string;
updated_at: string | null;
};
}
declare namespace App.Modules.Partner.Domain.Enums {
export type PartnerType = 'customer' | 'supplier' | 'both';
}
declare namespace App.Modules.Product.Application.DTOs {
export type CategoryData = {
id: number;
company_id: string;
parent_id: number | null;
name: string;
slug: string;
description: string | null;
image_url: string | null;
path: string;
depth: number;
sort_order: number;
is_active: boolean;
products_count: number | null;
breadcrumb: Array<any> | null;
children: Array<any> | null;
};
export type CertificationData = {
id: string;
type: string;
slug: string;
certifying_body: string | null;
logo_url: string | null;
verification_url: string | null;
is_active: boolean;
display_order: number;
name: string;
description: string | null;
created_at: string;
updated_at: string | null;
};
export type HealthClaimData = {
id: string;
claim_type: string;
slug: string;
regulatory_status: string;
efsa_reference: string | null;
fda_reference: string | null;
country_restrictions: Array<any> | null;
requires_disclaimer: boolean;
claim: string;
disclaimer_text: string | null;
created_at: string;
updated_at: string | null;
};
export type IngredientData = {
id: string;
slug: string;
cas_number: string | null;
is_allergen: boolean;
allergen_code: string | null;
regulatory_status: string | null;
notes: string | null;
name: string;
description: string | null;
created_at: string;
updated_at: string | null;
};
export type KeyComponentData = {
id: string;
slug: string;
is_allergen: boolean;
name: string;
description: string | null;
created_at: string;
updated_at: string | null;
};
export type ParapharmacyProductMetadataData = {
id: string;
product_id: string;
category: App.Modules.Product.Domain.Enums.ParapharmacyCategory;
dosage_form: App.Modules.Product.Domain.Enums.DosageForm | null;
ingredients: any | null;
key_components: any | null;
usage_instructions: string | null;
warnings: string | null;
contraindications: string | null;
minimum_age: number | null;
age_restriction: App.Modules.Product.Domain.Enums.AgeRestriction | null;
requires_consultation: boolean;
regulatory_code: string | null;
health_claims: any | null;
certifications: any | null;
storage_requirements: string | null;
created_at: string;
updated_at: string | null;
};
export type ProductCertificationData = {
certification: App.Modules.Product.Application.DTOs.CertificationData;
certification_code: string | null;
issued_date: string | null;
expiry_date: string | null;
verification_url: string | null;
notes: string | null;
};
export type ProductData = {
id: string;
name: string;
sku: string;
type: App.Modules.Product.Domain.Enums.ProductType;
description: string | null;
sale_price: string | null;
purchase_price: string | null;
cost_price: string | null;
tax_rate: string | null;
unit: string | null;
barcode: string | null;
is_active: boolean;
oem_numbers: Array<any> | null;
cross_references: Array<any> | null;
target_margin_override: string | null;
minimum_margin_override: string | null;
created_at: string;
updated_at: string | null;
parapharmacy_metadata: App.Modules.Product.Application.DTOs.ParapharmacyProductMetadataData | null;
};
export type ProductHealthClaimData = {
health_claim: App.Modules.Product.Application.DTOs.HealthClaimData;
display_order: number;
};
export type ProductIngredientData = {
ingredient: App.Modules.Product.Application.DTOs.IngredientData;
concentration: string | null;
concentration_numeric: number | null;
concentration_unit: string | null;
order: number;
notes: string | null;
};
export type ProductKeyComponentData = {
key_component: App.Modules.Product.Application.DTOs.KeyComponentData;
order: number;
};
}
declare namespace App.Modules.Product.Domain.Enums {
export type AgeRestriction = 'adult_only' | 'children_only' | 'all_ages';
export type DosageForm = 'capsule' | 'tablet' | 'softgel' | 'liquid' | 'powder' | 'cream' | 'gel' | 'lotion' | 'spray' | 'patch' | 'other';
export type ParapharmacyCategory = 'supplement' | 'cosmetic' | 'medical_device' | 'herbal' | 'baby_care' | 'sports_nutrition' | 'other';
export type ProductType = 'part' | 'service' | 'consumable';
}
declare namespace App.Modules.Service.Application.DTOs {
export type ServiceCategoryData = {
id: string;
name: string;
description: string | null;
parent_id: string | null;
sort_order: number;
is_active: boolean;
services_count: number | null;
created_at: string;
updated_at: string | null;
};
export type ServiceData = {
id: string;
code: string;
name: string;
description: string | null;
category_id: string | null;
category: App.Modules.Service.Application.DTOs.ServiceCategoryData | null;
pricing_type: App.Modules.Service.Domain.Enums.PricingType;
base_price: string;
currency: string;
default_duration_minutes: number | null;
hourly_rate: string | null;
tax_rate: string | null;
is_active: boolean;
created_at: string;
updated_at: string | null;
};
}
declare namespace App.Modules.Service.Domain.Enums {
export type PricingType = 'flat_rate' | 'hourly' | 'percentage';
}
declare namespace App.Modules.Taxation.Domain.Enums {
export type CertificateStatus = 'draft' | 'issued' | 'submitted' | 'voided';
export type CompanyTaxStatus = 'REGISTERED' | 'NON_REGISTERED';
export type PartnerTaxStatus = 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT';
export type StackingBehavior = 'SUBTOTAL' | 'TOTAL_INCLUDING_PREVIOUS';
export type TaxApplicationLevel = 'LINE_ITEMS' | 'DOCUMENT_TOTAL';
export type TaxType = 'PERCENTAGE' | 'FIXED_AMOUNT';
export type TransactionType = 'services' | 'goods' | 'rental' | 'rental_hotel' | 'commission' | 'export_services';
export type WithholdingDirection = 'purchase' | 'sales';
}
declare namespace App.Modules.Taxation.Domain.Services {
export type TaxSource = 'line' | 'product' | 'category' | 'company';
}
declare namespace App.Modules.Tenant.Domain.Enums {
export type SubscriptionPlan = 'trial' | 'starter' | 'professional' | 'enterprise';
export type TenantStatus = 'active' | 'suspended' | 'pending' | 'archived';
}
declare namespace App.Modules.Treasury.Domain.Enums {
export type AllocationMethod = 'fifo' | 'due_date' | 'manual';
export type AllocationType = 'invoice_payment' | 'credit_application' | 'credit_note_application' | 'tolerance_writeoff';
export type FeeType = 'none' | 'fixed' | 'percentage' | 'mixed';
export type InstrumentStatus = 'received' | 'in_transit' | 'deposited' | 'clearing' | 'cleared' | 'bounced' | 'expired' | 'cancelled' | 'collected';
export type PaymentStatus = 'pending' | 'completed' | 'failed' | 'reversed';
export type PaymentType = 'document_payment' | 'advance' | 'refund' | 'credit_application' | 'supplier_payment';
export type ReconciliationStatus = 'draft' | 'completed' | 'cancelled';
export type RepositoryType = 'cash_register' | 'safe' | 'bank_account' | 'virtual';
}
declare namespace App.Modules.Uom.Application.DTOs {
export type ConversionResultData = {
originalQuantity: string;
originalUnit: App.Modules.Uom.Application.DTOs.UnitData;
convertedQuantity: string;
convertedUnit: App.Modules.Uom.Application.DTOs.UnitData;
conversionFactor: string;
};
export type UnitCategoryData = {
id: string;
code: string;
name: string;
description: string | null;
baseUnitId: string | null;
isSystem: boolean;
isActive: boolean;
units: Array<any>;
};
export type UnitData = {
id: string;
categoryId: string;
code: string;
name: string;
symbol: string;
conversionFactor: string;
decimalPlaces: number;
roundingMethod: string;
isBaseUnit: boolean;
isSystem: boolean;
isActive: boolean;
category: App.Modules.Uom.Application.DTOs.UnitCategoryData | null;
};
}
declare namespace App.Modules.Uom.Domain.Enums {
export type RoundingMethod = 'half_up' | 'floor' | 'ceil';
}
declare namespace App.Shared.Application.DTOs {
export type PaginationData = {
page: number;
perPage: number;
total: number;
totalPages: number;
hasNextPage: boolean;
hasPreviousPage: boolean;
};
}
