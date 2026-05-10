import { create } from 'zustand';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { withTimeout } from '@/lib/bootstrap/withTimeout';

/**
 * T2.4 — bootstrap state machine that wraps the four existing init paths
 * with explicit timeouts, retry, and skip-with-cache semantics. This Day 1
 * landing introduces the store + the withTimeout primitive only; the
 * AppRouter wiring + BootstrapErrorScreen are Day 2 work.
 *
 * The state machine deliberately WRAPS the existing
 * `authStore.initialize`/`fetchCompanies`, `terminalStore.initialize`, and
 * `operatorStore.checkHasPins` rather than replacing them. Public method
 * signatures stay unchanged so AppRouter Day 2 can swap the three
 * sequential `useEffect` orchestrators for a single `useEffect → start()`
 * call without rippling further.
 */
export type BootstrapPhase =
  | 'idle'
  | 'authenticating'
  | 'fetching-companies'
  | 'fetching-terminal'
  | 'checking-pins'
  | 'ready'
  | 'error';

const RUNNABLE_PHASES = [
  'authenticating',
  'fetching-companies',
  'fetching-terminal',
  'checking-pins',
] as const satisfies ReadonlyArray<BootstrapPhase>;

type RunnablePhase = (typeof RUNNABLE_PHASES)[number];

/**
 * Default per-phase timeout. The activation-hardening kickoff (T2.4)
 * recommends 15s for every phase as a starting point; the Phase 6 manual
 * smoke pass on Slow-3G profiles will tell us whether `fetching-companies`
 * needs a longer ceiling. Configurable via `useBootstrapStore.setState`
 * for test/local tuning.
 */
const DEFAULT_TIMEOUT_MS = 15_000;

/**
 * Codex review (PR #106 round 7, P2): recoverability is dynamic, not
 * static. Computing at error time lets us check whether a usable cache
 * actually exists for the failed phase — without this, the "Use cached
 * data" affordance can be offered when there is no cached data to fall
 * back to.
 *
 *   - `authenticating`: always false. The login token is the entry point
 *     — there is no cached fallback for "I am authenticated"; the cashier
 *     must log in again.
 *   - `fetching-companies`: always false. The phase only runs when the
 *     companies cache is already empty (Codex r6 P2 gates the call), so
 *     a failure here means the recovery cache is also empty — there is
 *     nothing to "skip to".
 *   - `fetching-terminal`: true iff SQLite has a cached terminal record.
 *     `terminalStore.initialize()` populates `terminal` from the SQLite
 *     mirror before any network call, so if the in-memory `terminal` is
 *     non-null at error time, a cached value is available; otherwise
 *     there is no fallback.
 *   - `checking-pins`: always false (Codex r5 P1). `hasPins=false` would
 *     route to `PinSetupPage` and bypass the operator PIN gate; we must
 *     not offer "Use cached data" here.
 */
function phaseRecoverable(phase: RunnablePhase): boolean {
  switch (phase) {
    case 'authenticating':
      return false;
    case 'fetching-companies':
      return false;
    case 'fetching-terminal':
      return useTerminalStore.getState().terminal !== null;
    case 'checking-pins':
      return false;
  }
}

/**
 * Mirrors `App.tsx::SAFE_ERROR_NAMES` (T0.1 banner-opacity contract). Any
 * errorName not in this set is coerced to `'Error'` so vendor / API class
 * names cannot leak to the cashier UI. We re-declare the set here to keep
 * `bootstrapStore` independent of `App.tsx`; if the allowlist evolves,
 * this constant should be kept in sync (Day 2 may extract it to a shared
 * constants module).
 */
const SAFE_ERROR_NAMES: ReadonlySet<string> = new Set([
  'ApiRequestError',
  'FetchTimeoutError',
  'AbortError',
  'TypeError',
  'Error',
]);

function safeErrorName(error: unknown): string {
  if (error instanceof Error && SAFE_ERROR_NAMES.has(error.name)) {
    return error.name;
  }
  return 'Error';
}

