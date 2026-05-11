// Fixture: a CREATE TABLE on a guarded resource (`payment_methods`) that
// includes BOTH tenant_id and company_id columns. The scanner MUST NOT flag
// this — it satisfies the tenant_and_company expected scope.

export const createPaymentMethodsTable = `
  CREATE TABLE payment_methods (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    company_id TEXT NOT NULL,
    name TEXT NOT NULL,
    code TEXT NOT NULL
  )
`;
