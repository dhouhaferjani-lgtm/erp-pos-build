/**
 * Fixture factories for Dashboard tests.
 *
 * The dashboard fans out into three TanStack queries:
 *   - `/dashboard/stats` via `api.get<{ data: DashboardStats }>` (reads `.data.data`)
 *   - `/documents?...`  via `api.get<DocumentsResponse>` (reads `.data`)
 *   - `/payments?...`   via `api.get<PaymentsResponse>` (reads `.data`)
 *
 * Types mirror the hand-written interfaces declared in ../Dashboard.tsx.
 */

export interface DashboardStats {
  revenue: {
    current: number
    previous: number
    change: number
  }
  invoices: {
    total: number
    pending: number
    overdue: number
  }
  partners: {
    total: number
    newThisMonth: number
  }
  payments: {
    received: number
    pending: number
  }
}

export interface RecentDocument {
  id: string
  document_number: string
  type: string
  partner_name: string
  total_amount: number | string | null
  status: string
  created_at: string
}

export interface RecentPayment {
  id: string
  payment_number: string
  partner_name: string
  amount: number | string | null
  payment_method_name: string
  created_at: string
}

export function makeDashboardStats(
  overrides: Partial<DashboardStats> = {},
): DashboardStats {
  return {
    revenue: { current: 15000, previous: 12000, change: 25 },
    invoices: { total: 45, pending: 12, overdue: 3 },
    partners: { total: 28, newThisMonth: 5 },
    payments: { received: 35000, pending: 8000 },
    ...overrides,
  }
}

export function makeRecentDocument(
  overrides: Partial<RecentDocument> = {},
): RecentDocument {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    document_number: 'INV-2025-0001',
    type: 'invoice',
    partner_name: 'Acme Corp',
    total_amount: 1500,
    status: 'posted',
    created_at: '2025-01-15T10:00:00Z',
    ...overrides,
  }
}

export function makeRecentPayment(
  overrides: Partial<RecentPayment> = {},
): RecentPayment {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    payment_number: 'PAY-2025-0001',
    partner_name: 'Acme Corp',
    amount: 1500,
    payment_method_name: 'Cash',
    created_at: '2025-01-15T10:00:00Z',
    ...overrides,
  }
}
