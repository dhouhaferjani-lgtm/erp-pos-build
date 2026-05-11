import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Hoisted mocks — the bootstrapStore consumes these via static getState().
const initializeAuth = vi.fn();
const fetchCompanies = vi.fn();
const initializeTerminal = vi.fn();
const checkHasPins = vi.fn();
// `setState` mock so bootstrapStore.skipWithCache can flip hasPins=false
// for the checking-pins recovery branch (Codex r4 P2). Declared up here
// so it is initialized before vi.mock's hoisted factory body runs.
const operatorSetState = vi.fn();

// Codex review PR #106 round 2 P1: bootstrapStore gates each phase on
// auth/company/terminal state from the existing stores. The mocks must
// expose `isAuthenticated`, `companyId`, and `terminal` so the gating
// reads them; `setAuthState` / `setTerminal` let individual tests vary.
let mockedAuth: {
  isAuthenticated: boolean;
  companyId: string | null;
  companies: { id: string }[];
} = {
  isAuthenticated: true,
  companyId: 'company-1',
  // Default to a non-empty cached list so the happy-path tests skip
  // the network fetchCompanies call (Codex r6 P2: cached-offline boot
  // must not trip on an unconditional fetch).
  companies: [{ id: 'company-1' }],
};
let mockedTerminal: { id: string } | null = { id: 'terminal-1' };
let mockedHasPins: boolean | null = true;

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({
      initialize: initializeAuth,
      fetchCompanies,
      get isAuthenticated() {
        return mockedAuth.isAuthenticated;
      },
      get companyId() {
        return mockedAuth.companyId;
      },
      get companies() {
        return mockedAuth.companies;
      },
    }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: () => ({
      initialize: initializeTerminal,
      get terminal() {
        return mockedTerminal;
      },
    }),
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: () => ({
      checkHasPins,
      get hasPins() {
        return mockedHasPins;
      },
    }),
    // Wrapped so the factory can resolve at hoist time (operatorSetState
    // itself is in TDZ when the factory body runs). Kept for compat with
    // any future skipWithCache plumbing.
    setState: (...args: unknown[]) => operatorSetState(...args),
  },
}));

import { useBootstrapStore } from '@/stores/bootstrapStore';

beforeEach(() => {
  vi.useFakeTimers();
  initializeAuth.mockReset().mockResolvedValue(undefined);
  fetchCompanies.mockReset().mockResolvedValue(undefined);
  initializeTerminal.mockReset().mockResolvedValue(undefined);
  checkHasPins.mockReset().mockResolvedValue(true);
  operatorSetState.mockReset();

  // Default: fully-authenticated, single-company-selected, terminal-hydrated,
  // PINs configured. Tests that exercise the gating / empty-cache paths
  // override.
  mockedAuth = {
    isAuthenticated: true,
    companyId: 'company-1',
    companies: [{ id: 'company-1' }],
  };
  mockedTerminal = { id: 'terminal-1' };
  mockedHasPins = true;
  // checkHasPins side effect: most tests rely on the resolved value plus
  // the post-call hasPins state. The mock implementation here advances the
  // shared `mockedHasPins` snapshot when the inner value matches its
  // resolution, so the bootstrapStore's post-call `hasPins === null`
  // discriminator (Codex r6 P1) sees a stable, controllable value.
  checkHasPins.mockImplementation(async () => {
    return mockedHasPins ?? false;
  });

  // Reset the store between tests so each one starts from `idle`. The
  // `running` flag must also be reset — a hung test that leaves the
  // single-flight guard latched would silently swallow all subsequent
  // start/retry calls.
  useBootstrapStore.setState({
    phase: 'idle',
    error: null,
    lastSuccessfulPhase: null,
    running: false,
  } as never);
});

afterEach(() => {
  vi.useRealTimers();
});

