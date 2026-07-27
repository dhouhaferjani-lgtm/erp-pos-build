import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeProduct } from '@/test/helpers';

const mocks = vi.hoisted(() => ({
  db: {},
  enqueue: vi.fn(),
  getOpen: vi.fn(),
  recordAudit: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  auth: {
    companyId: 'company-1',
    user: { tenantId: 'tenant-1' },
  },
  terminal: { id: 'terminal-1' },
  connectivity: { isOnline: true },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('sonner', () => ({
  toast: { error: mocks.toastError, success: mocks.toastSuccess },
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue(mocks.db),
}));

vi.mock('@/lib/db/repositories/replenishmentOutboxRepository', () => ({
  enqueueReplenishmentRequest: mocks.enqueue,
}));

vi.mock('@/lib/db/repositories/openReplenishmentRepository', () => ({
  getOpenRequestForProduct: mocks.getOpen,
}));

vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: mocks.recordAudit,
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: { getState: () => mocks.auth },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ terminal: mocks.terminal }) },
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => mocks.connectivity },
}));

import { RequestRefillSheet } from '../RequestRefillSheet';

const product = makeProduct({ id: 'product-1', name: 'Empty Product', stock_quantity: 0 });

beforeEach(() => {
  vi.clearAllMocks();
  mocks.getOpen.mockResolvedValue(null);
  mocks.enqueue.mockResolvedValue(undefined);
  mocks.recordAudit.mockResolvedValue(undefined);
  mocks.auth.companyId = 'company-1';
  mocks.auth.user.tenantId = 'tenant-1';
  mocks.terminal.id = 'terminal-1';
  mocks.connectivity.isOnline = true;
  vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(
    '00000000-0000-4000-8000-000000000001',
  );
});

describe('RequestRefillSheet', () => {
  it('renders without a permission gate', async () => {
    render(
      <RequestRefillSheet isOpen product={product} onClose={vi.fn()} />,
    );

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('replenishment.request_refill')).toBeInTheDocument();
    await waitFor(() => expect(mocks.getOpen).toHaveBeenCalled());
  });

  it('queues a terminal-scoped request and records its audit event', async () => {
    const onClose = vi.fn();
    render(<RequestRefillSheet isOpen product={product} onClose={onClose} />);
    fireEvent.change(screen.getByLabelText('replenishment.quantity_optional'), {
      target: { value: '2.5000' },
    });
    fireEvent.change(screen.getByLabelText('replenishment.note'), {
      target: { value: 'Front shelf empty' },
    });

    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => {
      expect(mocks.enqueue).toHaveBeenCalledWith(mocks.db, {
        client_request_uuid: '00000000-0000-4000-8000-000000000001',
        tenant_id: 'tenant-1',
        company_id: 'company-1',
        terminal_id: 'terminal-1',
        product_id: 'product-1',
        variant_id: null,
        requested_qty: '2.5000',
        note: 'Front shelf empty',
      });
    });
    expect(mocks.recordAudit).toHaveBeenCalledWith({
      type: 'pos.replenishment_requested',
      aggregateType: 'ReplenishmentRequest',
      aggregateId: '00000000-0000-4000-8000-000000000001',
      payload: {
        product_id: 'product-1',
        variant_id: null,
        requested_qty: '2.5000',
      },
    });
    expect(mocks.toastSuccess).toHaveBeenCalledWith('replenishment.request_recorded');
    expect(onClose).toHaveBeenCalled();
  });

  it('shows the already-requested status from the open cache', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });

    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);

    expect(await screen.findByText('replenishment.already_requested')).toBeInTheDocument();
  });

  it('prefills and submits the cached suggested quantity', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    await waitFor(() => expect(quantity).toHaveValue('6.0000'));
    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => {
      expect(mocks.enqueue).toHaveBeenCalledWith(
        mocks.db,
        expect.objectContaining({ requested_qty: '6.0000' }),
      );
    });
  });

  it('keeps the cached suggested quantity editable', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    await waitFor(() => expect(quantity).toHaveValue('6.0000'));
    fireEvent.change(quantity, { target: { value: '4.5000' } });

    expect(quantity).toHaveValue('4.5000');
  });

  it('leaves quantity blank when the cached row has no suggestion', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: null,
    });
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);

    await screen.findByText('replenishment.already_requested');
    expect(screen.getByLabelText('replenishment.quantity_optional')).toHaveValue('');
  });

  it('does not overwrite a user edit while the cache lookup is in flight', async () => {
    let resolveCache: ((value: {
      request_id: string;
      status: string;
      last_requested_at: string;
      suggested_qty: string;
    }) => void) | undefined;
    mocks.getOpen.mockReturnValueOnce(new Promise((resolve) => {
      resolveCache = resolve;
    }));
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    fireEvent.change(quantity, { target: { value: '3.0000' } });
    resolveCache?.({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });

    await screen.findByText('replenishment.already_requested');
    expect(quantity).toHaveValue('3.0000');
  });

  it('rejects quantities with more than four decimal places', async () => {
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');
    fireEvent.change(quantity, { target: { value: '1.00001' } });

    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => expect(quantity).toHaveAttribute('aria-invalid', 'true'));
    expect(mocks.enqueue).not.toHaveBeenCalled();
  });

  it('prefills the suggested quantity at whole-unit precision (quantity_decimals 0)', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });
    const pieceProduct = makeProduct({
      id: 'product-1',
      name: 'Piece Product',
      stock_quantity: 0,
      quantity_decimals: 0,
    });
    render(<RequestRefillSheet isOpen product={pieceProduct} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    await waitFor(() => expect(quantity).toHaveValue('6'));
  });

  it('rejects fractional input then submits a whole quantity (quantity_decimals 0)', async () => {
    const pieceProduct = makeProduct({
      id: 'product-1',
      name: 'Piece Product',
      stock_quantity: 0,
      quantity_decimals: 0,
    });
    render(<RequestRefillSheet isOpen product={pieceProduct} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    fireEvent.change(quantity, { target: { value: '2.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => expect(quantity).toHaveAttribute('aria-invalid', 'true'));
    expect(mocks.enqueue).not.toHaveBeenCalled();

    fireEvent.change(quantity, { target: { value: '3' } });
    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => {
      expect(mocks.enqueue).toHaveBeenCalledWith(
        mocks.db,
        expect.objectContaining({ requested_qty: '3' }),
      );
    });
  });

  it('prefills raw scale-4 when quantity_decimals is null (byte-identical to legacy)', async () => {
    mocks.getOpen.mockResolvedValueOnce({
      request_id: 'server-request-1',
      status: 'pending',
      last_requested_at: '2026-07-10T12:00:00.000Z',
      suggested_qty: '6.0000',
    });
    const nullProduct = makeProduct({
      id: 'product-1',
      name: 'Null Product',
      stock_quantity: 0,
      quantity_decimals: null,
    });
    render(<RequestRefillSheet isOpen product={nullProduct} onClose={vi.fn()} />);
    const quantity = screen.getByLabelText('replenishment.quantity_optional');

    await waitFor(() => expect(quantity).toHaveValue('6.0000'));
  });

  it('surfaces feedback when terminal scope is unavailable', async () => {
    mocks.auth.companyId = '';
    render(<RequestRefillSheet isOpen product={product} onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: 'replenishment.submit' }));

    await waitFor(() => {
      expect(mocks.toastError).toHaveBeenCalledWith('replenishment.scope_unavailable');
    });
    expect(mocks.enqueue).not.toHaveBeenCalled();
  });
});
