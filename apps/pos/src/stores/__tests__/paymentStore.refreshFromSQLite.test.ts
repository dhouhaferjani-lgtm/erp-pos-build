import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useAuthStore } from '@/stores/authStore';
import { makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({ execute: vi.fn(), select: vi.fn() })),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
  upsertPaymentMethods: vi.fn().mockResolvedValue(undefined),
  upsertPaymentRepositories: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

describe('paymentStore.refreshFromSQLite', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: null,
      companyId: 'company-1',
      companies: [],
      token: 'tok',
      serverUrl: 'http://localhost',
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });
  });

  it('T0.5: paymentStore.refreshFromSQLite reads from SQLite and atomically replaces in-memory paymentMethods + paymentRepositories', async () => {
    // Seed in-memory state to a known empty starting point.
    usePaymentStore.setState({ paymentMethods: [], paymentRepositories: [] });

    const sqliteMethods = [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH' }),
      makePaymentMethod({ id: 'pm-card', code: 'CARD' }),
      makePaymentMethod({ id: 'pm-voucher', code: 'STORE_VOUCHER' }),
      makePaymentMethod({ id: 'pm-rest', code: 'RESTAURANT_VOUCHER' }),
      makePaymentMethod({ id: 'pm-gift', code: 'GIFT_CARD' }),
      makePaymentMethod({ id: 'pm-bank', code: 'BANK_TRANSFER' }),
      makePaymentMethod({ id: 'pm-check', code: 'CHECK' }),
      makePaymentMethod({ id: 'pm-cred', code: 'STORE_CREDIT' }),
      makePaymentMethod({ id: 'pm-misc', code: 'OTHER' }),
    ];
    const sqliteRepos = [
      makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
      makePaymentRepository({ id: 'repo-card', type: 'bank_account' }),
      makePaymentRepository({ id: 'repo-voucher', type: 'virtual' }),
      makePaymentRepository({ id: 'repo-bank', type: 'bank_account' }),
      makePaymentRepository({ id: 'repo-safe', type: 'safe' }),
      makePaymentRepository({ id: 'repo-petty', type: 'cash_register' }),
      makePaymentRepository({ id: 'repo-online', type: 'virtual' }),
    ];

    const { getAllPaymentMethods, getAllPaymentRepositories } = await import(
      '@/lib/db/repositories/paymentRepository'
    );
    vi.mocked(getAllPaymentMethods).mockResolvedValueOnce(sqliteMethods);
    vi.mocked(getAllPaymentRepositories).mockResolvedValueOnce(sqliteRepos);

    // Atomicity check: subscribe to the store BEFORE calling refreshFromSQLite,
    // record every transition, then assert no transition ever observed a
    // half-populated state (e.g. methods populated but repositories still
    // empty, or vice versa). A regression that split the `set()` into two
    // sequential calls would surface here as an intermediate snapshot where
    // exactly one of the two arrays had grown.
    const transitions: Array<{ methodCount: number; repoCount: number }> = [];
    const unsubscribe = usePaymentStore.subscribe((state) => {
      transitions.push({
        methodCount: state.paymentMethods.length,
        repoCount: state.paymentRepositories.length,
      });
    });

    await usePaymentStore.getState().refreshFromSQLite();
    unsubscribe();

    // Both arrays populated atomically with the SQLite contents.
    const state = usePaymentStore.getState();
    expect(state.paymentMethods).toHaveLength(9);
    expect(state.paymentRepositories).toHaveLength(7);
    // Pin actual ids — a regression where the wrong array shape was set
    // (e.g. only repositories, swapped, or mapped through a transformer)
    // would fail this.
    expect(state.paymentMethods.map((m) => m.id)).toEqual([
      'pm-cash', 'pm-card', 'pm-voucher', 'pm-rest', 'pm-gift',
      'pm-bank', 'pm-check', 'pm-cred', 'pm-misc',
    ]);
    expect(state.paymentRepositories.map((r) => r.id)).toEqual([
      'repo-cash', 'repo-card', 'repo-voucher', 'repo-bank',
      'repo-safe', 'repo-petty', 'repo-online',
    ]);

    // Atomic replace: at least one transition fired, and EVERY transition
    // either had both arrays empty (the pre-set baseline if Zustand emitted
    // it) or both arrays at their final populated lengths. No transition
    // observed methodCount=9 with repoCount=0 (or methodCount=0 with
    // repoCount=7) — that's the half-populated window a non-atomic set
    // would create.
    expect(transitions.length).toBeGreaterThan(0);
    for (const t of transitions) {
      const halfPopulated =
        (t.methodCount === 9 && t.repoCount === 0) ||
        (t.methodCount === 0 && t.repoCount === 7);
      expect(halfPopulated).toBe(false);
    }
    // Final transition must reflect the populated end-state.
    expect(transitions[transitions.length - 1]).toEqual({
      methodCount: 9,
      repoCount: 7,
    });
  });

  // T1.2 T0.5-residual: equality skip-set (Codex Phase-2 prompt 2.1
  // item 4 — deferred from T0.5). refreshFromSQLite must avoid calling
  // `set` when the SQLite read returns the SAME methods + repositories
  // as the in-memory state. Otherwise every 60 s scheduler tick creates
  // new array references and forces every Zustand subscriber to
  // re-render — a perf bug, not a correctness bug. The check is per-row
  // shallow compare on all primitive fields; getAllPaymentMethods
  // already filters WHERE is_active = 1, so an active→inactive flip
  // shrinks the array (length-changed → set fires).
  it('T1.2: refreshFromSQLite does NOT trigger a snapshot change when methods + repos unchanged', async () => {
    const seedMethod = makePaymentMethod({ id: 'pm-cash', code: 'CASH' });
    const seedRepo = makePaymentRepository({
      id: 'repo-cash',
      type: 'cash_register',
    });
    usePaymentStore.setState({
      paymentMethods: [seedMethod],
      paymentRepositories: [seedRepo],
    });

    const { getAllPaymentMethods, getAllPaymentRepositories } = await import(
      '@/lib/db/repositories/paymentRepository'
    );
    // Return STRUCTURALLY-EQUAL but not reference-equal arrays so a
    // naive Object.is check on the array reference would still trigger
    // `set` — only a per-row shallow compare can recognize they're
    // unchanged.
    vi.mocked(getAllPaymentMethods).mockResolvedValueOnce([
      makePaymentMethod({ id: 'pm-cash', code: 'CASH' }),
    ]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValueOnce([
      makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
    ]);

    // Capture the array references BEFORE refresh — if the skip-set
    // optimization is in place, `set` is never called, and the
    // references remain identical (Object.is) after refresh. Without
    // the optimization, `set` runs unconditionally and produces NEW
    // array references, breaking Object.is.
    const beforeMethods = usePaymentStore.getState().paymentMethods;
    const beforeRepos = usePaymentStore.getState().paymentRepositories;

    await usePaymentStore.getState().refreshFromSQLite();

    const afterMethods = usePaymentStore.getState().paymentMethods;
    const afterRepos = usePaymentStore.getState().paymentRepositories;

    expect(afterMethods).toBe(beforeMethods);
    expect(afterRepos).toBe(beforeRepos);
  });

  it('T1.2: refreshFromSQLite triggers a snapshot change when an id changes', async () => {
    const seedMethod = makePaymentMethod({ id: 'pm-cash', code: 'CASH' });
    const seedRepo = makePaymentRepository({
      id: 'repo-cash',
      type: 'cash_register',
    });
    usePaymentStore.setState({
      paymentMethods: [seedMethod],
      paymentRepositories: [seedRepo],
    });

    const { getAllPaymentMethods, getAllPaymentRepositories } = await import(
      '@/lib/db/repositories/paymentRepository'
    );
    vi.mocked(getAllPaymentMethods).mockResolvedValueOnce([
      makePaymentMethod({ id: 'pm-cash-v2', code: 'CASH' }),
    ]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValueOnce([
      makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
    ]);

    const beforeMethods = usePaymentStore.getState().paymentMethods;
    await usePaymentStore.getState().refreshFromSQLite();
    const afterMethods = usePaymentStore.getState().paymentMethods;

    // Reference must change (set fired) AND content must reflect the
    // new id. If only the content check passed but the reference were
    // the same, that would mean we mutated the array in place — a
    // Zustand-anti-pattern that would NOT trigger subscriber re-renders.
    expect(afterMethods).not.toBe(beforeMethods);
    expect(afterMethods[0]!.id).toBe('pm-cash-v2');
  });

  it('T1.2: refreshFromSQLite triggers a snapshot change when a non-id field (e.g. name) changes on an existing id', async () => {
    const seedMethod = makePaymentMethod({
      id: 'pm-cash',
      code: 'CASH',
      name: 'Cash',
    });
    const seedRepo = makePaymentRepository({
      id: 'repo-cash',
      type: 'cash_register',
    });
    usePaymentStore.setState({
      paymentMethods: [seedMethod],
      paymentRepositories: [seedRepo],
    });

    const { getAllPaymentMethods, getAllPaymentRepositories } = await import(
      '@/lib/db/repositories/paymentRepository'
    );
    // Same id, different name — id-set equality alone would say
    // "unchanged" (BUG: the cashier's UI never reflects the rename
    // until the next ref-changing tick). Per-row shallow compare
    // catches this.
    vi.mocked(getAllPaymentMethods).mockResolvedValueOnce([
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', name: 'Cash (renamed)' }),
    ]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValueOnce([
      makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
    ]);

    const beforeMethods = usePaymentStore.getState().paymentMethods;
    await usePaymentStore.getState().refreshFromSQLite();
    const afterMethods = usePaymentStore.getState().paymentMethods;

    expect(afterMethods).not.toBe(beforeMethods);
    expect(afterMethods[0]!.name).toBe('Cash (renamed)');
  });

  it('T0.5: paymentStore.refreshFromSQLite leaves in-memory state unchanged when SQLite returns zero methods', async () => {
    // Pre-seed in-memory state with the cashier's currently-loaded config.
    // The empty-SQLite branch must NOT clobber this — that would leave the
    // cashier with no payment methods until the next /payment-methods API
    // call lands, which can be minutes if the network is slow.
    const existingMethods = [makePaymentMethod({ id: 'pm-existing', code: 'CASH' })];
    const existingRepos = [makePaymentRepository({ id: 'repo-existing', type: 'cash_register' })];
    usePaymentStore.setState({
      paymentMethods: existingMethods,
      paymentRepositories: existingRepos,
    });

    const { getAllPaymentMethods, getAllPaymentRepositories } = await import(
      '@/lib/db/repositories/paymentRepository'
    );
    vi.mocked(getAllPaymentMethods).mockResolvedValueOnce([]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValueOnce([]);

    await usePaymentStore.getState().refreshFromSQLite();

    // In-memory state is preserved verbatim — no wipe, no replacement with [].
    const state = usePaymentStore.getState();
    expect(state.paymentMethods).toHaveLength(1);
    expect(state.paymentMethods[0]!.id).toBe('pm-existing');
    expect(state.paymentRepositories).toHaveLength(1);
    expect(state.paymentRepositories[0]!.id).toBe('repo-existing');
  });
});
