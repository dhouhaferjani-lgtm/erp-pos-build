import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { HeldTransactionRow } from '@/lib/db/repositories/heldTransactionRepository';
import { runC2BareCartLineDump } from '../c2BareCartLineDump';

const bareProductId = '11111111-1111-4111-8111-111111111111';
const categoryId = '22222222-2222-4222-8222-222222222222';
const compositeProductId = `${bareProductId}_${categoryId}`;

function heldRow(id: string, productId: string, sellableType: 'product' | 'composite_item' = 'product'): HeldTransactionRow {
  return {
    id,
    terminal_id: 'terminal-1',
    operator_id: 'operator-1',
    label: id,
    items_json: JSON.stringify([
      {
        id: `line-${id}`,
        product: {
          id: productId,
          name: 'Coca',
          sku: 'COCA',
          price: '3.0000',
          sellableType,
        },
        quantity: 1,
        unit_price: '3.0000',
        line_total: '3.0000',
        tax_rate: '19.00',
        tax_amount: '0.5700',
      },
    ]),
    transaction_discount_json: null,
    subtotal: '3.0000',
    total: '3.0000',
    item_count: 1,
    held_at: '2026-05-11T08:00:00.000Z',
  };
}

describe('C2 bare cart-line migration', () => {
  let alreadyRan = false;
  let rows: HeldTransactionRow[];
  const deleteHeldTransactions = vi.fn(async (ids: string[]) => {
    rows = rows.filter((row) => !ids.includes(row.id));
  });
  const setBannerPending = vi.fn(async () => {});

  beforeEach(() => {
    alreadyRan = false;
    rows = [];
    deleteHeldTransactions.mockClear();
    setBannerPending.mockClear();
  });

  it('dumps held transactions whose product lines carry bare sellable_id on a Menu tenant', async () => {
    rows = [
      heldRow('pre-c2', bareProductId),
      heldRow('post-c2', compositeProductId),
    ];

    const result = await runC2BareCartLineDump({
      isMenuTenant: true,
      getMigrationRan: async () => alreadyRan,
      setMigrationRan: async () => {
        alreadyRan = true;
      },
      listHeldTransactions: async () => rows,
      deleteHeldTransactions,
      setBannerPending,
    });

    expect(result).toEqual({
      alreadyRan: false,
      deferred: false,
      dumpedCount: 1,
      dumpedIds: ['pre-c2'],
      keptIds: ['post-c2'],
    });
    expect(deleteHeldTransactions).toHaveBeenCalledWith(['pre-c2']);
    expect(setBannerPending).toHaveBeenCalledWith(true);

    const second = await runC2BareCartLineDump({
      isMenuTenant: true,
      getMigrationRan: async () => alreadyRan,
      setMigrationRan: async () => {
        alreadyRan = true;
      },
      listHeldTransactions: async () => rows,
      deleteHeldTransactions,
      setBannerPending,
    });

    expect(second.alreadyRan).toBe(true);
    expect(second.dumpedCount).toBe(0);
    expect(deleteHeldTransactions).toHaveBeenCalledTimes(1);
  });

  it('is a no-op for non-Menu tenants because bare sellable_id is correct for them', async () => {
    rows = [heldRow('retail', bareProductId)];

    const result = await runC2BareCartLineDump({
      isMenuTenant: false,
      getMigrationRan: async () => alreadyRan,
      setMigrationRan: async () => {
        alreadyRan = true;
      },
      listHeldTransactions: async () => rows,
      deleteHeldTransactions,
      setBannerPending,
    });

    expect(result.dumpedCount).toBe(0);
    expect(result.keptIds).toEqual(['retail']);
    expect(alreadyRan).toBe(true);
    expect(deleteHeldTransactions).not.toHaveBeenCalled();
    expect(setBannerPending).not.toHaveBeenCalled();
  });

  it('Codex r6 P2: scopes completion state per-terminal so two terminals on the same DB dump independently', async () => {
    // Terminal A and Terminal B share the company SQLite database
    // (multi-terminal-per-device edge case). Each terminal must run its
    // own one-shot dump. Terminal A's flag must NOT cause Terminal B to
    // skip its own dump.
    const flagsByKey: Record<string, boolean> = {};
    const rowsByTerminal: Record<string, HeldTransactionRow[]> = {
      'terminal-a': [{ ...heldRow('pre-c2-a', bareProductId), terminal_id: 'terminal-a' }],
      'terminal-b': [{ ...heldRow('pre-c2-b', bareProductId), terminal_id: 'terminal-b' }],
    };
    const deletedIds: string[] = [];

    async function migrateTerminal(terminalId: string) {
      const key = `c2_bare_cart_line_dump:${terminalId}`;
      return runC2BareCartLineDump({
        isMenuTenant: true,
        getMigrationRan: async () => flagsByKey[key] === true,
        setMigrationRan: async () => {
          flagsByKey[key] = true;
        },
        listHeldTransactions: async () => rowsByTerminal[terminalId] ?? [],
        deleteHeldTransactions: async (ids: string[]) => {
          deletedIds.push(...ids);
          rowsByTerminal[terminalId] = (rowsByTerminal[terminalId] ?? []).filter(
            (row) => !ids.includes(row.id),
          );
        },
        setBannerPending,
      });
    }

    const a = await migrateTerminal('terminal-a');
    expect(a.dumpedIds).toEqual(['pre-c2-a']);
    expect(flagsByKey['c2_bare_cart_line_dump:terminal-a']).toBe(true);
    // Terminal B's flag is still unset — Terminal A's run must NOT have
    // touched it.
    expect(flagsByKey['c2_bare_cart_line_dump:terminal-b']).toBeUndefined();

    const b = await migrateTerminal('terminal-b');
    expect(b.dumpedIds).toEqual(['pre-c2-b']);
    expect(flagsByKey['c2_bare_cart_line_dump:terminal-b']).toBe(true);
    // Both terminals' stale rows are dumped.
    expect(deletedIds.sort()).toEqual(['pre-c2-a', 'pre-c2-b']);
  });

  it('Codex r7 P2: returns the result intact when banner persistence throws (in-memory holdStore reconcile must still happen)', async () => {
    rows = [heldRow('pre-c2', bareProductId)];
    const failingSetBanner = vi.fn(async () => {
      throw new Error('Tauri Store unavailable');
    });

    const result = await runC2BareCartLineDump({
      isMenuTenant: true,
      getMigrationRan: async () => false,
      setMigrationRan: async () => {},
      listHeldTransactions: async () => rows,
      deleteHeldTransactions,
      setBannerPending: failingSetBanner,
    });

    expect(result.dumpedIds).toEqual(['pre-c2']);
    // The SQLite deletion happened (deleteHeldTransactions was called); the
    // banner persistence failure must not prevent the caller's post-dump
    // branch from reconciling the in-memory holdStore.
    expect(deleteHeldTransactions).toHaveBeenCalledWith(['pre-c2']);
    expect(failingSetBanner).toHaveBeenCalledWith(true);
  });

  it('keeps composite ids, malformed ids, invalid JSON, and composite-item lines', async () => {
    rows = [
      heldRow('composite-product', compositeProductId),
      heldRow('malformed', 'not-a-uuid'),
      { ...heldRow('invalid-json', bareProductId), items_json: '[not-json' },
      heldRow('composite-item', bareProductId, 'composite_item'),
    ];

    const result = await runC2BareCartLineDump({
      isMenuTenant: true,
      getMigrationRan: async () => false,
      setMigrationRan: async () => {},
      listHeldTransactions: async () => rows,
      deleteHeldTransactions,
      setBannerPending,
    });

    expect(result.dumpedCount).toBe(0);
    expect(result.keptIds).toEqual([
      'composite-product',
      'malformed',
      'invalid-json',
      'composite-item',
    ]);
    expect(deleteHeldTransactions).not.toHaveBeenCalled();
    expect(setBannerPending).not.toHaveBeenCalled();
  });
});