export interface BootstrapError {
  phase: RunnablePhase;
  errorName: string;
  recoverable: boolean;
  retryCount: number;
}

interface BootstrapState {
  phase: BootstrapPhase;
  error: BootstrapError | null;
  /**
   * The last RUNNABLE phase that completed successfully (or null before any
   * phase has). `retry` resumes from `lastSuccessfulPhase + 1` so a network
   * blip at phase N does not waste a re-run of N-1.
   */
  lastSuccessfulPhase: RunnablePhase | null;
  /**
   * Per-phase timeout in milliseconds. Default 15s; configurable so tests
   * can tighten and Phase 6 smoke can loosen for slow-network phases.
   */
  timeoutMs: number;
}

interface BootstrapActions {
  start: () => Promise<void>;
  retry: () => Promise<void>;
  skipWithCache: () => Promise<void>;
}

const initialState: BootstrapState = {
  phase: 'idle',
  error: null,
  lastSuccessfulPhase: null,
  timeoutMs: DEFAULT_TIMEOUT_MS,
};

function nextPhaseAfter(phase: RunnablePhase | null): RunnablePhase | null {
  if (phase === null) return RUNNABLE_PHASES[0];
  const idx = RUNNABLE_PHASES.indexOf(phase);
  return idx >= 0 && idx + 1 < RUNNABLE_PHASES.length
    ? RUNNABLE_PHASES[idx + 1]!
    : null;
}

async function runPhase(phase: RunnablePhase, timeoutMs: number): Promise<void> {
  switch (phase) {
    case 'authenticating':
      await withTimeout(phase, useAuthStore.getState().initialize(), timeoutMs);
      return;
    case 'fetching-companies':
      // Codex review (PR #106 round 6, P2): the existing AppRouter only
      // calls fetchCompanies from the empty-companies recovery branch
      // (`App.tsx::CompanyRecoveryScreen`), not on every boot. A returning
      // cashier with cached COMPANIES who boots offline would otherwise
      // see an unnecessary network failure here even though their cached
      // company list is fully usable. Skip the call entirely when
      // companies are already loaded; only fetch when the cache is empty.
      if (useAuthStore.getState().companies.length > 0) {
        return;
      }
      await withTimeout(phase, useAuthStore.getState().fetchCompanies(), timeoutMs);
      return;
    case 'fetching-terminal':
      await withTimeout(phase, useTerminalStore.getState().initialize(), timeoutMs);
      return;
    case 'checking-pins':
      await withTimeout(phase, useOperatorStore.getState().checkHasPins(), timeoutMs);
      // Codex review (PR #106 round 6, P1): operatorStore.checkHasPins
      // swallows API + SQLite failures and resolves `false` without
      // updating store state. A naive `await` therefore reports the
      // phase as successful even when the PIN state is genuinely
      // unknown — and false routes to first-time PinSetupPage, which
      // would let any cashier create a fresh PIN if real PINs already
      // existed. Discriminate by reading the store: if hasPins is
      // still null after the call, the underlying lookup did not
      // succeed; throw so the phase fails non-recoverably.
      if (useOperatorStore.getState().hasPins === null) {
        throw new Error(
          'PIN state could not be determined: API + SQLite both unavailable',
        );
      }
      return;
  }
}

/**
 * Codex review (PR #106 round 2, P1): respect the same gating the existing
 * `AppRouter` flow has between init phases. Without this, `start()` would
 * unconditionally advance through every phase even from a logged-out boot
 * (sending `/user/companies` without a token → 401 → bootstrap error
 * instead of showing the login screen) or from a multi-company state with
 * no `companyId` selected (calling terminal APIs without `X-Company-Id`).
 *
 * The state machine returns `false` (do not continue) when the next
 * phase's prerequisite is not satisfied. The orchestrator treats that as
 * "bootstrap done — whatever conditional rendering AppRouter does based
 * on auth/company state takes it from here", consistent with the kickoff
 * doc's "the state machine just guards the **happy path** and surfaces
 * failures" framing.
 */
