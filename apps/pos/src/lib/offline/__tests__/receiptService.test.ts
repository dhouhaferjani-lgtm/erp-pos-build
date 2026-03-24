import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the fiscal hash service - must be before imports
vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('mock-fiscal-hash-abc123'),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
  advanceHashChain: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
}));

import { createOfflineReceipt } from '../receiptService';
import { computeFiscalHash } from '@/lib/fiscal/hashService';
import { getTerminalState, advanceHashChain } from '@/lib/db/repositories/terminalStateRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { makeCartItem } from '@/test/helpers';
import type { FiscalHashInput } from '@/lib/fiscal/hashService';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

const terminalState = {
  terminal_id: 'terminal-1',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'seed-abc',
  last_hash: 'previous-hash-xyz',
  hash_sequence: 5,
};

describe('receiptService - createOfflineReceipt', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
  });

  it('computes subtotal, tax, and total correctly', async () => {
    const items = [
      makeCartItem({ id: 'item-1', line_total: '100.00', tax_amount: '20.00' }),
      makeCartItem({ id: 'item-2', line_total: '50.00', tax_amount: '5.00' }),
    ];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 200,
    });

    expect(result.subtotal).toBe('150.00');
    expect(result.taxAmount).toBe('25.00');
    // Tax-inclusive: total = subtotal (tax already included in line_total)
    expect(result.total).toBe('150.00');
    expect(result.changeDue).toBe(50);
  });

  it('generates sequential receipt number from terminal state', async () => {
    const items = [makeCartItem()];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 20,
    });

    const year = new Date().getFullYear();
    expect(result.receiptNumber).toBe(`MAIN-T001-${year}-00000006`);
  });

  it('throws when terminal state is not initialized', async () => {
    vi.mocked(getTerminalState).mockResolvedValue(null);

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: [makeCartItem()],
        currency: 'EUR',
        paymentMethodId: 'pm-1',
        paymentRepositoryId: 'repo-1',
        tenderedAmount: 20,
      }),
    ).rejects.toThrow('Terminal hash chain not initialized');
  });

  it('applies transaction discount', async () => {
    const items = [makeCartItem({ line_total: '100.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 200,
      transactionDiscount: { type: 'fixed', value: '20.00', reason: 'Loyalty' },
    });

    expect(result.total).toBe('80.00');
    expect(result.discountAmount).toBe('20.00');
  });

  it('total never goes below zero with large discount', async () => {
    const items = [makeCartItem({ line_total: '10.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 0,
      transactionDiscount: { type: 'fixed', value: '999.00' },
    });

    expect(result.total).toBe('0.00');
  });

  it('calculates correct change due', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 50,
    });

    expect(result.changeDue).toBe(20);
  });

  it('wraps insert and hash chain advance in a transaction', async () => {
    const items = [makeCartItem()];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 20,
    });

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    expect(executeCalls).toContain('BEGIN TRANSACTION');
    expect(executeCalls).toContain('COMMIT');
    expect(insertOfflineReceipt).toHaveBeenCalledOnce();
    expect(advanceHashChain).toHaveBeenCalledWith(
      db,
      'terminal-1',
      'mock-fiscal-hash-abc123',
      6,
    );
  });

  it('rolls back transaction if insertOfflineReceipt fails', async () => {
    vi.mocked(insertOfflineReceipt).mockRejectedValueOnce(new Error('insert failed'));

    const items = [makeCartItem()];

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-1',
        paymentRepositoryId: 'repo-1',
        tenderedAmount: 20,
      }),
    ).rejects.toThrow('insert failed');

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    expect(executeCalls).toContain('BEGIN TRANSACTION');
    expect(executeCalls).toContain('ROLLBACK');
    expect(executeCalls).not.toContain('COMMIT');
  });

  it('calls computeFiscalHash with previous hash from terminal state', async () => {
    const items = [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 50,
    });

    expect(computeFiscalHash).toHaveBeenCalledWith(
      expect.objectContaining({
        previousHash: 'previous-hash-xyz',
        total: '50.00',
        currency: 'EUR',
      }),
    );
  });

  it('returns the fiscal hash in the result', async () => {
    const items = [makeCartItem()];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
    });

    expect(result.fiscalHash).toBe('mock-fiscal-hash-abc123');
  });

  it('computes VAT breakdown and passes it to fiscal hash', async () => {
    const items = [
      makeCartItem({ id: 'i1', tax_rate: '20', tax_amount: '20.00', line_total: '100.00' }),
      makeCartItem({ id: 'i2', tax_rate: '20', tax_amount: '10.00', line_total: '50.00' }),
      makeCartItem({ id: 'i3', tax_rate: '10', tax_amount: '3.00', line_total: '30.00' }),
    ];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 250,
    });

    expect(computeFiscalHash).toHaveBeenCalledOnce();
    const hashInput = vi.mocked(computeFiscalHash).mock.calls[0]![0] as FiscalHashInput;

    expect(hashInput.vatBreakdown).toHaveLength(2);
    expect(hashInput.vatBreakdown[0]).toEqual({ rate: '10', amount: '3.00' });
    expect(hashInput.vatBreakdown[1]).toEqual({ rate: '20', amount: '30.00' });
  });
});
