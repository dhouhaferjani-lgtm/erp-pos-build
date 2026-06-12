/**
 * Task 11 — single stock gate for every cart ingress.
 *
 * The gate is the ONE guard (lesson L9: canonicalize-before-state-machine-
 * input) every cart ingress routes through. These tests pin its policy
 * matrix (block / warn / off), the exempt short-circuit, the fail-open
 * behaviour when the offline DB is unavailable (browser/non-Tauri dev), and
 * that 'off' never consults availability at all (throwing stubs prove it).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { POSProduct } from '@/types/product';
import type { Terminal } from '@/stores/terminalStore';

const mocks = vi.hoisted(() => ({
  getEffectiveAvailable: vi.fn(),
  getDatabase: vi.fn(),
  terminalGetState: vi.fn(),
  authGetState: vi.fn(),
  productGetState: vi.fn(),
}));

vi.mock('../availability', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../availability')>();
  return { ...actual, getEffectiveAvailable: mocks.getEffectiveAvailable };
});
vi.mock('@/lib/db', () => ({ getDatabase: mocks.getDatabase }));
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: mocks.terminalGetState },
}));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: { getState: mocks.authGetState },
}));
vi.mock('@/stores/productStore', () => ({
  useProductStore: { getState: mocks.productGetState },
  // Real predicate shape: null config → false (non-Menu default in tests).
  hasModule: (
    config: { all_enabled_modules?: string[] } | null,
    moduleName: string,
  ): boolean => config?.all_enabled_modules?.includes(moduleName) ?? false,
}));

import { gateStockForAdd, formatAvailableQty } from '../stockGate';

function product(overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id: 'p1',
    name: 'Test product',
    sku: 'SKU-1',
    sale_price: '10.000',
    stock_quantity: 0,
    sellableType: 'product',
    ...overrides,
  };
}

function setPolicy(policy: 'block' | 'warn' | 'off' | undefined): void {
  const terminal = {
    id: 'term-1',
    ...(policy !== undefined ? { pos_stock_policy: policy } : {}),
  } as Terminal;
  mocks.terminalGetState.mockReturnValue({ terminal });
}

const FAKE_DB = { __brand: 'db' };

beforeEach(() => {
  vi.clearAllMocks();
  mocks.authGetState.mockReturnValue({ companyId: 'co-1' });
  mocks.getDatabase.mockResolvedValue(FAKE_DB);
  mocks.productGetState.mockReturnValue({ companyConfig: null });
});

describe('gateStockForAdd', () => {
  it('Menu-module tenant → PASS even with a stale terminal payload lacking the policy field (never consults availability)', async () => {
    // Codex final-review P1: a cached pre-deploy terminal payload has no
    // pos_stock_policy → '?? block' fallback — which must NEVER freeze a
    // made-to-order tenant. The Menu-module check wins before the policy read.
    setPolicy(undefined);
    mocks.productGetState.mockReturnValue({
      companyConfig: { company_id: 'co-1', all_enabled_modules: ['POS', 'Menu'] },
    });
    mocks.getEffectiveAvailable.mockImplementation(() => {
      throw new Error('availability must not be consulted for Menu tenants');
    });
    mocks.getDatabase.mockImplementation(() => {
      throw new Error('db must not be opened for Menu tenants');
    });

    const result = await gateStockForAdd(product(), null, '1', []);

    expect(result).toEqual({ ok: true, warn: false });
  });

  it('block + insufficient → {ok:false, available}; gate is pure wrt cart', async () => {
    setPolicy('block');
    mocks.getEffectiveAvailable.mockResolvedValue('2.0000');

    const cartLines = Object.freeze([
      Object.freeze({ product: Object.freeze({ id: 'p1' }), quantity: 1 }),
    ]);
    const result = await gateStockForAdd(product(), null, '3.0000', cartLines);

    expect(result).toEqual({ ok: false, available: '2.0000' });
    // The gate only READS cart lines — it passes them through untouched.
    expect(mocks.getEffectiveAvailable).toHaveBeenCalledWith(
      FAKE_DB,
      expect.objectContaining({ id: 'p1' }),
      null,
      cartLines,
    );
  });

  it('block + exactly-enough → ok (boundary: requested == available)', async () => {
    setPolicy('block');
    mocks.getEffectiveAvailable.mockResolvedValue('3.0000');

    const result = await gateStockForAdd(product(), null, '3.0000', []);
    expect(result).toEqual({ ok: true, warn: false });
  });

  it('warn + insufficient → {ok:true, warn:true, available}', async () => {
    setPolicy('warn');
    mocks.getEffectiveAvailable.mockResolvedValue('1.0000');

    const result = await gateStockForAdd(product(), null, '2.0000', []);
    expect(result).toEqual({ ok: true, warn: true, available: '1.0000' });
  });

  it("off → ok WITHOUT consulting availability or the DB (throwing stubs)", async () => {
    setPolicy('off');
    mocks.getEffectiveAvailable.mockImplementation(() => {
      throw new Error('selector must not be consulted when policy is off');
    });
    mocks.getDatabase.mockImplementation(() => {
      throw new Error('db must not be opened when policy is off');
    });

    const result = await gateStockForAdd(product(), null, '999', []);
    expect(result).toEqual({ ok: true, warn: false });
    expect(mocks.getEffectiveAvailable).not.toHaveBeenCalled();
    expect(mocks.getDatabase).not.toHaveBeenCalled();
  });

  it('exempt product (selector returns null) → ok even at block', async () => {
    setPolicy('block');
    mocks.getEffectiveAvailable.mockResolvedValue(null);

    const result = await gateStockForAdd(
      product({ is_physical: false }),
      null,
      '5',
      [],
    );
    expect(result).toEqual({ ok: true, warn: false });
  });

  it('missing policy field on terminal → defaults to block (fail-safe for retail)', async () => {
    setPolicy(undefined);
    mocks.getEffectiveAvailable.mockResolvedValue('0.0000');

    const result = await gateStockForAdd(product(), null, '1', []);
    expect(result).toEqual({ ok: false, available: '0.0000' });
  });

  it('missing terminal entirely → defaults to block', async () => {
    mocks.terminalGetState.mockReturnValue({ terminal: null });
    mocks.getEffectiveAvailable.mockResolvedValue('0.0000');

    const result = await gateStockForAdd(product(), null, '1', []);
    expect(result).toEqual({ ok: false, available: '0.0000' });
  });

  it('passes the variant id through to the availability selector', async () => {
    setPolicy('block');
    mocks.getEffectiveAvailable.mockResolvedValue('4.0000');

    await gateStockForAdd(product({ has_variants: true }), 'vA', '1', []);
    expect(mocks.getEffectiveAvailable).toHaveBeenCalledWith(
      FAKE_DB,
      expect.objectContaining({ id: 'p1' }),
      'vA',
      [],
    );
  });

  it('fails OPEN with a console.warn when the offline DB is unavailable (browser dev)', async () => {
    setPolicy('block');
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    mocks.getDatabase.mockRejectedValue(new Error('not running in Tauri'));

    const result = await gateStockForAdd(product(), null, '99', []);
    expect(result).toEqual({ ok: true, warn: false });
    expect(warnSpy).toHaveBeenCalled();
    warnSpy.mockRestore();
  });

  it('fails OPEN when the availability read itself throws (SQLite blip)', async () => {
    setPolicy('block');
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    mocks.getEffectiveAvailable.mockRejectedValue(new Error('sqlite gone'));

    const result = await gateStockForAdd(product(), null, '99', []);
    expect(result).toEqual({ ok: true, warn: false });
    expect(warnSpy).toHaveBeenCalled();
    warnSpy.mockRestore();
  });

  it('fails OPEN with a console.warn when there is no active company', async () => {
    setPolicy('block');
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    mocks.authGetState.mockReturnValue({ companyId: null });

    const result = await gateStockForAdd(product(), null, '99', []);
    expect(result).toEqual({ ok: true, warn: false });
    expect(warnSpy).toHaveBeenCalled();
    expect(mocks.getDatabase).not.toHaveBeenCalled();
    warnSpy.mockRestore();
  });

  it('compares scale-aware (decimal, not string): "2" requested vs "10.0000" available → ok', async () => {
    setPolicy('block');
    mocks.getEffectiveAvailable.mockResolvedValue('10.0000');

    const result = await gateStockForAdd(product(), null, '2', []);
    expect(result).toEqual({ ok: true, warn: false });
  });
});

describe('formatAvailableQty', () => {
  it('trims trailing zeros for whole numbers', () => {
    expect(formatAvailableQty('5.0000')).toBe('5');
  });

  it('keeps significant decimals', () => {
    expect(formatAvailableQty('2.5000')).toBe('2.5');
    expect(formatAvailableQty('0.2500')).toBe('0.25');
  });

  it('handles zero', () => {
    expect(formatAvailableQty('0.0000')).toBe('0');
  });

  it('passes through integers without a dot', () => {
    expect(formatAvailableQty('7')).toBe('7');
  });
});
