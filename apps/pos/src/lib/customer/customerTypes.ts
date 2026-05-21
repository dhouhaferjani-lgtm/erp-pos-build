export interface CustomerMirrorRow {
  id: string;
  tenant_id: string;
  company_id: string;
  name: string;
  phone: string | null;
  email: string | null;
  tax_number: string | null;
  customer_category: string | null;
  receivable_balance: string;
  credit_balance: string;
  balance_updated_at: string | null;
  is_active: 0 | 1;
  sync_version: string | null;
  updated_at: string | null;
  synced_at: string;
}

export interface CustomerSearchInput {
  tenant_id: string;
  company_id: string;
  query: string;
  limit?: number;
}
