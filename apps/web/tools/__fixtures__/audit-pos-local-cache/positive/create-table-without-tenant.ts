// Fixture: a CREATE TABLE on a guarded resource (`payment_methods`) without
// tenant_id or company_id columns. The audit-pos-local-cache scanner MUST
// flag this as a tenant-isolation gap (pattern_type = create_table_without_tenant).

export const createPaymentMethodsTable = `
  CREATE TABLE payment_methods (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT NOT NULL
  )
`;