describe('bootstrapStore — happy path', () => {
  it('transitions through every phase to `ready`', async () => {
    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().error).toBeNull();
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('checking-pins');
  });

  it('calls auth/terminal/pins init exactly once on the cached-companies happy path (Codex r6 P2)', async () => {
    // Default mockedAuth.companies is non-empty, so fetchCompanies must
    // be SKIPPED — mirrors the existing AppRouter behaviour where
    // fetchCompanies only fires from the empty-companies bootstrap phase.
    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).toHaveBeenCalledTimes(1);
    expect(checkHasPins).toHaveBeenCalledTimes(1);
  });

  it('Fold: fetching-companies phase fetches when cache is empty', async () => {
    // BootstrapErrorScreen owns empty-company recovery, so bootstrapStore
    // owns the empty-companies fetch instead of leaving it to a second
    // screen-level fetcher.
    mockedAuth = { ...mockedAuth, companies: [] };

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(fetchCompanies).toHaveBeenCalledTimes(1);
  });

  it('Fold: fetching-companies phase surfaces fetch failures via bootstrap error state', async () => {
    mockedAuth = { ...mockedAuth, companies: [] };
    fetchCompanies.mockRejectedValue(
      Object.assign(new Error('companies down'), { name: 'ApiRequestError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-companies');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);
  });

  it('Fold: fetching-companies phase errors when refresh succeeds but cache remains empty', async () => {
    mockedAuth = { ...mockedAuth, companies: [] };
    fetchCompanies.mockResolvedValue(undefined);

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-companies');
  });

  it('cached-offline boot does not regress to bootstrap error even if fetchCompanies would fail (Codex r6 P2 + PR #108 r3 P2)', async () => {
    // Returning cashier with cached TOKEN/USER/COMPANIES boots offline.
    // initialize succeeds (cache hydrated). fetchCompanies WOULD fail
    // (no network) but must not be called when cached companies exist.
    fetchCompanies.mockRejectedValue(
      Object.assign(new Error('offline'), { name: 'TypeError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().error).toBeNull();
    expect(fetchCompanies).not.toHaveBeenCalled();
  });
});

describe('bootstrapStore — failure handling', () => {
  it('halts on the first failing phase and writes BootstrapError with that phase', async () => {
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('network down'), { name: 'TypeError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    const state = useBootstrapStore.getState();
    expect(state.phase).toBe('error');
    expect(state.error?.phase).toBe('fetching-terminal');
    expect(state.error?.errorName).toBe('TypeError');
    // Phases after the failing one must NOT have been called.
    expect(checkHasPins).not.toHaveBeenCalled();
  });

  it('declares authenticating non-recoverable (no cache to fall back to)', async () => {
    // Codex review (PR #106 round 7, P2): recoverable is computed from
    // actual cache availability. Authenticating has no cached fallback;
    // the cashier must log in again. fetching-companies cannot fail
    // from bootstrap any more (PR #108 r3 P2 made it a no-op), so its
    // recoverability declaration is dead — kept in the matrix for
    // completeness but no longer covered here.
    initializeAuth.mockRejectedValue(
      Object.assign(new Error('auth failed'), { name: 'ApiRequestError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().error?.phase).toBe('authenticating');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);
  });

  it('fetching-terminal is recoverable iff terminal cache is non-null at error time (Codex r7 P2)', async () => {
    // Cache populated → recoverable=true.
    mockedTerminal = { id: 'cached-terminal-1' };
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('terminal endpoint hung'), { name: 'FetchTimeoutError' }),
    );

    let p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-terminal');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(true);

    // Cache empty → recoverable=false.
    mockedTerminal = null;
    useBootstrapStore.setState({ phase: 'idle', error: null, lastSuccessfulPhase: null, running: false } as never);

    p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-terminal');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);
  });

  it('coerces a non-allowlisted errorName to "Error" (SAFE_ERROR_NAMES contract from T0.1)', async () => {
    // Mirrors App.tsx:161 — never leak a vendor / API class name to the
    // cashier UI. The bootstrap store is a UI-adjacent surface, so the same
    // discipline applies here.
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('boom'), { name: 'AxiosError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().error?.errorName).toBe('Error');
  });
});

describe('bootstrapStore — retry', () => {
  it('re-runs from `lastSuccessfulPhase + 1`, not from `idle`', async () => {
    // First run: auth succeeds, fetchCompanies succeeds, terminal init fails.
    initializeTerminal.mockRejectedValueOnce(
      Object.assign(new Error('terminal down'), { name: 'ApiRequestError' }),
    );

    let startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('fetching-companies');

    initializeAuth.mockClear();
    fetchCompanies.mockClear();
    initializeTerminal.mockClear().mockResolvedValue(undefined);
    checkHasPins.mockClear();

    // Retry: should NOT re-run auth or fetchCompanies (already succeeded).
    startPromise = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).not.toHaveBeenCalled();
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).toHaveBeenCalledTimes(1);
    expect(checkHasPins).toHaveBeenCalledTimes(1);
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });

  it('increments retryCount on each retry', async () => {
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('persistent failure'), { name: 'ApiRequestError' }),
    );

    let p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().error?.retryCount).toBe(0);

    p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().error?.retryCount).toBe(1);

    p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().error?.retryCount).toBe(2);
  });
});

