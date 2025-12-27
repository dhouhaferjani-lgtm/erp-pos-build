export interface AdminAuthResponse {
  id: string
  email: string
  name: string
  role: 'super_admin'
  token?: string
}

export interface AdminDashboardStats {
  total_tenants: number
  active_tenants: number
  trial_tenants: number
  suspended_tenants: number
  expired_tenants: number
  total_users: number
  total_companies: number
  total_revenue: number
  monthly_revenue: number
  new_signups_this_month: number
}

export interface TenantListItem {
  id: string
  name: string
  email: string | null
  status: 'active' | 'trial' | 'suspended' | 'expired'
  subscription: {
    plan: {
      id: string
      name: string
    }
    status: 'active' | 'trial' | 'cancelled' | 'expired'
    trial_ends_at: string | null
    current_period_end: string | null
  } | null
  created_at: string
}

export interface TenantDetail extends TenantListItem {
  owner: {
    id: string
    name: string
    email: string
  } | null
  users_count: number
  locations_count: number
  storage_used: number
  updated_at: string
}

export interface AdminAuditLog {
  id: string
  admin_id: string
  admin_name: string
  tenant_id: string | null
  tenant_name: string | null
  action: string
  details: Record<string, unknown>
  ip_address: string | null
  created_at: string
}

// Billing Types
export interface BillingDashboardStats {
  mrr: number
  arr: number
  active_subscriptions: number
  trial_subscriptions: number
  past_due_subscriptions: number
  revenue_this_month: number
  outstanding_invoices: number
  overdue_invoices_count: number
}

export interface PaymentProviderStatus {
  name: string
  available: boolean
  configured: boolean
  type: 'online' | 'manual'
}

export interface Plan {
  id: string
  code: string
  name: string
  description: string | null
  limits: Record<string, unknown>
  price_monthly: string | null
  price_yearly: string | null
  currency: string
  trial_days: number
  is_active: boolean
  is_public: boolean
  display_order: number
  stripe_monthly_price_id: string | null
  stripe_yearly_price_id: string | null
}

export type SubscriptionStatus =
  | 'trial'
  | 'active'
  | 'past_due'
  | 'unpaid'
  | 'paused'
  | 'cancelling'
  | 'cancelled'
  | 'expired'

export interface Subscription {
  id: string
  tenant_id: string
  plan_id: string
  status: SubscriptionStatus
  billing_cycle: 'monthly' | 'yearly'
  price: string | null
  currency: string
  trial_ends_at: string | null
  current_period_start: string | null
  current_period_end: string | null
  cancelled_at: string | null
  ends_at: string | null
  stripe_subscription_id: string | null
  stripe_customer_id: string | null
  last_payment_at: string | null
  next_payment_due: string | null
  notes: string | null
  created_at: string
  updated_at: string
  tenant?: {
    id: string
    name: string
    email: string | null
  }
  plan?: Plan
}

export type InvoiceStatus =
  | 'draft'
  | 'pending'
  | 'sent'
  | 'paid'
  | 'partially_paid'
  | 'overdue'
  | 'cancelled'
  | 'refunded'

export interface Invoice {
  id: string
  tenant_id: string
  subscription_id: string | null
  number: string
  status: InvoiceStatus
  subtotal: string
  tax_amount: string
  discount_amount: string
  total: string
  amount_paid: string
  amount_due: string
  currency: string
  tax_rate: string
  billing_name: string | null
  billing_email: string | null
  billing_address: Record<string, string>
  invoice_date: string
  due_date: string
  paid_at: string | null
  sent_at: string | null
  period_start: string | null
  period_end: string | null
  pdf_path: string | null
  notes: string | null
  created_at: string
  tenant?: {
    id: string
    name: string
    email: string | null
  }
  subscription?: {
    id: string
    plan?: Plan
  }
  items?: InvoiceItem[]
}

export interface InvoiceItem {
  id: string
  invoice_id: string
  description: string
  long_description: string | null
  quantity: string
  unit_price: string
  amount: string
  tax_rate: string
  tax_amount: string
  period_start: string | null
  period_end: string | null
}

export type PaymentStatus =
  | 'pending'
  | 'processing'
  | 'requires_action'
  | 'succeeded'
  | 'failed'
  | 'cancelled'
  | 'refunded'
  | 'partially_refunded'

