import { describe, it, expect, vi, beforeEach } from 'vitest';

// CRITICAL — the no-fetch contract for §0.2: the scan dispatcher path must
// NEVER touch the network. Mock all three network layers and assert none is
// touched by `dispatchScan`.
vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

import { queryOne } from '@/lib/db';
import { apiGet, apiPost } from '@/lib/api';
import { fetch as httpFetch } from '@tauri-apps/plugin-http';
import { parseReceiptToken, dispatchScan } from '../dispatcher';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

const db = {} as import('@tauri-apps/plugin-sql').default;

const RECEIPT_INDEX_ROW: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe('parseReceiptToken', () => {
  it('returns the receipt UUID for a well-formed v:kid:uuid:mac token', () => {
    const result = parseReceiptToken(
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );
    expect(result).toEqual({ receiptUuid: '550e8400-e29b-41d4-a716-446655440000' });
  });

  it('lowercases an upper-case UUID', () => {
    const result = parseReceiptToken(
      '1:keyabc:550E8400-E29B-41D4-A716-446655440000:macblob',
    );
    expect(result).not.toBeNull();
    expect(result?.receiptUuid).toBe('550e8400-e29b-41d4-a716-446655440000');
  });

  it('returns null for a token with the wrong number of fields', () => {
    expect(parseReceiptToken('1:kid:only-three')).toBeNull();
    expect(parseReceiptToken('1:kid:uuid:mac:extra')).toBeNull();
    expect(parseReceiptToken('not-a-token')).toBeNull();
    expect(parseReceiptToken('')).toBeNull();
  });

  it('returns null when the receipt field is not a UUID', () => {
    expect(parseReceiptToken('1:keyabc:not-a-uuid:macblob')).toBeNull();
  });

  it('returns null when the token looks like a plain product barcode', () => {
    expect(parseReceiptToken('1234567890128')).toBeNull(); // EAN-13
    expect(parseReceiptToken('SKU-ABC-001')).toBeNull();
  });
});

describe('dispatchScan', () => {
  it("returns kind='receipt-token' when the token parses, the entry exists, AND the terminal matches", async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    const result = await dispatchScan({
      token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
      db,
      terminalId: 'term-1',
    });

    expect(result.kind).toBe('receipt-token');
    if (result.kind === 'receipt-token') {
      expect(result.entry).toEqual(RECEIPT_INDEX_ROW);
    }
  });

  it("falls through when the parsed receipt is not in the local index", async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(null);

    const result = await dispatchScan({
      token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
      db,
      terminalId: 'term-1',
    });

    expect(result.kind).toBe('fallthrough');
  });

  it("falls through when the receipt was scanned at a DIFFERENT terminal (Codex finding G)", async () => {
    vi.mocked(queryOne).mockResolvedValueOnce({
      ...RECEIPT_INDEX_ROW,
      terminal_id: 'other-term',
    });

    const result = await dispatchScan({
      token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
      db,
      terminalId: 'term-1',
    });

    expect(result.kind).toBe('fallthrough');
  });

  it("falls through when terminalId is null (no active terminal)", async () => {
    const result = await dispatchScan({
      token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
      db,
      terminalId: null,
    });

    expect(result.kind).toBe('fallthrough');
    expect(queryOne).not.toHaveBeenCalled();
  });

  it("falls through immediately for a malformed token (wrong field count)", async () => {
    const result = await dispatchScan({
      token: 'SKU-ABC-001',
      db,
      terminalId: 'term-1',
    });

    expect(result.kind).toBe('fallthrough');
    expect(queryOne).not.toHaveBeenCalled();
  });

  it("falls through immediately when the embedded receipt field is not a UUID", async () => {
    const result = await dispatchScan({
      token: '1:kid:not-a-uuid:mac',
      db,
      terminalId: 'term-1',
    });

    expect(result.kind).toBe('fallthrough');
    expect(queryOne).not.toHaveBeenCalled();
  });

  it('NEVER calls any network layer (apiGet, apiPost, plugin-http fetch, globalThis.fetch)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from scan dispatcher');
    });
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    await dispatchScan({
      token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
      db,
      terminalId: 'term-1',
    });

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });
});