describe('bootstrapStore — skipWithCache', () => {
  it('falls through the failing fetching-terminal phase when terminal cache is non-null (Codex r7 P2)', async () => {
    // Cache hit + network failure: skipWithCache uses the cached terminal
    // and continues from checking-pins.
    mockedTerminal = { id: 'cached-terminal-1' };
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('terminal endpoint hung'), { name: 'FetchTimeoutError' }),
    );

    let startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-terminal');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(true);

    checkHasPins.mockClear();

    startPromise = useBootstrapStore.getState().skipWithCache();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(checkHasPins).toHaveBeenCalledTimes(1);
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });

  it('treats PIN-state-unknown (hasPins still null after checkHasPins) as a phase failure (Codex r6 P1)', async () => {
    // operatorStore.checkHasPins resolves false silently when both API
    // and SQLite fail. Without the discriminator, the wrapper would mark
    // checking-pins as successful and downstream routing would send the
    // cashier to first-time PinSetupPage — bypassing operator gating
    // entirely. The store's post-call read of `hasPins === null` is the
    // discriminator that catches this.
    mockedHasPins = null; // simulate "could not determine"
    checkHasPins.mockResolvedValue(false); // mirrors the swallowed-error path

    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('checking-pins');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);
  });

  it('checking-pins is non-recoverable — skipWithCache rejects with security caveat (Codex r5 P1)', async () => {
    // PR #106 round-5 P1: hasPins=false routes to PinSetupPage (first-time
    // setup), not PIN entry. If PINs already exist on the terminal, that
    // bypass would let any cashier create a fresh PIN without going through
    // the operator gate. checking-pins is therefore non-recoverable; the
    // "Use cached data" affordance must not be offered for this phase.
    checkHasPins.mockRejectedValue(
      Object.assign(new Error('has-pins endpoint hung'), { name: 'FetchTimeoutError' }),
    );

    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().error?.phase).toBe('checking-pins');
    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);

    await expect(useBootstrapStore.getState().skipWithCache()).rejects.toThrow(
      /not recoverable|cannot skip|checking-pins/i,
    );
  });

  it('throws when recoverable is false (authenticating cannot be skipped)', async () => {
    initializeAuth.mockRejectedValue(
      Object.assign(new Error('login required'), { name: 'ApiRequestError' }),
    );

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(useBootstrapStore.getState().error?.recoverable).toBe(false);

    await expect(useBootstrapStore.getState().skipWithCache()).rejects.toThrow(
      /not recoverable|cannot skip|authenticating/i,
    );
  });
});

describe('bootstrapStore — fresh start clears stale progress (Codex r1 P2)', () => {
  it('start() resets lastSuccessfulPhase so a later early-failing run does not skip phases on retry', async () => {
    // Run 1: clean ride to ready.
    let p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('checking-pins');

    // Run 2: auth now fails. start() must clear lastSuccessfulPhase so a
    // follow-on retry() does not skip past the failing authenticating
    // phase by reading the stale 'checking-pins' marker from run 1.
    initializeAuth.mockReset().mockRejectedValue(
      Object.assign(new Error('auth failed'), { name: 'ApiRequestError' }),
    );
    p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('authenticating');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBeNull();

    // Retry must re-run authenticating (which still fails); it must NOT
    // skip ahead to fetching-companies and beyond.
    initializeAuth.mockClear();
    fetchCompanies.mockClear();
    p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('authenticating');
  });
});