export type PaymentProvider =
  | 'stripe'
  | 'paypal'
  | 'klarna'
  | 'sepa_transfer'
  | 'flouci'
  | 'click_to_pay'
  | 'konnect'
  | 'manual'
  | 'bank_transfer'
  | 'cash'
  | 'check'

export interface Payment {
  id: string
  tenant_id: string
  invoice_id: string | null
  provider: PaymentProvider
  provider_payment_id: string | null
  status: PaymentStatus
  amount: string
  fee: string
  net_amount: string
  currency: string
  refunded_amount: string
  payment_method_type: string | null
  payment_method_details: Record<string, unknown>
  reference_number: string | null
  payment_date: string | null
  recorded_by: string | null
  error_code: string | null
  error_message: string | null
  paid_at: string | null
  refunded_at: string | null
  created_at: string
  tenant?: {
    id: string
    name: string
    email: string | null
  }
  invoice?: Invoice
  recorder?: {
    id: string
    name: string
    email: string
  }
}

export interface Refund {
  id: string
  payment_id: string
  provider_refund_id: string | null
  status: PaymentStatus
  amount: string
  currency: string
  reason: string | null
  notes: string | null
  initiated_by: string | null
  refunded_at: string | null
  created_at: string
}

// API Request Types
export interface CreateInvoiceRequest {
  tenant_id: string
  items: Array<{
    description: string
    amount: number
    quantity?: number
  }>
  notes?: string
  due_date?: string
}

export interface RecordPaymentRequest {
  tenant_id: string
  invoice_id?: string
  amount: number
  currency?: string
  provider: 'manual' | 'bank_transfer' | 'cash' | 'check'
  reference_number?: string
  payment_date?: string
  notes?: string
}

export interface RefundPaymentRequest {
  amount?: number
  reason?: string
}

export interface UpdateSubscriptionRequest {
  status?: 'active' | 'paused' | 'cancelled'
  notes?: string
}

// Plan Usage and Limits Types
export interface UsageStat {
  current: number
  limit: number
  percent: number
}

export interface PlanUsageStats {
  companies: UsageStat
  locations: UsageStat
  users: UsageStat
  products: UsageStat
  partners: UsageStat
  documents_this_month: UsageStat
}

export interface EnabledModules {
  sales: boolean
  inventory: boolean
  treasury: boolean
  accounting: boolean
  partners: boolean
  workshop: boolean
  vehicles: boolean
  reporting: boolean
  multi_location: boolean
  ecommerce: boolean
  hr: boolean
}

export interface EnabledFeatures {
  credit_notes: boolean
  delivery_notes: boolean
  document_conversion: boolean
  pdf_export: boolean
  excel_export: boolean
  email_notifications: boolean
  sms_notifications: boolean
  api_access: boolean
  webhooks: boolean
  custom_branding: boolean
  priority_support: boolean
  audit_trail: boolean
  backup: boolean
  multi_currency: boolean
  landed_cost: boolean
  margin_analysis: boolean
  bank_reconciliation: boolean
  fiscal_compliance: boolean
}

export interface UserOverage {
  extra_users: number
  price_per_user: number
  total_overage: number
}

export interface PlanSummary {
  plan: {
    code: string
    name: string
    description: string
    price_monthly: number | null
    price_yearly: number | null
    currency: string
  } | null
  subscription: {
    status: SubscriptionStatus
    billing_cycle: 'monthly' | 'yearly'
    current_period_end: string | null
    trial_ends_at: string | null
    is_on_trial: boolean
  } | null
  usage: PlanUsageStats
  modules: EnabledModules
  features: EnabledFeatures
  overage: UserOverage
}

export interface TenantDetailResponse {
  tenant: TenantListItem
  stats: {
    users_count: number
    companies_count: number
    locations_count: number
  }
  plan_summary: PlanSummary
}

// User Management Types
export interface AdminUser {
  id: string
  tenant_id: string
  name: string
  email: string
  status: 'active' | 'inactive' | 'suspended'
  email_verified_at: string | null
  created_at: string
  updated_at: string
  tenant?: {
    id: string
    name: string
  }
}

export interface AdminUserDetail {
  user: AdminUser
  memberships: Array<{
    id: string
    company_id: string
    company_name: string
    role: string
    is_primary: boolean
    status: string
  }>
}
