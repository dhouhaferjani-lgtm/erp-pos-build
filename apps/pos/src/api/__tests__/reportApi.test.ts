import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  localZReport: {
    actual_cash: '100.000',
    cash_counts: [],
    expected_cash: '100.000',
    fiscal_hash: 'f'.repeat(64),
    formatted_z_number: 'Z0001',
    generated_at: '2026-05-24T18:00:00.000Z',
    grand_totals: {},
    gross_sales: '0.000',
    has_variance: false,
    id: 'z-1',
    opening_cash: '100.000',
    previous_hash: 'GENESIS',
    report_data: {
      gross_sales: '0.000',
      has_variance: false,
      net_sales: '0.000',
      payment_methods: [],
      sales_count: 0,
      tax_amount: '0.000',
      vat_breakdown: [],
      variance: '0.000',
    },
    shift_id: 'shift-1',
    terminal_id: 'term-1',
    variance: '0.000',
    z_number: 1,
  },
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
  queryAll: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/zReportService', () => ({
  generateZReport: vi.fn().mockResolvedValue(mocks.localZReport),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => ({
      companyId: 'company-1',
      companies: [{ id: 'company-1', currency: 'TND' }],
    })),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn(() => ({
      terminal: {
        id: 'term-1',
        fiscal_schema_version: 3,
      },
    })),
  },
}));

import { generateZReport } from '../reportApi';
import { generateZReport as generateLocalZReport } from '@/lib/offline/zReportService';

describe('reportApi.generateZReport', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('requires fiscal close context for cutover terminals even when caller omits the option', async () => {
    await generateZReport('term-1', 'company-1', 'shift-1', '2026-05-24T08:00:00.000Z', '100.000');

    expect(generateLocalZReport).toHaveBeenCalledWith(
      expect.anything(),
      'term-1',
      'shift-1',
      '2026-05-24T08:00:00.000Z',
      '100.000',
      expect.objectContaining({ requireFiscalEvents: true }),
    );
  });
});