describe('bootstrapStore — phase gating (Codex r2 P1)', () => {
  it('stops at "ready" without entering fetching-companies when isAuthenticated is false (logged-out boot)', async () => {
    // initializeAuth resolves but does NOT flip isAuthenticated true. This
    // is the fresh-install / logged-out boot state — the existing AppRouter
    // would simply render the login screen here, not advance to
    // fetchCompanies (which would 401 without a token).
    mockedAuth = { isAuthenticated: false, companyId: null, companies: [] };

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).not.toHaveBeenCalled();
    expect(checkHasPins).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().error).toBeNull();
  });

  it('halts at fetching-companies when companyId is null and the refreshed companies cache stays empty', async () => {
    // BootstrapErrorScreen owns empty-company recovery, so bootstrap now
    // owns the empty-company fetch. If the refresh still
    // leaves no company choices, the cashier gets the bootstrap error
    // surface instead of falling through to terminal setup.
    mockedAuth = { isAuthenticated: true, companyId: null, companies: [] };

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(fetchCompanies).toHaveBeenCalledTimes(1);
    expect(initializeTerminal).not.toHaveBeenCalled();
    expect(checkHasPins).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-companies');
  });

  it('halts at fetching-companies when orphan recovery refresh leaves the cache empty (Codex PR #108 r4 P2)', async () => {
    // Cached session has a stale non-null companyId from a prior session
    // but an empty companies list. Bootstrap retries the company refresh
    // first and must not advance into terminal init unless the refresh
    // repopulates the cache.
    mockedAuth = { isAuthenticated: true, companyId: 'stale-company-1', companies: [] };

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(fetchCompanies).toHaveBeenCalledTimes(1);
    expect(initializeTerminal).not.toHaveBeenCalled();
    expect(checkHasPins).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error?.phase).toBe('fetching-companies');
  });

  it('stops at "ready" without entering checking-pins when terminal is null', async () => {
    mockedTerminal = null;

    const startPromise = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await startPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    // fetchCompanies skipped because cached companies present (Codex r6 P2).
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).toHaveBeenCalledTimes(1);
    expect(checkHasPins).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });
});

describe('bootstrapStore — single-flight guard (Day 2 AppRouter wiring)', () => {
  it('start() short-circuits when another start() is already running', async () => {
    // Hold `initializeAuth` indefinitely so the first start() stays in
    // flight; that lets the second call observe `running===true`.
    let resolveAuth: (() => void) | undefined;
    initializeAuth.mockImplementation(
      () => new Promise<void>((res) => { resolveAuth = res; }),
    );

    const first = useBootstrapStore.getState().start();

    // The first call has set running=true and is awaiting initializeAuth.
    expect(useBootstrapStore.getState().running).toBe(true);

    const second = useBootstrapStore.getState().start();

    // Second call must early-return without re-invoking the wrapped init.
    await second;
    expect(initializeAuth).toHaveBeenCalledTimes(1);

    // Release the first call so the suite can move on.
    resolveAuth?.();
    await vi.runAllTimersAsync();
    await first;

    expect(useBootstrapStore.getState().running).toBe(false);
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });

  it('retry() short-circuits when start() is already running', async () => {
    let resolveAuth: (() => void) | undefined;
    initializeAuth.mockImplementation(
      () => new Promise<void>((res) => { resolveAuth = res; }),
    );

    const startPromise = useBootstrapStore.getState().start();
    // Mid-flight retry must not race the start orchestrator. The guard
    // protects AppRouter's two useEffects (mount → start, dep-change →
    // retry) from interleaving phase writes when login flips state mid-
    // boot.
    const retryPromise = useBootstrapStore.getState().retry();
    await retryPromise;

    expect(initializeAuth).toHaveBeenCalledTimes(1);

    resolveAuth?.();
    await vi.runAllTimersAsync();
    await startPromise;
  });

  it('running flag clears when a phase rejects (try/finally discipline)', async () => {
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('boom'), { name: 'TypeError' }),
    );

    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().running).toBe(false);

    // A follow-up retry must be able to acquire the guard cleanly.
    initializeTerminal.mockReset().mockResolvedValue(undefined);
    const retryP = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await retryP;

    expect(useBootstrapStore.getState().phase).toBe('ready');
  });
});

