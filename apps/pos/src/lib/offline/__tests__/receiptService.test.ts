import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getReceiptByIdempotencyKey: vi.fn().mockResolvedValue(null),
  insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
  scheduleDebouncedSync: vi.fn(),
}));

vi.mock('@/lib/offline/voucherRepository', () => ({
  findByCode: vi.fn().mockResolvedValue(null),
  updateVoucherBalanceAndStatus: vi.fn().mockResolvedValue(undefined),
}));

const incrementPendingCountSpy = vi.fn();
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({
      incrementPendingCount: incrementPendingCountSpy,
    }),
  },
}));

import { createOfflineReceipt } from '../receiptService';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { makeCartItem } from '@/test/helpers';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

const terminalState = {
  terminal_id: '4f0d7e79-6ef6-46b4-85ea-f0c339f3f0db',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'a'.repeat(64),
  last_hash: 'b'.repeat(64),
  hash_sequence: 5,
  manager_pin_throttle_until: null,
  manager_pin_failed_attempts: 0,
  fiscal_schema_version: 3 as const,
};

const seller = {
  name: 'AutoERP Demo SARL',
  taxNumber: 'TN 1234567A',
  countryCode: 'TN',
  street: '1 Avenue Habib Bourguiba',
  city: 'Tunis',
  postalCode: '1000',
};

describe('receiptService — fiscal-event engine wiring', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({
      append: vi.fn().mockResolvedValue({
        id: '8a28b902-1204-4a17-a121-51ea17034ee2',
        tenant_id: '11111111-1111-4111-8111-111111111111',
        company_id: '22222222-2222-4222-8222-222222222222',
        terminal_id: terminalState.terminal_id,
        operator_id: '33333333-3333-4333-8333-333333333333',
        event_type: 'SALE_RECEIPT',
        event_version: 1,
        signature_version: 'hash-chain-integrity-v1',
        sequence_number: 6,
        event_time_device: '2026-05-20T10:00:00Z',
        business_date: '2026-05-20',
        reference_event_id: null,
        reference_document_id: null,
        source_event_class: 'offline_receipts',
        source_event_id: '44444444-4444-4444-8444-444444444444',
        canonical_bytes: '{"business_date":"2026-05-20"}',
        previous_hash: 'a'.repeat(64),
        current_hash: 'c'.repeat(64),
        sync_status: 'pending',
        signature_status: 'not_required',
        created_at: '2026-05-20T10:00:00Z',
      }),
    } as never);
  });

  it('appends SALE_RECEIPT through FiscalEventEngine and mirrors canonical bytes', async () => {
    const db = makeMockDb();

    const result = await createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [makeCartItem({ tax_rate: '0.00' })],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;
    expect(engine.append).toHaveBeenCalledWith(
      db,
      expect.objectContaining({
        event_type: 'SALE_RECEIPT',
        tenant_id: '11111111-1111-4111-8111-111111111111',
        company_id: '22222222-2222-4222-8222-222222222222',
        terminal_id: terminalState.terminal_id,
      }),
    );

    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    expect(inserted.fiscal_hash).toBe('c'.repeat(64));
    expect(inserted.previous_hash).toBe('a'.repeat(64));
    expect(inserted.hash_sequence).toBe(6);
    expect(inserted.canonical_bytes).toBe('{"business_date":"2026-05-20"}');
    expect(result.fiscalHash).toBe('c'.repeat(64));
  });
});
