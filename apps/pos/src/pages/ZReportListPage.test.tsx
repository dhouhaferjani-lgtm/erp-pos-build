import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ZReportListPage } from './ZReportListPage';

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'reports.zList.title': 'Z Reports',
        'reports.zList.columns.zNumber': 'Z Number',
        'reports.zList.columns.generatedAt': 'Generated At',
        'reports.zList.columns.gross': 'Gross Sales',
        'reports.zList.columns.hash': 'Hash',
        'reports.zList.empty': 'No Z reports found.',
        'reports.loading': 'Loading...',
      };
      return map[key] ?? key;
    },
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: number | string) => String(v) }),
}));

const mockFetchZReports = vi.fn();
vi.mock('@/api/reportApi', () => ({
  fetchZReports: (...args: unknown[]) => mockFetchZReports(...args),
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: (selector: (s: { terminal: { id: string } | null }) => unknown) =>
    selector({ terminal: { id: 'term-1' } }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (s: { companyId: string }) => unknown) =>
    selector({ companyId: 'company-1' }),
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const sampleReports = [
  {
    id: 'zr-1',
    terminal_id: 'term-1',
    shift_id: 'shift-1',
    z_number: 2,
    formatted_z_number: 'Z0002',
    generated_at: '2026-04-23T18:00:00+00:00',
    fiscal_hash: 'abc123def456',
    gross_sales: '150.00',
    is_reprint: false,
  },
  {
    id: 'zr-2',
    terminal_id: 'term-1',
    shift_id: 'shift-0',
    z_number: 1,
    formatted_z_number: 'Z0001',
    generated_at: '2026-04-22T18:00:00+00:00',
    fiscal_hash: '111222333444',
    gross_sales: '80.00',
    is_reprint: false,
  },
];

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ZReportListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows loading spinner then renders the list of Z reports', async () => {
    mockFetchZReports.mockResolvedValue(sampleReports);

    render(
      <MemoryRouter>
        <ZReportListPage />
      </MemoryRouter>,
    );

    // Loading state
    expect(screen.getByText('Loading...')).toBeInTheDocument();

    // Wait for data
    await waitFor(() => {
      expect(screen.getByText('Z0002')).toBeInTheDocument();
    });

    expect(screen.getByText('Z0001')).toBeInTheDocument();
    expect(screen.getByText('150.00')).toBeInTheDocument();
    expect(screen.getByText('80.00')).toBeInTheDocument();
    // Hash truncated to 12 chars
    expect(screen.getByText('abc123def456')).toBeInTheDocument();
  });

  it('shows empty state when there are no Z reports', async () => {
    mockFetchZReports.mockResolvedValue([]);

    render(
      <MemoryRouter>
        <ZReportListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText('No Z reports found.')).toBeInTheDocument();
    });
  });

  it('calls fetchZReports with correct terminalId and companyId', async () => {
    mockFetchZReports.mockResolvedValue([]);

    render(
      <MemoryRouter>
        <ZReportListPage />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(mockFetchZReports).toHaveBeenCalledWith('term-1', 'company-1');
    });
  });
});