function canEnterPhase(phase: RunnablePhase): boolean {
  switch (phase) {
    case 'authenticating':
      // Always allowed — the entry gate.
      return true;
    case 'fetching-companies':
      // Only fetch the company list once we have a token (post-`initialize`).
      return useAuthStore.getState().isAuthenticated === true;
    case 'fetching-terminal':
      // Need both auth AND a selected company before any terminal API call.
      return (
        useAuthStore.getState().isAuthenticated === true
        && useAuthStore.getState().companyId !== null
      );
    case 'checking-pins':
      // Same prerequisites as terminal init plus a hydrated terminal.
      return (
        useAuthStore.getState().isAuthenticated === true
        && useAuthStore.getState().companyId !== null
        && useTerminalStore.getState().terminal !== null
      );
  }
}

async function runFromPhase(
  startPhase: RunnablePhase,
  retryCount: number,
  setState: (partial: Partial<BootstrapState>) => void,
  getState: () => BootstrapState,
): Promise<void> {
  const startIdx = RUNNABLE_PHASES.indexOf(startPhase);
  for (let i = startIdx; i < RUNNABLE_PHASES.length; i++) {
    const phase = RUNNABLE_PHASES[i]!;
    if (!canEnterPhase(phase)) {
      // Codex review (PR #106 round 2, P1): the prerequisite for this phase
      // is not satisfied (logged-out boot, no company selected, no terminal
      // hydrated, etc.). Stop the orchestration and let AppRouter's
      // downstream conditional rendering handle the rest. The orchestration
      // is "as ready as it can be" given the current auth/company state.
      setState({ phase: 'ready', error: null });
      return;
    }
    setState({ phase, error: null });
    try {
      await runPhase(phase, getState().timeoutMs);
      setState({ lastSuccessfulPhase: phase });
    } catch (error) {
      setState({
        phase: 'error',
        error: {
          phase,
          errorName: safeErrorName(error),
          recoverable: phaseRecoverable(phase),
          retryCount,
        },
      });
      return;
    }
  }
  setState({ phase: 'ready', error: null });
}

export const useBootstrapStore = create<BootstrapState & BootstrapActions>((set, get) => ({
  ...initialState,

  start: async () => {
    // Codex review (PR #106 round 1, P2): clear residual progress so a
    // fresh start() never inherits a stale `lastSuccessfulPhase` from a
    // previous run. Without this, calling start() after a prior `ready`
    // run that then fails in an earlier phase leaves the old
    // `lastSuccessfulPhase` in place, and a follow-on retry() would
    // skip past the actually-failing phase and falsely promote to ready.
    set({ lastSuccessfulPhase: null, error: null });
    await runFromPhase(RUNNABLE_PHASES[0], 0, set, get);
  },

  retry: async () => {
    const { lastSuccessfulPhase, error } = get();
    const startPhase = nextPhaseAfter(lastSuccessfulPhase);
    if (startPhase === null) {
      // Already at the end — nothing to retry. Tag as ready so subscribers
      // unblock.
      set({ phase: 'ready', error: null });
      return;
    }
    const nextRetryCount = (error?.retryCount ?? -1) + 1;
    await runFromPhase(startPhase, nextRetryCount, set, get);
  },

  skipWithCache: async () => {
    const { error } = get();
    if (error === null) {
      // No error to skip past — defensive no-op rather than throw, since
      // a stale UI tap should never crash the bootstrap orchestrator.
      return;
    }
    if (!error.recoverable) {
      throw new Error(
        `Bootstrap phase "${error.phase}" is not recoverable; cannot skip with cache.`,
      );
    }
    // "Skip" means: pretend the failing phase succeeded (the cached value
    // is already in place by contract — see RECOVERABLE doc above) and
    // continue from the NEXT phase.
    const nextPhase = nextPhaseAfter(error.phase);
    if (nextPhase === null) {
      set({ phase: 'ready', error: null, lastSuccessfulPhase: error.phase });
      return;
    }
    set({ lastSuccessfulPhase: error.phase });
    await runFromPhase(nextPhase, error.retryCount + 1, set, get);
  },
}));
