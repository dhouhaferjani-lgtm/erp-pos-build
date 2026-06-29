/**
 * CustomersPage — Task 29 TDD suite.
 *
 * (a) Page lists synced customers from the customer repository.
 * (b) The skin-profile form captures skin_type + skin_advice_note and submits
 *     them through the write path (updateCustomerSkinProfile + apiPatch).
 * (c) The customer detail panel shows skin_type and skin_advice_note.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks — must appear before component imports
// ---------------------------------------------------------------------------

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: (_ns?: string) => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      // Return the last segment of the key for simple assertions.
      const label = key.split('.').pop() ?? key;
      if (opts && typeof opts === 'object') {
        return Object.entries(opts).reduce(
          (str, [k, v]) => str.replace(`{{${k}}}`, String(v)),
          label,
        );
      }
      return label;
    },
    i18n: { language: 'fr', changeLanguage: vi.fn() },
  }),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn().mockResolvedValue([]),
  apiPatch: vi.fn().mockResolvedValue({}),
  apiPost: vi.fn().mockResolvedValue({}),
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'error'),
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/customerRepository', () => ({
  listCustomers: vi.fn(),
  updateCustomerSkinProfile: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/pendingCustomerRepository', () => ({
  enqueuePendingCustomer: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn((selector?: (s: unknown) => unknown) => {
    const state = {
      user: { tenantId: 'tenant-1', id: 'user-1', name: 'Test User' },
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Test Company', currency: 'EUR' }],
    };
    return selector ? selector(state) : state;
  }),
}));

// ---------------------------------------------------------------------------
// Deferred imports (after mocks)
// ---------------------------------------------------------------------------

import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { listCustomers, updateCustomerSkinProfile } from '@/lib/db/repositories/customerRepository';
import { apiPatch } from '@/lib/api';

// Component under test
import { CustomersPage } from '../CustomersPage';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeCustomer(overrides: Partial<CustomerMirrorRow> = {}): CustomerMirrorRow {
  return {
    id: 'customer-uuid-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    name: 'Marie Curie',
    phone: '+33 6 00 00 00 01',
    email: 'marie@example.test',
    tax_number: null,
    customer_category: 'para-pharmacy',
    receivable_balance: '0.000',
    credit_balance: '0.000',
    credit_limit: null,
    payment_terms_days: null,
    charge_account_enabled: false,
    charge_policy_version: null,
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: null,
    is_active: 1,
    sync_version: null,
    updated_at: '2026-06-28T10:00:00.000Z',
    synced_at: '2026-06-28T10:01:00.000Z',
    skin_type: null,
    skin_advice_note: null,
    ...overrides,
  };
}

// ---------------------------------------------------------------------------
// (a) List synced customers
// ---------------------------------------------------------------------------

describe('CustomersPage — list', () => {
  beforeEach(() => {
    vi.mocked(listCustomers).mockResolvedValue([
      makeCustomer({ id: 'c1', name: 'Marie Curie', skin_type: 'dry' }),
      makeCustomer({ id: 'c2', name: 'Sophie Germain', skin_type: 'sensitive', skin_advice_note: 'Avoid fragrances' }),
    ]);
  });

  it('renders the page title', async () => {
    await act(async () => render(<CustomersPage />));
    expect(screen.getByText('pageTitle')).toBeInTheDocument();
  });

  it('lists synced customers by name', async () => {
    await act(async () => render(<CustomersPage />));
    await waitFor(() => {
      expect(screen.getByText('Marie Curie')).toBeInTheDocument();
      expect(screen.getByText('Sophie Germain')).toBeInTheDocument();
    });
  });

  it('calls listCustomers with the active tenant + company scope', async () => {
    await act(async () => render(<CustomersPage />));
    await waitFor(() => {
      expect(listCustomers).toHaveBeenCalledWith(
        expect.anything(), // db
        'tenant-1',
        'company-1',
        expect.any(Number),
      );
    });
  });
});

// ---------------------------------------------------------------------------
// (c) Detail view shows skin_type and skin_advice_note
// ---------------------------------------------------------------------------

describe('CustomersPage — detail view', () => {
  const customerWithSkin = makeCustomer({
    id: 'detail-c1',
    name: 'Isabelle Adjani',
    skin_type: 'oily',
    skin_advice_note: 'Prefer oil-free products',
  });

  beforeEach(() => {
    vi.mocked(listCustomers).mockResolvedValue([customerWithSkin]);
  });

  it('shows skin_type and skin_advice_note when a customer is selected', async () => {
    await act(async () => render(<CustomersPage />));

    // Wait for the list to populate, then click the customer row.
    await waitFor(() => screen.getByText('Isabelle Adjani'));
    await act(async () => {
      fireEvent.click(screen.getByText('Isabelle Adjani'));
    });

    // The detail panel should show the skin fields.
    // 'oily' may appear in both the list row and the detail panel (jsdom has
    // no CSS, so hidden elements stay in DOM). Use getAllByText for the skin
    // type value; check the unique skin_advice_note to confirm detail panel.
    await waitFor(() => {
      // skin_type value rendered via i18n key 'skin_type.oily' → t returns 'oily'
      expect(screen.getAllByText('oily').length).toBeGreaterThan(0);
      // skin_advice_note only appears in the detail panel (not in the list row).
      expect(screen.getByText('Prefer oil-free products')).toBeInTheDocument();
    });
  });
});

// ---------------------------------------------------------------------------
// (b) Skin-profile edit form submits through write path
// ---------------------------------------------------------------------------

describe('CustomersPage — skin profile edit form', () => {
  const syncedCustomer = makeCustomer({
    id: 'edit-c1',
    name: 'Simone Veil',
    skin_type: null,
    skin_advice_note: null,
  });

  beforeEach(() => {
    vi.mocked(listCustomers).mockResolvedValue([syncedCustomer]);
    vi.mocked(updateCustomerSkinProfile).mockResolvedValue(undefined);
    vi.mocked(apiPatch).mockResolvedValue({});
  });

  it('opens the skin-profile edit form when the edit button is clicked', async () => {
    await act(async () => render(<CustomersPage />));
    await waitFor(() => screen.getByText('Simone Veil'));

    await act(async () => { fireEvent.click(screen.getByText('Simone Veil')); });
    await waitFor(() => {
      // Edit skin profile button
      expect(screen.getByRole('button', { name: /editSkinProfile/i })).toBeInTheDocument();
    });

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /editSkinProfile/i }));
    });

    // Form fields should now be visible
    await waitFor(() => {
      expect(screen.getByRole('combobox', { name: /skinType/i })).toBeInTheDocument();
      expect(screen.getByRole('textbox', { name: /skinAdviceNote/i })).toBeInTheDocument();
    });
  });

  it('submits skin_type and skin_advice_note through updateCustomerSkinProfile + apiPatch', async () => {
    await act(async () => render(<CustomersPage />));
    await waitFor(() => screen.getByText('Simone Veil'));

    // Open detail → open edit form
    await act(async () => { fireEvent.click(screen.getByText('Simone Veil')); });
    await waitFor(() => screen.getByRole('button', { name: /editSkinProfile/i }));
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /editSkinProfile/i }));
    });

    // Fill the form
    await waitFor(() => screen.getByRole('combobox', { name: /skinType/i }));
    await act(async () => {
      fireEvent.change(screen.getByRole('combobox', { name: /skinType/i }), {
        target: { value: 'sensitive' },
      });
      fireEvent.change(screen.getByRole('textbox', { name: /skinAdviceNote/i }), {
        target: { value: 'Avoid perfumed products' },
      });
    });

    // Submit
    const saveButton = screen.getByRole('button', { name: /saveSkinProfile/i });
    await act(async () => { fireEvent.click(saveButton); });

    await waitFor(() => {
      // Local SQLite update
      expect(updateCustomerSkinProfile).toHaveBeenCalledWith(
        expect.anything(), // db
        'tenant-1',
        'company-1',
        'edit-c1',
        'sensitive',
        'Avoid perfumed products',
      );
      // Server PATCH
      expect(apiPatch).toHaveBeenCalledWith(
        '/partners/edit-c1',
        { skin_type: 'sensitive', skin_advice_note: 'Avoid perfumed products' },
      );
    });
  });
});
