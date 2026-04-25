declare global {
declare namespace App.Enums {
export type Product = 'izipos' | 'otospex';
export type Vertical = 'mechanic' | 'pharmacy' | 'restaurant' | 'coffee_shop' | 'retail' | 'fashion' | 'body_shop' | 'parts_retailer' | 'car_glass' | 'tire_shop' | 'service_station' | 'parapharmacy';
}
declare namespace App.Modules.Accounting.Application.DTOs {
export type AccountData = {
id: string;
tenant_id: string;
parent_id: string | null;
code: string;
name: string;
type: string;
description: string | null;
is_active: boolean;
is_system: boolean;
balance: string;
created_at: string;
updated_at: string;
};
export type JournalEntryData = {
id: string;
tenantId: string;
entryNumber: string;
entryDate: string;
description: string | null;
status: App.Modules.Accounting.Domain.Enums.JournalEntryStatus;
sourceType: string | null;
sourceId: string | null;
lines: Array<any>;
createdAt: string | null;
updatedAt: string | null;
};
export type JournalLineData = {
id: string;
journalEntryId: string;
accountId: string;
debit: string;
credit: string;
description: string | null;
lineOrder: number;
};
}
declare namespace App.Modules.Accounting.Application.DTOs.Reports {
export type AgedPayablesData = {
as_of_date: string;
lines: Array<App.Modules.Accounting.Application.DTOs.Reports.AgedPayablesLineData>;
total_current: string;
total_days_30: string;
total_days_60: string;
total_days_90: string;
total_over_90: string;
grand_total: string;
};
export type AgedPayablesLineData = {
vendor_id: string;
vendor_name: string;
current: string;
days_30: string;
days_60: string;
days_90: string;
over_90: string;
total: string;
};
export type AgedReceivablesData = {
as_of_date: string;
lines: Array<App.Modules.Accounting.Application.DTOs.Reports.AgedReceivablesLineData>;
total_current: string;
total_days_30: string;
total_days_60: string;
total_days_90: string;
total_over_90: string;
grand_total: string;
};
export type AgedReceivablesLineData = {
customer_id: string;
customer_name: string;
current: string;
days_30: string;
days_60: string;
days_90: string;
over_90: string;
total: string;
};
export type BalanceSheetData = {
assets: Array<App.Modules.Accounting.Application.DTOs.Reports.BalanceSheetLineData>;
liabilities: Array<App.Modules.Accounting.Application.DTOs.Reports.BalanceSheetLineData>;
equity: Array<App.Modules.Accounting.Application.DTOs.Reports.BalanceSheetLineData>;
total_assets: string;
total_liabilities: string;
total_equity: string;
retained_earnings: string;
is_balanced: boolean;
as_of_date: string;
};
export type BalanceSheetLineData = {
account_code: string;
account_name: string;
account_type: string;
amount: string;
level: number;
is_parent: boolean;
};
export type LedgerData = {
opening_balance: string;
closing_balance: string;
total_debits: string;
total_credits: string;
lines: Array<App.Modules.Accounting.Application.DTOs.Reports.LedgerLineData>;
date_from: string | null;
date_to: string | null;
account_filter: string | null;
partner_filter: string | null;
};
export type LedgerLineData = {
id: string;
date: string;
entry_number: string;
description: string;
account_code: string;
account_name: string;
partner_name: string | null;
debit: string;
credit: string;
balance: string;
source_type: string | null;
source_id: string | null;
};
export type ProfitLossData = {
revenue: Array<App.Modules.Accounting.Application.DTOs.Reports.ProfitLossLineData>;
expenses: Array<App.Modules.Accounting.Application.DTOs.Reports.ProfitLossLineData>;
total_revenue: string;
total_expenses: string;
net_income: string;
date_from: string;
date_to: string;
};
export type ProfitLossLineData = {
account_code: string;
account_name: string;
account_type: string;
amount: string;
level: number;
is_parent: boolean;
};
export type TrialBalanceData = {
lines: Array<App.Modules.Accounting.Application.DTOs.Reports.TrialBalanceLineData>;
total_debit: string;
total_credit: string;
is_balanced: boolean;
as_of_date: string;
};
export type TrialBalanceLineData = {
account_code: string;
account_name: string;
account_type: string;
debit: string;
credit: string;
level: number;
is_parent: boolean;
};
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
declare namespace App.Modules.Cart.Application.DTOs {
export type CatalogCartData = {
id: string;
name: string | null;
vehicle_id: string | null;
status: App.Modules.Cart.Domain.Enums.CartStatus;
is_shared: boolean;
items: Array<App.Modules.Cart.Application.DTOs.CatalogCartItemData>;
notes: string | null;
created_at: string;
updated_at: string | null;
};
export type CatalogCartItemData = {
id: string;
article_name: string;
article_number: string | null;
supplier_brand: string | null;
quantity: string;
unit_price: string | null;
currency: string | null;
source: App.Modules.Cart.Domain.Enums.CartItemSource;
product_id: string | null;
marketplace_listing_id: string | null;
reservation_id: string | null;
reservation_expires_at: string | null;
notes: string | null;
sort_order: number;
};
}
declare namespace App.Modules.Cart.Domain.Enums {
export type CartItemSource = 'catalog' | 'marketplace' | 'manual';
export type CartStatus = 'active' | 'converted' | 'partial' | 'archived';
}
declare namespace App.Modules.Catalog.Application.DTOs {
export type CompositeItemData = {
id: string;
code: string;
name: string;
vertical_type: App.Modules.Catalog.Domain.Enums.VerticalType;
base_price: string;
production_type: App.Modules.Catalog.Domain.Enums.ProductionType;
pricing_mode: App.Modules.Catalog.Domain.Enums.PricingMode;
tax_rate: string | null;
default_tax_configuration_id: string | null;
manual_cost: string | null;
effective_cost: string | null;
recipe_cost: string | null;
margin_percentage: number | null;
is_active: boolean;
is_available: boolean;
category_id: string | number | null;
category_name: string | null;
image_url: string | null;
display_order: number;
active_recipe: App.Modules.Catalog.Application.DTOs.RecipeData | null;
variants: Array<App.Modules.Catalog.Application.DTOs.CompositeItemVariantData> | null;
modifier_groups: Array<App.Modules.Catalog.Application.DTOs.ModifierGroupData> | null;
created_at: string;
updated_at: string | null;
};
export type CompositeItemVariantData = {
id: string;
composite_item_id: string;
code: string;
name: string;
price_adjustment_type: App.Modules.Catalog.Domain.Enums.PriceAdjustmentType;
price_adjustment: string;
recipe_multiplier: string;
is_default: boolean;
is_active: boolean;
display_order: number;
};
export type ModifierData = {
id: string;
modifier_group_id: string;
code: string;
name: string;
price_adjustment: string;
has_inventory_impact: boolean;
component_type: string | null;
component_id: string | null;
component_quantity: string | null;
component_unit_id: string | null;
is_default: boolean;
is_active: boolean;
display_order: number;
};
export type ModifierGroupData = {
id: string;
code: string;
name: string;
selection_type: App.Modules.Catalog.Domain.Enums.SelectionType;
min_selections: number;
max_selections: number;
is_required: boolean;
is_active: boolean;
display_order: number;
modifiers: Array<App.Modules.Catalog.Application.DTOs.ModifierData> | null;
created_at: string;
updated_at: string | null;
};
export type RecipeCostData = {
total_cost: string;
lines: Array<any>;
};
export type RecipeData = {
id: string;
composite_item_id: string;
version: number;
version_name: string | null;
is_active: boolean;
yield_quantity: string;
yield_unit_id: string | null;
calculated_cost: string | null;
prep_time_minutes: number | null;
cook_time_minutes: number | null;
total_time_minutes: number | null;
instructions: string | null;
lines: Array<App.Modules.Catalog.Application.DTOs.RecipeLineData> | null;
created_at: string;
updated_at: string | null;
};
export type RecipeLineData = {
id: string;
recipe_id: string;
component_type: App.Modules.Catalog.Domain.Enums.ComponentType;
component_id: string;
component_name: string | null;
component_sku: string | null;
quantity: string;
unit_id: string | null;
unit_name: string | null;
is_optional: boolean;
is_scalable: boolean;
wastage_percent: string;
unit_cost: string | null;
line_cost: string | null;
display_order: number;
};
}
declare namespace App.Modules.Catalog.Domain.Enums {
export type ComponentType = 'product' | 'composite_item';
export type PriceAdjustmentType = 'absolute' | 'percentage' | 'override';
export type PricingMode = 'standard' | 'fixed_bundle';
export type ProductionType = 'made_to_order' | 'batch' | 'stock';
export type SelectionType = 'single' | 'multiple';
export type VerticalType = 'fnb' | 'manufacturing' | 'sewing' | 'bakery' | 'generic';
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
declare namespace App.Modules.Compliance.Domain.Enums {
export type Nf525EventType = 'TICKET' | 'ANNULATION' | 'RETOUR' | 'DUPLICATA' | 'OUVERTURE_CAISSE' | 'FERMETURE_CAISSE' | 'RAPPORT_Z' | 'DEPOT_ESPECES' | 'RETRAIT_ESPECES' | 'REMBOURSEMENT' | 'ACTIVATION_TERMINAL' | 'DESACTIVATION_TERMINAL' | 'MODE_FORMATION' | 'MAJ_LOGICIEL';
}
declare namespace App.Modules.Contact.Application.DTOs {
export type ContactData = {
id: string;
first_name: string;
last_name: string | null;
full_name: string;
email: string | null;
phone: string | null;
mobile: string | null;
date_of_birth: string | null;
gender: App.Modules.Contact.Domain.Enums.Gender | null;
national_id: string | null;
avatar_media_id: string | null;
notes: string | null;
is_active: boolean;
created_at: string;
updated_at: string | null;
parties: Array<App.Modules.Contact.Application.DTOs.ContactPartyData>;
};
export type ContactPartyData = {
id: string;
name: string;
type: string;
job_title: string | null;
department: string | null;
is_primary: boolean;
};
}
declare namespace App.Modules.Contact.Domain.Enums {
export type Gender = 'male' | 'female' | 'other';
}
declare namespace App.Modules.Coupon.Application.DTOs {
export type CouponData = {
id: string;
name: string;
code: string;
type: string;
status: string;
is_single_use: boolean;
max_uses: number | null;
use_count: number;
max_uses_per_customer: number | null;
discount_type: string;
discount_value: string;
max_discount_amount: string | null;
minimum_order_amount: string | null;
qualifying_product_ids: Array<any> | null;
qualifying_category_ids: Array<any> | null;
is_exclusive: boolean;
stacking_group: string;
starts_at: string | null;
expires_at: string | null;
created_at: string;
updated_at: string | null;
};
}
declare namespace App.Modules.Coupon.Domain.Enums {
export type CouponStatus = 'active' | 'exhausted' | 'expired' | 'revoked';
export type CouponType = 'standard' | 'single_use' | 'customer_specific';
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
lines: Array<App.Modules.Document.Application.DTOs.DocumentLineData>;
payments: Array<any>;
created_at: string;
updated_at: string;
};
export type DocumentLineData = {
id: string;
document_id: string;
product_id: string | null;
line_number: number;
description: string;
quantity: string;
unit_price: string;
discount_percent: string | null;
discount_amount: string | null;
tax_rate: string | null;
line_total: string;
notes: string | null;
designation_default_snapshot: string | null;
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
export type FacturXProfile = 'minimum' | 'basicwl' | 'basic' | 'en16931' | 'extended';
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
email: string | null;
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
email: string | null;
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
canDiscount: boolean | null;
maxDiscountPercent: number | null;
};
}
declare namespace App.Modules.Identity.Domain.Enums {
export type UserStatus = 'active' | 'inactive' | 'suspended' | 'pending_verification';
}
declare namespace App.Modules.Import.Domain.Enums {
export type ImportStatus = 'pending' | 'validating' | 'validated' | 'importing' | 'completed' | 'failed';
export type ImportType = 'partners' | 'products' | 'stock_levels' | 'opening_balances' | 'product_images' | 'composite_items';
}
declare namespace App.Modules.Inventory.Application.DTOs {
export type StockLevelData = {
id: string;
product_id: string;
product_name: string | null;
location_id: string;
location_name: string | null;
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
export type MovementReason = 'goods_receipt' | 'customer_return' | 'adjustment_positive' | 'transfer_in' | 'production_output' | 'opening_balance' | 'delivery' | 'supplier_return' | 'adjustment_negative' | 'transfer_out' | 'damage' | 'expiry' | 'write_off' | 'consumption' | 'pos_sale' | 'pos_return';
export type MovementType = 'receipt' | 'issue' | 'transfer_in' | 'transfer_out' | 'adjustment' | 'opening';
export type ReleaseReason = 'delivered' | 'cancelled' | 'expired' | 'manual_release' | 'converted' | 'order_modified' | 'insufficient_stock';
export type ReservationSource = 'sales_order' | 'ecommerce_cart' | 'marketplace_order' | 'manual_hold' | 'customer_return_pending' | 'quality_check' | 'transfer_pending' | 'work_order';
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
loyaltyable_type: string | null;
loyaltyable_id: string | null;
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
export type EnrollmentStatus = 'active' | 'suspended' | 'opted_out';
export type LoyaltyTargetType = 'contact' | 'partner';
export type MemberStatus = 'active' | 'inactive' | 'suspended';
export type ProgramStatus = 'draft' | 'active' | 'paused' | 'archived';
export type ProgramType = 'points' | 'stamps' | 'visits' | 'cashback' | 'hybrid';
export type QualificationType = 'spend' | 'points_earned' | 'visits' | 'manual';
export type RewardType = 'free_item' | 'discount_amount' | 'discount_percent' | 'choice' | 'credit' | 'external';
export type TransactionType = 'earn' | 'redeem' | 'adjust' | 'expire' | 'transfer_in' | 'transfer_out' | 'bonus' | 'refund';
}
declare namespace App.Modules.Marketplace.Application.DTOs {
export type MarketplaceListingData = {
listing_id: string;
price: string;
currency: string;
product_name: string;
supplier_brand: string | null;
article_number: string | null;
quality_tier: string | null;
min_order_quantity: string;
quantity_available: string;
country_code: string;
};
export type MarketplaceOrderData = {
id: string;
order_number: string;
order_status: App.Modules.Marketplace.Domain.Enums.MarketplaceOrderStatus;
seller_name: string;
currency: string;
subtotal: string;
commission_amount: string;
total: string;
buyer_document_id: string | null;
seller_document_id: string | null;
lines: Array<any>;
created_at: string;
confirmed_at: string | null;
shipped_at: string | null;
delivered_at: string | null;
};
export type PriceComparisonData = {
current_price: string;
best_marketplace_price: string;
savings_amount: string;
savings_percent: string;
available_listings_count: number;
cheapest_listing_id: string;
};
}
declare namespace App.Modules.Marketplace.Domain.Enums {
export type ListingStatus = 'active' | 'out_of_stock' | 'suspended' | 'delisted';
export type MarketplaceOrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'disputed';
export type SellerStatus = 'active' | 'suspended' | 'pending_review';
export type SellerType = 'erp_tenant' | 'external' | 'syneriva';
}
declare namespace App.Modules.Menu.Application.DTOs {
export type ActiveMenuData = {
id: string;
name: string;
description: string | null;
is_default: boolean;
categories: Array<App.Modules.Menu.Application.DTOs.MenuCategoryData>;
};
export type MenuCategoryData = {
id: string;
menu_id: string;
name: string;
description: string | null;
icon: string | null;
display_order: number;
is_active: boolean;
items: Array<App.Modules.Menu.Application.DTOs.MenuItemData> | null;
created_at: string;
updated_at: string | null;
};
export type MenuData = {
id: string;
name: string;
description: string | null;
is_default: boolean;
is_active: boolean;
active_from: string | null;
active_until: string | null;
start_date: string | null;
end_date: string | null;
available_days: Array<number> | null;
display_order: number;
categories: Array<App.Modules.Menu.Application.DTOs.MenuCategoryData> | null;
categories_count: number;
items_count: number;
created_at: string;
updated_at: string | null;
};
export type MenuItemData = {
id: string;
sellable_id: string;
sellable_type: string;
name: string;
code: string;
base_price: string;
override_price: string | null;
effective_price: string;
tax_rate: string | null;
display_order: number;
is_available: boolean;
image_url: string | null;
modifier_groups: Array<App.Modules.Catalog.Application.DTOs.ModifierGroupData> | null;
};
}
declare namespace App.Modules.POS.Application.DTOs {
export type CustomerAnalyticsData = {
unique_customers: number;
returning_count: number;
returning_rate: string;
top_customers: Array<any>;
};
export type DiscountAnalysisData = {
total_discount_amount: string;
discount_count: number;
by_reason: Array<any>;
top_discounted_products: Array<any>;
};
export type FnbMetricsData = {
avg_table_time_minutes: string;
avg_items_per_order: string;
peak_hours: Array<any>;
orders_by_mode: Array<any>;
};
export type HeldOrderData = {
id: string;
terminal_id: string;
shift_id: string;
cashier_id: string;
label: string | null;
cart_snapshot: { [key: string]: any };
status: string;
held_at: string;
expires_at: string | null;
recalled_at: string | null;
line_count: number;
total: string | null;
created_at: string;
};
export type OrderData = {
id: string;
terminal_id: string;
shift_id: string;
table_id: string | null;
order_number: string;
status: App.Modules.POS.Domain.Enums.OrderStatus;
cashier_id: string;
cashier_name: string;
customer_name: string | null;
customer_identifier: string | null;
partner_id: string | null;
subtotal: string;
tax_amount: string;
discount_amount: string;
total: string;
currency: string;
consumption_mode: App.Modules.POS.Domain.Enums.ConsumptionMode | null;
notes: string | null;
opened_at: string;
sent_at: string | null;
closed_at: string | null;
cancelled_at: string | null;
receipt_id: string | null;
lines: Array<App.Modules.POS.Application.DTOs.OrderLineData>;
};
export type OrderLineData = {
id: string;
order_id: string;
line_number: number;
product_id: string;
product_name: string;
variant_name: string | null;
barcode: string | null;
quantity: string;
unit_price: string;
discount_amount: string;
tax_rate: string;
tax_amount: string;
line_total: string;
modifiers: Array<any> | null;
special_instructions: string | null;
status: App.Modules.POS.Domain.Enums.OrderLineStatus;
sent_at: string | null;
prepared_at: string | null;
created_at: string;
};
export type SalesSummaryData = {
receipt_count: number;
gross_sales: string;
net_sales: string;
tax_total: string;
average_ticket: string;
refund_count: number;
refund_total: string;
voided_count: number;
payment_breakdown: Array<any>;
};
}
declare namespace App.Modules.POS.Domain.Enums {
export type ConsumptionMode = 'SUR_PLACE' | 'A_EMPORTER';
export type DiscountSource = 'manual' | 'promotion' | 'coupon' | 'loyalty';
export type HeldOrderStatus = 'held' | 'recalled' | 'expired';
export type OrderLineStatus = 'pending' | 'sent' | 'preparing' | 'ready' | 'served' | 'cancelled';
export type OrderStatus = 'open' | 'sent_to_kitchen' | 'ready' | 'closed' | 'cancelled';
export type PrintMethod = 'pdf' | 'thermal' | 'escpos';
export type ReceiptPrintType = 'original' | 'duplicate' | 'reprint';
export type ReceiptType = 'sale' | 'return';
export type ReturnReason = 'defective' | 'wrong_item' | 'customer_changed_mind' | 'other';
export type ShiftStatus = 'OPEN' | 'CLOSED';
export type SyncStatus = 'synced' | 'duplicate' | 'failed' | 'chain_broken';
export type TableShape = 'rectangle' | 'circle' | 'square';
export type TableStatus = 'available' | 'occupied' | 'reserved' | 'cleaning';
export type TerminalType = 'web' | 'physical';
}
declare namespace App.Modules.Partner.Application.DTOs {
export type PartnerData = {
id: string;
name: string;
type: App.Modules.Partner.Domain.Enums.PartnerType;
customer_category: App.Modules.Partner.Domain.Enums.CustomerCategory | null;
company_legal_name: string | null;
business_registration_number: string | null;
payment_terms: App.Modules.Partner.Domain.Enums.PaymentTerms | null;
payment_terms_days: number | null;
credit_limit: string | null;
discount_percentage: string | null;
invoice_consolidation: boolean;
consolidation_frequency: App.Modules.Partner.Domain.Enums.ConsolidationFrequency | null;
code: string | null;
email: string | null;
phone: string | null;
country_code: string | null;
vat_number: string | null;
notes: string | null;
receivable_balance: string | null;
credit_balance: string | null;
payable_balance: string | null;
street_address: string | null;
street_address_2: string | null;
city: string | null;
state: string | null;
postal_code: string | null;
country: string | null;
is_active: boolean;
contacts_count: number;
primary_contact_name: string | null;
created_at: string;
updated_at: string | null;
};
}
declare namespace App.Modules.Partner.Domain.Enums {
export type ConsolidationFrequency = 'weekly' | 'monthly';
export type CustomerCategory = 'individual' | 'business';
export type PartnerType = 'customer' | 'supplier' | 'both';
export type PaymentTerms = 'immediate' | 'net_15' | 'net_30' | 'net_60' | 'net_90' | 'custom';
}
declare namespace App.Modules.PlatformIntegration.Application.DTOs {
export type BarcodeLookupResultData = {
status: string;
barcode: string | null;
product: any | null;
trackingId: string | null;
suggestedProduct: Array<any> | null;
errorReason: string | null;
};
export type CatalogSearchResultData = {
articles: Array<any>;
pagination: Array<any> | null;
};
export type SubmissionResultData = {
trackingId: string;
status: string;
statusUrl: string;
};
export type VehicleIdentificationData = {
status: string;
vehicle: Array<any> | null;
platformMatch: Array<any> | null;
candidates: Array<any> | null;
wmiHint: Array<any> | null;
message: string | null;
};
}
declare namespace App.Modules.PlatformIntegration.Domain.Enums {
export type PlatformLookupStatus = 'found' | 'not_found' | 'error' | 'cached';
}
declare namespace App.Modules.Product.Application.DTOs {
export type AutomotiveCriterionData = {
id: string;
criteria_key: string;
criteria_label: string;
value: string;
unit: string | null;
sort_order: number;
platform_criteria_id: string | null;
created_at: string;
updated_at: string | null;
};
export type AutomotiveCrossReferenceData = {
id: string;
reference_type: App.Modules.Product.Domain.Enums.CrossReferenceType;
reference_number: string;
manufacturer_name: string | null;
platform_cross_ref_id: string | null;
created_at: string;
updated_at: string | null;
};
export type AutomotiveProductMetadataData = {
id: string;
product_id: string;
platform_article_id: string | null;
platform_link_status: App.Modules.Product.Domain.Enums.PlatformLinkStatus;
article_number: string | null;
supplier_brand: string | null;
product_group_name: string | null;
brand_quality_tier: App.Modules.Product.Domain.Enums.BrandQualityTier | null;
article_status: App.Modules.Product.Domain.Enums.AutomotiveArticleStatus;
confidence_score: number;
data_source: string;
weight_kg: string | null;
dimensions: Array<any> | null;
superseded_by_product_id: string | null;
is_universal_fit: boolean;
notes: string | null;
platform_synced_at: string | null;
tire_width: number | null;
tire_aspect_ratio: number | null;
tire_rim_diameter: number | null;
tire_speed_rating: string | null;
tire_load_index: number | null;
tire_season: string | null;
glass_type: string | null;
glass_tinting: string | null;
cross_references: Array<App.Modules.Product.Application.DTOs.AutomotiveCrossReferenceData> | null;
vehicles: Array<App.Modules.Product.Application.DTOs.AutomotiveVehicleData> | null;
criteria: Array<App.Modules.Product.Application.DTOs.AutomotiveCriterionData> | null;
created_at: string;
updated_at: string | null;
};
export type AutomotiveVehicleData = {
id: string;
platform_vehicle_id: string | null;
vehicle_type: App.Modules.Product.Domain.Enums.VehicleTypeRef;
vehicle_display: string;
year_from: number | null;
year_to: number | null;
notes: string | null;
platform_synced_at: string | null;
created_at: string;
updated_at: string | null;
};
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
children: Array<App.Modules.Product.Application.DTOs.CategoryData> | null;
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
export type EnrichedProductData = {
name: string;
brand: string | null;
description: string | null;
classification: Array<any>;
ingredients: Array<any>;
images: Array<any>;
confidence_score: number;
enrichment_tier: string | null;
field_confidence: Array<any> | null;
enrichment_sources: Array<any> | null;
assigned_barcode: string | null;
assigned_barcode_type: string | null;
};
export type EnrichmentResultData = {
id: string;
product_id: string;
product_name: string;
product_barcode: string | null;
product_sku: string | null;
tracking_id: string;
status: string;
enriched_data: App.Modules.Product.Application.DTOs.EnrichedProductData;
enrichment_quality: string;
assigned_barcode: string | null;
reviewed_at: string | null;
reviewed_by: string | null;
accepted_fields: Array<any> | null;
rejection_reason: string | null;
created_at: string;
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
ingredients: Array<App.Modules.Product.Application.DTOs.ProductIngredientData> | null;
key_components: Array<App.Modules.Product.Application.DTOs.ProductKeyComponentData> | null;
usage_instructions: string | null;
warnings: string | null;
contraindications: string | null;
minimum_age: number | null;
age_restriction: App.Modules.Product.Domain.Enums.AgeRestriction | null;
requires_consultation: boolean;
regulatory_code: string | null;
health_claims: Array<App.Modules.Product.Application.DTOs.ProductHealthClaimData> | null;
certifications: Array<App.Modules.Product.Application.DTOs.ProductCertificationData> | null;
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
type: App.Modules.Product.Domain.Enums.ProductType | null;
description: string | null;
sale_price: string | null;
purchase_price: string | null;
cost_price: string | null;
tax_rate: string | null;
default_tax_configuration_id: string | null;
unit: string | null;
barcode: string | null;
is_active: boolean;
is_physical: boolean;
oem_numbers: Array<any> | null;
cross_references: Array<any> | null;
target_margin_override: string | null;
minimum_margin_override: string | null;
created_at: string;
updated_at: string | null;
primary_image_url: string | null;
parapharmacy_metadata: App.Modules.Product.Application.DTOs.ParapharmacyProductMetadataData | null;
automotive_metadata: App.Modules.Product.Application.DTOs.AutomotiveProductMetadataData | null;
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
export type AutomotiveArticleStatus = 'active' | 'discontinued' | 'superseded' | 'pending_review';
export type BrandQualityTier = 'oe' | 'oes' | 'premium_aftermarket' | 'aftermarket' | 'economy';
export type CrossReferenceType = 'oe' | 'oem' | 'trade' | 'iam' | 'ean' | 'internal';
export type DosageForm = 'capsule' | 'tablet' | 'softgel' | 'liquid' | 'powder' | 'cream' | 'gel' | 'lotion' | 'spray' | 'patch' | 'other';
export type EnrichmentReviewStatus = 'pending_review' | 'accepted' | 'rejected';
export type ParapharmacyCategory = 'supplement' | 'cosmetic' | 'medical_device' | 'herbal' | 'baby_care' | 'sports_nutrition' | 'other';
export type PlatformLinkStatus = 'linked' | 'unlinked' | 'pending_match' | 'rejected';
export type ProductType = 'part' | 'service' | 'consumable';
export type VehicleTypeRef = 'pc' | 'cv' | 'mtb' | 'eng' | 'axl' | 'universal';
}
declare namespace App.Modules.Progression.Domain.Enums {
export type GrowthStage = 'launch' | 'stabilize' | 'optimize' | 'expand';
export type MilestoneStatus = 'pending' | 'in_progress' | 'completed' | 'skipped';
export type ModuleReadinessStatus = 'locked' | 'available' | 'ready' | 'active';
export type RecommendationPriority = 'high' | 'medium' | 'low';
export type RecommendationStatus = 'pending' | 'accepted' | 'dismissed';
}
declare namespace App.Modules.Promotion.Application.DTOs {
export type PromotionData = {
id: string;
name: string;
description: string | null;
type: string;
status: string;
priority: number;
is_exclusive: boolean;
stacking_group: string;
starts_at: string | null;
ends_at: string | null;
days_of_week: Array<any> | null;
time_from: string | null;
time_until: string | null;
conditions: Array<any>;
discount_type: string;
discount_value: string;
max_discount_amount: string | null;
applies_to: string;
usage_limit: number | null;
usage_count: number;
metadata: Array<any> | null;
created_at: string;
updated_at: string | null;
};
}
declare namespace App.Modules.Promotion.Domain.Enums {
export type DiscountAppliesTo = 'transaction' | 'qualifying_items' | 'specific_item' | 'cheapest_item';
export type DiscountType = 'percentage' | 'fixed' | 'free_item';
export type PromotionStatus = 'draft' | 'active' | 'paused' | 'expired' | 'archived';
export type PromotionType = 'happy_hour' | 'buy_x_get_y' | 'volume_discount' | 'category_discount' | 'combo_discount';
}
declare namespace App.Modules.Scheduling.Domain.Enums {
export type AppointmentSource = 'manual' | 'phone' | 'online' | 'walkin';
export type AppointmentStatus = 'scheduled' | 'confirmed' | 'checked_in' | 'in_progress' | 'completed' | 'closed' | 'no_show' | 'cancelled';
export type AppointmentType = 'quick_service' | 'inspection' | 'diagnostic' | 'standard_repair' | 'major_repair' | 'maintenance' | 'tire_service' | 'bodywork' | 'other';
export type BayType = 'general' | 'quick_service' | 'alignment' | 'heavy' | 'specialist' | 'flat' | 'other';
export type ReminderChannel = 'email' | 'sms';
export type ReminderDeliveryStatus = 'pending' | 'sent' | 'failed' | 'skipped';
export type WaitType = 'waiter' | 'drop_off' | 'pickup_scheduled';
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
declare namespace App.Modules.SmartPrompts.Domain.Enums {
export type RecommendationContext = 'cart' | 'checkout' | 'reorder';
export type SkinType = 'normal' | 'oily' | 'dry' | 'combination' | 'sensitive';
export type SmartPromptsVariant = 'inline' | 'toast' | 'both' | 'off';
}
declare namespace App.Modules.Taxation.Domain.Enums {
export type CertificateStatus = 'draft' | 'issued' | 'submitted' | 'voided';
export type CompanyTaxStatus = 'REGISTERED' | 'NON_REGISTERED';
export type PartnerTaxStatus = 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT';
export type StackingBehavior = 'SUBTOTAL' | 'TOTAL_INCLUDING_PREVIOUS';
export type TaxApplicationLevel = 'LINE_ITEMS' | 'DOCUMENT_TOTAL';
export type TaxType = 'PERCENTAGE' | 'FIXED_AMOUNT';
export type TransactionType = 'services' | 'goods' | 'rental' | 'rental_hotel' | 'commission' | 'export_services';
export type VatDirection = 'OUTPUT' | 'INPUT';
export type VatExportFormat = 'PDF' | 'CSV' | 'FEC' | 'MTD_JSON' | 'TEIF_XML';
export type VatPeriodStatus = 'OPEN' | 'CLOSED' | 'FILED';
export type VatPeriodType = 'MONTHLY' | 'QUARTERLY' | 'ANNUAL';
export type WithholdingDirection = 'purchase' | 'sales';
}
declare namespace App.Modules.Taxation.Domain.Services {
export type TaxSource = 'line' | 'product' | 'category' | 'company';
}
declare namespace App.Modules.Tenant.Domain.Enums {
export type OnboardingStep = 'company_info' | 'tax_config' | 'payment_methods' | 'payment_repositories' | 'pos_terminal' | 'first_product';
export type SubscriptionPlan = 'trial' | 'starter' | 'professional' | 'enterprise';
export type TenantStatus = 'active' | 'suspended' | 'pending' | 'archived';
}
declare namespace App.Modules.Treasury.Domain.Enums {
export type AllocationMethod = 'fifo' | 'due_date' | 'manual';
export type AllocationType = 'invoice_payment' | 'credit_application' | 'credit_note_application' | 'tolerance_writeoff';
export type FeeType = 'none' | 'fixed' | 'percentage' | 'mixed';
export type InstrumentStatus = 'received' | 'in_transit' | 'deposited' | 'clearing' | 'cleared' | 'bounced' | 'expired' | 'cancelled' | 'collected';
export type PaymentStatus = 'pending' | 'completed' | 'failed' | 'reversed';
export type PaymentType = 'document_payment' | 'advance' | 'refund' | 'credit_application' | 'supplier_payment' | 'pos';
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
units: Array<App.Modules.Uom.Application.DTOs.UnitData>;
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
declare namespace App.Modules.Vehicle.Application.DTOs {
export type VehicleData = {
id: string;
tenant_id: string;
company_id: string;
license_plate: string;
brand: string;
model: string;
year: number | null;
color: string | null;
mileage: number | null;
vin: string | null;
engine_code: string | null;
fuel_type: App.Modules.Vehicle.Domain.Enums.FuelType | null;
transmission: App.Modules.Vehicle.Domain.Enums.TransmissionType | null;
body_type: App.Modules.Vehicle.Domain.Enums.BodyType | null;
notes: string | null;
current_owner_partner_id: string | null;
current_owner_display_name: string | null;
partner_id: string | null;
created_at: string;
updated_at: string | null;
};
export type VehicleMileageReadingData = {
id: string;
vehicle_id: string;
mileage: number;
recorded_at: string;
source: App.Modules.Vehicle.Domain.Enums.MileageSource;
context_document_id: string | null;
context_work_order_id: string | null;
notes: string | null;
};
export type VehicleOwnershipData = {
id: string;
vehicle_id: string;
owner_partner_id: string;
owner_display_name: string;
acquired_at: string;
released_at: string | null;
reason_code: App.Modules.Vehicle.Domain.Enums.OwnershipReason;
notes: string | null;
recorded_by_user_id: string | null;
};
export type VehicleWithCurrentOwnerData = {
id: string;
tenant_id: string;
company_id: string;
license_plate: string;
brand: string;
model: string;
year: number | null;
color: string | null;
mileage: number | null;
vin: string | null;
engine_code: string | null;
fuel_type: App.Modules.Vehicle.Domain.Enums.FuelType | null;
transmission: App.Modules.Vehicle.Domain.Enums.TransmissionType | null;
body_type: App.Modules.Vehicle.Domain.Enums.BodyType | null;
notes: string | null;
current_owner_partner_id: string | null;
current_owner_display_name: string | null;
partner_id: string | null;
created_at: string;
updated_at: string | null;
current_ownership: App.Modules.Vehicle.Application.DTOs.VehicleOwnershipData | null;
recent_mileage_readings: Array<App.Modules.Vehicle.Application.DTOs.VehicleMileageReadingData>;
};
}
declare namespace App.Modules.Vehicle.Domain.Enums {
export type BodyType = 'sedan' | 'hatchback' | 'suv' | 'pickup' | 'van' | 'coupe' | 'convertible' | 'wagon' | 'truck' | 'motorcycle' | 'other';
export type FuelType = 'gasoline' | 'diesel' | 'electric' | 'hybrid' | 'plugin_hybrid' | 'lpg' | 'cng' | 'hydrogen' | 'other';
export type MileageSource = 'service' | 'manual' | 'odometer_photo' | 'external_api' | 'work_order_completion';
export type OwnershipReason = 'initial_registration' | 'purchase' | 'sale' | 'transfer' | 'trade_in' | 'fleet_assignment' | 'fleet_return' | 'other';
export type TransmissionType = 'manual' | 'automatic' | 'semi_automatic' | 'cvt' | 'dual_clutch' | 'other';
}
declare namespace App.Modules.Workshop.Bundle.Application.DTOs {
export type ApplicableBundleData = {
id: string;
code: string;
name: string;
description: string | null;
pricing_mode: App.Modules.Workshop.Bundle.Domain.Enums.BundlePricingMode;
base_price: string | null;
currency: string;
service_interval_km: number | null;
service_interval_months: number | null;
estimated_labor_hours: string | null;
component_count: number;
};
export type BundleExpansionLineData = {
component_type: App.Modules.Workshop.Bundle.Domain.Enums.BundleComponentType;
component_id: string | null;
display_name: string;
quantity: string;
unit: string;
unit_price: string;
line_total: string;
is_optional: boolean;
is_from_fixed_bundle: boolean;
};
export type ServiceBundleComponentData = {
id: string;
bundle_id: string;
component_type: App.Modules.Workshop.Bundle.Domain.Enums.BundleComponentType;
component_id: string;
component_display_name: string;
quantity: string;
unit: string;
override_unit_price: string | null;
is_optional: boolean;
display_order: number;
notes: string | null;
};
export type ServiceBundleData = {
id: string;
tenant_id: string;
company_id: string;
code: string;
name: string;
description: string | null;
pricing_mode: App.Modules.Workshop.Bundle.Domain.Enums.BundlePricingMode;
base_price: string | null;
currency: string;
tax_rate: string | null;
estimated_labor_hours: string | null;
service_interval_km: number | null;
service_interval_months: number | null;
is_active: boolean;
components: Array<App.Modules.Workshop.Bundle.Application.DTOs.ServiceBundleComponentData>;
vehicle_applicabilities: Array<App.Modules.Workshop.Bundle.Application.DTOs.ServiceBundleVehicleApplicabilityData>;
created_at: string;
updated_at: string | null;
};
export type ServiceBundleVehicleApplicabilityData = {
id: string;
bundle_id: string;
platform_vehicle_id: string | null;
vehicle_type: App.Modules.Product.Domain.Enums.VehicleTypeRef | null;
vehicle_display: string | null;
year_from: number | null;
year_to: number | null;
};
}
declare namespace App.Modules.Workshop.Bundle.Domain.Enums {
export type BundleComponentType = 'part' | 'labor' | 'nested_bundle';
export type BundlePricingMode = 'standard' | 'fixed_bundle';
}
declare namespace App.Modules.Workshop.Technician.Application.DTOs {
export type AvailabilityResultData = {
status: string;
reason: string;
};
export type PayrollExportLineData = {
technician_profile_id: string;
user_display_name: string;
employee_code: string;
week_starts_at: string;
total_minutes: number;
work_order_minutes: number;
overtime_minutes: number;
estimated_billable_amount: string | null;
estimated_cost_amount: string | null;
currency: string;
};
export type ScheduleWindowData = {
start: string;
end: string;
};
export type TechnicianCertificationData = {
id: string;
technician_profile_id: string;
certification_name: string;
issuing_body: string | null;
certificate_number: string | null;
issued_at: string | null;
expires_at: string | null;
notes: string | null;
created_at: string;
};
export type TechnicianProfileData = {
id: string;
tenant_id: string;
company_id: string;
user_id: string;
user_display_name: string;
user_email: string | null;
skill_level: App.Modules.Workshop.Technician.Domain.Enums.SkillLevel;
specialties: Array<any>;
hourly_cost_rate: string | null;
hourly_billing_rate: string | null;
currency: string;
weekly_schedule: App.Modules.Workshop.Technician.Application.DTOs.WeeklyScheduleData;
hire_date: string | null;
employment_status: App.Modules.Workshop.Technician.Domain.Enums.EmploymentStatus;
employee_code: string | null;
notes: string | null;
national_id: string | null;
personal_address: string | null;
personal_phone: string | null;
is_active: boolean;
created_at: string;
updated_at: string | null;
};
export type TechnicianTimeEntryData = {
id: string;
technician_profile_id: string;
company_id: string;
started_at: string;
ended_at: string | null;
duration_minutes: number | null;
entry_type: App.Modules.Workshop.Technician.Domain.Enums.TimeEntryType;
work_order_id: string | null;
work_order_status: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderStatus | null;
source: App.Modules.Workshop.Technician.Domain.Enums.TimeEntrySource;
recorded_by_user_id: string | null;
notes: string | null;
};
export type TechnicianTimeOffData = {
id: string;
technician_profile_id: string;
starts_at: string;
ends_at: string;
reason_code: App.Modules.Workshop.Technician.Domain.Enums.TimeOffReason;
is_full_day: boolean;
is_approved: boolean;
approved_by_user_id: string | null;
notes: string | null;
};
export type WeeklyHoursSummaryData = {
technician_profile_id: string;
week_starts_at: string;
total_minutes: number;
minutes_by_entry_type: Array<any>;
work_order_breakdown: Array<App.Modules.Workshop.Technician.Application.DTOs.WorkOrderHoursBreakdownData>;
estimated_billable_amount: string | null;
estimated_cost_amount: string | null;
overtime_minutes: number;
currency: string;
};
export type WeeklyScheduleData = {
mon: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
tue: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
wed: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
thu: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
fri: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
sat: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
sun: Array<App.Modules.Workshop.Technician.Application.DTOs.ScheduleWindowData>;
};
export type WorkOrderHoursBreakdownData = {
work_order_id: string;
minutes: number;
};
}
declare namespace App.Modules.Workshop.Technician.Domain.Enums {
export type EmploymentStatus = 'active' | 'on_leave' | 'terminated';
export type SkillLevel = 'apprentice' | 'junior' | 'general' | 'senior' | 'master' | 'specialist';
export type SpecialtyCode = 'engine_mechanical' | 'engine_diagnostic' | 'transmission' | 'electrical' | 'electronic' | 'suspension' | 'brakes' | 'ac_climate' | 'tires' | 'alignment' | 'bodywork' | 'paint' | 'hybrid_ev' | 'diesel' | 'pre_control' | 'general_service';
export type TimeEntrySource = 'event' | 'manual' | 'import';
export type TimeEntryType = 'work_order' | 'break' | 'non_billable' | 'manual_adjust';
export type TimeOffReason = 'vacation' | 'sick' | 'training' | 'personal' | 'unpaid' | 'other';
}
declare namespace App.Modules.Workshop.WorkOrder.Application.DTOs {
export type ApprovalEvidenceData = {
method: App.Modules.Workshop.WorkOrder.Domain.Enums.ApprovalMethod;
captured_at: string;
captured_by_user_id: string | null;
captured_by_display_name: string | null;
reference: string | null;
};
export type PartNeedData = {
work_order_id: string;
work_order_line_id: string;
product_id: string | null;
display_name: string;
quantity: string;
unit: string;
vehicle_id: string;
vehicle_display_name: string;
preferred_brand: string | null;
urgency: string | null;
notes: string | null;
};
export type WorkOrderAssignmentData = {
id: string;
work_order_id: string;
technician_profile_id: string;
technician_display_name: string | null;
is_lead: boolean;
assigned_at: string;
unassigned_at: string | null;
assigned_by_user_id: string;
notes: string | null;
};
export type WorkOrderData = {
id: string;
tenant_id: string;
company_id: string;
location_id: string | null;
work_order_number: string;
status: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderStatus;
type: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderType;
customer_partner_id: string;
customer_display_name: string;
vehicle_id: string;
vehicle_display_name: string;
opened_by_user_id: string;
primary_technician_profile_id: string | null;
primary_technician_display_name: string | null;
appointment_id: string | null;
mileage_at_intake: number | null;
customer_complaint: string | null;
diagnosis: string | null;
internal_notes: string | null;
scheduled_start_at: string | null;
scheduled_end_at: string | null;
promised_at: string | null;
started_at: string | null;
paused_at: string | null;
completed_at: string | null;
cancelled_at: string | null;
cancellation_reason: App.Modules.Workshop.WorkOrder.Domain.Enums.CancellationReason | null;
approval_captured_at: string | null;
approval_method: App.Modules.Workshop.WorkOrder.Domain.Enums.ApprovalMethod | null;
approval_reference: string | null;
currency: string;
estimated_totals: App.Modules.Workshop.WorkOrder.Application.DTOs.WorkOrderTotalsData;
actual_totals: App.Modules.Workshop.WorkOrder.Application.DTOs.WorkOrderTotalsData;
quote_document_id: string | null;
invoice_document_id: string | null;
lines: Array<App.Modules.Workshop.WorkOrder.Application.DTOs.WorkOrderLineData>;
assignments: Array<App.Modules.Workshop.WorkOrder.Application.DTOs.WorkOrderAssignmentData>;
status_history: Array<App.Modules.Workshop.WorkOrder.Application.DTOs.WorkOrderStatusTransitionData>;
created_at: string;
updated_at: string | null;
};
export type WorkOrderLineData = {
id: string;
work_order_id: string;
line_type: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderLineType;
display_order: number;
product_id: string | null;
service_id: string | null;
service_bundle_id: string | null;
display_name: string;
sku_or_code: string | null;
description: string | null;
quantity: string;
unit: string;
unit_price: string;
tax_rate: string;
discount_percent: string;
line_total_excl_tax: string;
line_total_tax: string;
line_total_incl_tax: string;
labor_hours_estimated: string | null;
labor_hours_actual: string | null;
assigned_technician_profile_id: string | null;
stock_reservation_id: string | null;
is_customer_supplied: boolean;
core_deposit_partner_id: string | null;
core_deposit_status: App.Modules.Workshop.WorkOrder.Domain.Enums.CoreDepositStatus | null;
core_return_of_line_id: string | null;
from_bundle_id: string | null;
is_bundle_informational: boolean;
is_completed: boolean;
completed_at: string | null;
};
export type WorkOrderListItemData = {
id: string;
work_order_number: string;
status: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderStatus;
type: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderType;
customer_display_name: string;
vehicle_display_name: string;
primary_technician_display_name: string | null;
scheduled_start_at: string | null;
promised_at: string | null;
currency: string;
estimated_grand_total: string | null;
actual_grand_total: string | null;
created_at: string;
};
export type WorkOrderStatusTransitionData = {
id: string;
work_order_id: string;
from_status: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderStatus | null;
to_status: App.Modules.Workshop.WorkOrder.Domain.Enums.WorkOrderStatus;
reason_code: string | null;
triggered_by_user_id: string | null;
triggered_at: string;
context: Array<any> | null;
};
export type WorkOrderTotalsData = {
parts_total: string;
labor_total: string;
other_total: string;
tax_total: string;
grand_total: string;
};
}
declare namespace App.Modules.Workshop.WorkOrder.Domain.Enums {
export type ApprovalMethod = 'in_person' | 'phone' | 'email' | 'sms' | 'signed_document';
export type CancellationReason = 'customer_declined' | 'customer_no_show' | 'internal_error' | 'duplicate' | 'vehicle_unfit' | 'other';
export type CoreDepositStatus = 'outstanding' | 'returned' | 'expired' | 'credited';
export type WorkOrderLineType = 'part' | 'labor' | 'core_charge' | 'core_return' | 'sublet' | 'environmental_fee' | 'misc_fee' | 'bundle_header';
export type WorkOrderStatus = 'received' | 'diagnosed' | 'quoted' | 'approved' | 'in_progress' | 'paused' | 'waiting_parts' | 'completed' | 'invoiced' | 'closed' | 'cancelled';
export type WorkOrderType = 'repair' | 'maintenance' | 'inspection' | 'bodywork' | 'tire_service' | 'electrical' | 'diagnostic' | 'other';
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
declare namespace App.Shared.Enums {
export type EnrichmentStatus = 'pending' | 'enriching' | 'completed' | 'failed' | 'rejected' | 'not_enrichable';
}

}

export {};
