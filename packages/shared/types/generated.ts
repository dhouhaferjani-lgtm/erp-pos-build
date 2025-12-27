declare namespace App.Modules.Accounting.Domain.Enums {
export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
export type JournalEntryStatus = 'draft' | 'posted' | 'reversed';
export type OpeningBatchStatus = 'DRAFT' | 'VALIDATED' | 'LOCKED';
export type OpeningBatchType = 'ACCOUNTING' | 'INVENTORY' | 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS';
export type OpeningImportRowStatus = 'PENDING' | 'VALID' | 'INVALID' | 'SKIPPED' | 'POSTED';
export type SystemAccountPurpose = 'bank' | 'cash' | 'customer_receivable' | 'supplier_advance' | 'inventory' | 'uninvoiced_revenue' | 'supplier_payable' | 'customer_advance' | 'vat_collected' | 'vat_deductible' | 'product_revenue' | 'service_revenue' | 'cost_of_goods_sold' | 'purchase_expenses' | 'office_expense' | 'travel_expense' | 'meals_expense' | 'utilities_expense' | 'general_expense' | 'retained_earnings' | 'opening_balance_equity' | 'payment_tolerance_expense' | 'payment_tolerance_income' | 'sales_return' | 'realized_fx_gain' | 'realized_fx_loss' | 'sales_discount';
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
export type ImportType = 'partners' | 'products' | 'stock_levels' | 'opening_balances';
}
declare namespace App.Modules.Inventory.Application.DTOs {
export type StockLevelData = {
id: string;
location_id: string;
location_name: string;
quantity: string;
reserved: string;
available: string;
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
};
}
declare namespace App.Modules.Product.Domain.Enums {
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