describe('bootstrapStore — retry()-from-ready (Day 2 AppRouter wiring)', () => {
  it('is a safe no-op when the state machine is already ready', async () => {
    // Reach ready via start().
    let p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('checking-pins');

    // AppRouter's dep-change useEffect may fire retry() after start()
    // already lands ready. nextPhaseAfter('checking-pins') is null so
    // retry tags the phase as ready (no-op) without re-invoking any
    // init method.
    initializeAuth.mockClear();
    fetchCompanies.mockClear();
    initializeTerminal.mockClear();
    checkHasPins.mockClear();

    p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;

    expect(initializeAuth).not.toHaveBeenCalled();
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).not.toHaveBeenCalled();
    expect(checkHasPins).not.toHaveBeenCalled();
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });

  it('advances past a "ready"-via-gate stop when prerequisites become satisfied (post-login flow)', async () => {
    // Cold-boot: not authenticated yet. The gate stops at authenticating
    // and tags ready (no error).
    mockedAuth = { isAuthenticated: false, companyId: null, companies: [] };

    let p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;
    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('authenticating');

    // Cashier logs in via LoginPage → auth flips true, single company
    // selected. AppRouter's dep-change useEffect fires retry(), which
    // resumes from `nextPhaseAfter('authenticating')` = fetching-companies.
    mockedAuth = { isAuthenticated: true, companyId: 'company-1', companies: [{ id: 'company-1' }] };

    initializeAuth.mockClear();
    fetchCompanies.mockClear();
    initializeTerminal.mockClear();
    checkHasPins.mockClear();

    p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;

    // Auth phase already succeeded — must not re-run. fetchCompanies is
    // skipped because cached companies non-empty (Codex r6 P2). Terminal
    // + PIN phases fire.
    expect(initializeAuth).not.toHaveBeenCalled();
    expect(fetchCompanies).not.toHaveBeenCalled();
    expect(initializeTerminal).toHaveBeenCalledTimes(1);
    expect(checkHasPins).toHaveBeenCalledTimes(1);
    expect(useBootstrapStore.getState().phase).toBe('ready');
  });
});

describe('bootstrapStore — reset (Codex PR #108 r1 P2: logout escape)', () => {
  it('reset() clears phase / error / lastSuccessfulPhase / running back to a clean baseline', async () => {
    // Drive the store into the error state first.
    initializeTerminal.mockRejectedValue(
      Object.assign(new Error('boom'), { name: 'TypeError' }),
    );
    const p = useBootstrapStore.getState().start();
    await vi.runAllTimersAsync();
    await p;

    expect(useBootstrapStore.getState().phase).toBe('error');
    expect(useBootstrapStore.getState().error).not.toBeNull();
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('fetching-companies');

    useBootstrapStore.getState().reset();

    const state = useBootstrapStore.getState();
    expect(state.phase).toBe('ready');
    expect(state.error).toBeNull();
    expect(state.lastSuccessfulPhase).toBeNull();
    expect(state.running).toBe(false);
  });

  it('phase=`ready` after reset lets the dep-change effect restart from authenticating on next login', async () => {
    // After reset, lastSuccessfulPhase=null. A retry() therefore resumes
    // from `nextPhaseAfter(null)` = authenticating — which is exactly
    // the post-logout-then-login flow AppRouter's dep-change effect drives.
    useBootstrapStore.getState().reset();
    initializeAuth.mockClear();

    const p = useBootstrapStore.getState().retry();
    await vi.runAllTimersAsync();
    await p;

    expect(initializeAuth).toHaveBeenCalledTimes(1);
    expect(useBootstrapStore.getState().phase).toBe('ready');
    expect(useBootstrapStore.getState().lastSuccessfulPhase).toBe('checking-pins');
  });
});

describe('bootstrapStore — timeout', () => {
  it('a phase that hangs longer than the per-phase timeout fails with BootstrapTimeoutError name', async () => {
    initializeTerminal.mockImplementation(() => new Promise(() => {})); // never resolves

    const startPromise = useBootstrapStore.getState().start();
    // Advance past the 15s default timeout for the fetching-terminal phase.
    await vi.advanceTimersByTimeAsync(20_000);
    await startPromise;

    const state = useBootstrapStore.getState();
    expect(state.phase).toBe('error');
    expect(state.error?.phase).toBe('fetching-terminal');
    // Day 2: SAFE_ERROR_NAMES was extracted to `lib/safeErrorNames.ts`
    // and extended to include `'BootstrapTimeoutError'`. Engineering and
    // structured logs can now distinguish a phase-level timeout from a
    // generic error label without leaking message content.
    expect(state.error?.errorName).toBe('BootstrapTimeoutError');
  });
});
