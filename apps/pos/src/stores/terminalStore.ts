import { create } from 'zustand';
import { apiGet, apiPost } from '@/lib/api';
import { getDeviceId } from '@/lib/device';
import { getStoredValue, setStoredValue, removeStoredValue, StorageKeys } from '@/lib/storage';
import { getDatabase } from '@/lib/db';
import { pullTerminalState, pullZChainState } from '@/lib/sync/syncService';
import { SyncScheduler } from '@/lib/sync/syncScheduler';
import { useAuthStore } from '@/stores/authStore';
import { useSyncStore } from '@/stores/syncStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { serializeErrorForLog } from '@/lib/errorLogging';

export interface Location {
  id: string;
  name: string;
  code: string;
  type: string;
  is_default: boolean;
  pos_enabled: boolean;
}

export interface Terminal {
  id: string;
  code: string;
  name: string;
  type: string;
  is_active: boolean;
  /**
   * T2.5 — when true, the cashier is using a "training" terminal:
   * receipts persist as normal DB rows but skip the fiscal hash chain
   * (no fiscal_hash / previous_hash / chain_sequence written), and
   * are excluded from Z reports / NF525 exports / production-scoped
   * queries via Terminal::scopeProduction(). Backend already wires
   * this end-to-end via TerminalResource → /pos/terminals/{id}/
   * toggle-training. The POS surfaces it via TrainingModeBanner so
   * the cashier never confuses training and production at a glance.
   */
  is_training_mode: boolean;
  max_discount_percent?: number;
  allow_line_discounts?: boolean;
  allow_transaction_discounts?: boolean;
  hardware_identifier: string | null;
  location: {
    id: string;
    name: string;
    code: string;
  };
}

export interface Shift {
  id: string;
  terminal_id: string;
  shift_number: number;
  status: 'OPEN' | 'CLOSED';
  opening_cash: string;
  opened_at: string;
  user: {
    id: string;
    name: string;
  };
}

interface TerminalState {
  terminal: Terminal | null;
  pendingTerminalId: string | null;
  shift: Shift | null;
  isLoading: boolean;
  hashChainReady: boolean;
}

interface TerminalActions {
  initialize: (opts?: { signal?: AbortSignal }) => Promise<void>;
  fetchAvailable: () => Promise<Terminal[]>;
  claimTerminal: (terminalId: string, hardwareIdentifier: string) => Promise<void>;
  requestTerminal: (locationId: string, suggestedName: string, hardwareIdentifier: string) => Promise<Terminal>;
  checkTerminalStatus: (terminalId: string) => Promise<Terminal>;
  fetchCurrentShift: () => Promise<void>;
  openShift: (openingCash: string, cashierId?: string) => Promise<void>;
  closeShift: (actualCash: string) => Promise<void>;
  reset: () => void;
  refreshHashChainReady: () => Promise<void>;
  refreshTerminalRecord: () => Promise<void>;
}

type TerminalStore = TerminalState & TerminalActions;

/**
 * T2.5 / Codex round-3 P2 — ceiling on the boot-time terminal refresh.
 * 2s is generous for a healthy network (typical /pos/terminals/{id}
 * roundtrip is ~100-300ms) and bounded so an offline boot doesn't
 * stall the activation path.
 */
const TERMINAL_REFRESH_TIMEOUT_MS = 2_000;

const initialState: TerminalState = {
  terminal: null,
  pendingTerminalId: null,
  shift: null,
  isLoading: false,
  hashChainReady: false,
};

async function refreshOperatorDiscountPermissionsAfterTerminalChange(terminalCode: string): Promise<void> {
  try {
    const { useOperatorStore } = await import('@/stores/operatorStore');
    useOperatorStore.getState().invalidateDiscountPermissions();
  } catch (error) {
    console.error(
      '[Terminal] failed to invalidate active operator discount permissions',
      serializeErrorForLog(error),
    );
  }

  const companyId = useAuthStore.getState().companyId;
  if (companyId) {
    try {
      const db = await getDatabase(companyId);
      const { invalidateTerminalDiscountPermissions } = await import(
        '@/lib/db/repositories/operatorPinRepository'
      );
      await invalidateTerminalDiscountPermissions(db, terminalCode);
    } catch (error) {
      console.error(
        '[Terminal] failed to invalidate cached discount permissions',
        serializeErrorForLog(error),
      );
    }
  }

  try {
    const { useOperatorStore } = await import('@/stores/operatorStore');
    await useOperatorStore.getState().refreshDiscountPermissions();
  } catch (error) {
    console.error(
      '[Terminal] failed to refresh active operator discount permissions',
      serializeErrorForLog(error),
    );
  }
}

/**
 * Seed the local SQLite terminal_state and Z-chain state from the server.
 * Must run after a terminal becomes active so offline receipts and Z-reports
 * can compute fiscal hashes.
 *
 * Exported for T1.2 Step 2.4 regression coverage — the test mocks
 * SyncScheduler and usePaymentStore.fetchPaymentConfig to assert the
 * pre-warm fires in the right order. No production callers outside
 * this module.
 */
// TODO(go-live-followup): full offline cold-start mode for fresh devices
//   (Phase 0 deferred). Today the first launch requires online connectivity
//   for auth + initial catalog/payment-config seed; once seeded the terminal
//   is offline-first. Field signal will tell us whether merchants need a
//   one-online-then-offline activation flow vs. a fully-offline activation
//   path. See
//   docs/superpowers/plans/2026-05-09-pos-t2.2-crash-safety-small-wins-kickoff-prompt.md
//   Section 3 Step 5.3 row 7.
export async function seedOfflineHashChain(terminalId: string): Promise<void> {
  const companyId = useAuthStore.getState().companyId;
  if (!companyId) return;
  try {
    const db = await getDatabase(companyId);
    await pullTerminalState(db, terminalId);
    await pullZChainState(db, terminalId);

    // Update the ready flag whether or not the pull succeeded —
    // the flag reflects SQLite state, not network state.
    const { getTerminalState } = await import('@/lib/db/repositories/terminalStateRepository');
    const stateRow = await getTerminalState(db, terminalId);
    useTerminalStore.setState({ hashChainReady: stateRow !== null });

    // Hydrate in-memory image cache from SQLite manifest
    try {
      const { initImageCache } = await import('@/lib/images/imageCache');
      await initImageCache(db);
    } catch { /* image caching is non-critical */ }

    // T2.1 Step D: stranded-syncing receipt recovery. A SIGKILL/power-cut
    // between syncService.updateReceiptStatus(... 'syncing') and the
    // response handler can strand a row at 'syncing' permanently —
    // getPendingReceiptsForSync filters WHERE status IN ('pending',
    // 'failed') so the orphan is invisible to every retry. On boot,
    // demote any such row back to 'pending' so the next sync tick
    // re-attempts it. Idempotent by construction (T0.2 idempotency_key
    // dedups any double-send server-side).
    //
    // Order matters: this MUST run BEFORE scheduler.start() so the
    // first tick sees the demoted rows in getPendingReceiptsForSync.
    // A SQLite-level failure here is logged + serialized but never
    // propagated — letting the scheduler still start matters more than
    // the recovery succeeding (the next boot retries).
    try {
      const { recoverStrandedSyncingReceipts } = await import(
        '@/lib/db/repositories/offlineReceiptRepository'
      );
      const recovered = await recoverStrandedSyncingReceipts(db);
      if (recovered > 0) {
        console.info(
          `[POS][terminalStore][recover] demoted ${String(recovered)} stranded 'syncing' receipt(s) on boot`,
        );
      }
    } catch (err) {
      console.error(
        '[POS][terminalStore][recover] strandedSyncing failed',
        serializeErrorForLog(err),
      );
    }

    // Cash-drawer counterpart of the Step D recovery above. Same crash-
    // stranding pattern at `syncService.pushCashDrawerOps:506`. A SIGKILL/
    // power-cut between updateCashDrawerOpStatus(... 'syncing') and the
    // response handler strands the row at 'syncing' permanently, invisible
    // to getPendingCashDrawerOps's `status IN ('pending','failed')` filter.
    // Recovery demotes back to 'pending' so the next sync tick re-attempts.
    // T0.2 idempotency_key dedups any double-send server-side. Independent
    // try/catch from the receipts recovery so one path's SQLite blip can't
    // strand the other.
    try {
      const { recoverStrandedSyncingCashDrawerOps } = await import(
        '@/lib/db/repositories/cashDrawerRepository'
      );
      const recovered = await recoverStrandedSyncingCashDrawerOps(db);
      if (recovered > 0) {
        console.info(
          `[POS][terminalStore][recover] demoted ${String(recovered)} stranded 'syncing' cash-drawer op(s) on boot`,
        );
      }
    } catch (err) {
      console.error(
        '[POS][terminalStore][recover] strandedSyncingCashDrawer failed',
        serializeErrorForLog(err),
      );
    }

    // Start the background sync scheduler
    const scheduler = new SyncScheduler(db, terminalId);
    useSyncStore.getState().setScheduler(scheduler);
    scheduler.start();

    // T1.2 Step 2.4: pre-warm payment config so the cashier's first
    // visit to PaymentSummary's gate (Step 2.3) finds paymentMethods +
    // paymentRepositories already populated. Fire-and-forget so the
    // activation path is never blocked on a slow API; the catch uses
    // serializeErrorForLog to keep the structured-log payload safe
    // (T0.1 contract). On a brand-new device with no cached config,
    // the call still works after PIN entry rather than blocking
    // activation. HomePage's lazy fetchPaymentConfig later in the
    // boot is idempotent — last-write-wins on identical data is safe.
    void usePaymentStore
      .getState()
      .fetchPaymentConfig()
      .catch((err: unknown) => {
        console.error(
          '[POS][terminalStore][preWarm] paymentConfig fetch failed',
          serializeErrorForLog(err),
        );
      });

    // T1.3 Step 4.1: hydrate pendingReceiptCount from SQLite so the
    // header badge reflects the truth from boot. The hydration runs
    // AFTER scheduler.start, which fires the first tick immediately
    // (`SyncScheduler.start()` calls `void this.tick()` synchronously
    // — there is no debounce). If the first tick's setPendingCount
    // lands BEFORE this hydration await resolves, both writes source
    // from the same SQLite row so last-write-wins is safe — the
    // count is monotonic-ish across one boot.
    try {
      const { getPendingReceiptCount } = await import(
        '@/lib/db/repositories/offlineReceiptRepository'
      );
      const pendingCount = await getPendingReceiptCount(db);
      useSyncStore.getState().setPendingCount(pendingCount);
    } catch (err) {
      console.error(
        '[POS][terminalStore][hydrate] pendingReceiptCount failed',
        serializeErrorForLog(err),
      );
    }

    // T1.3 Step 4.2: hydrate lastSyncAt from sync_metadata so the
    // SyncButton's "X minutes ago" affordance survives app restarts.
    // Skip the write entirely on null / non-numeric values — leaves
    // the store at its initial null and the SyncButton just hides
    // the affordance until the next tick.
    //
    // T1.3 Codex round-1 finding 2: the hydration await runs AFTER
    // scheduler.start, which fires the first tick immediately. If the
    // tick completes runFullSync and writes a fresh Date.now() before
    // this hydration await resolves, an unguarded write would clobber
    // the fresher in-memory value with the older persisted one. Guard
    // by only writing when the in-memory value is null OR the parsed
    // value is strictly newer (in-memory wins on conflict).
    try {
      const { getSyncMetadata } = await import(
        '@/lib/db/repositories/syncLogRepository'
      );
      const raw = await getSyncMetadata(db, 'last_sync_at');
      if (raw !== null) {
        const parsed = Number(raw);
        if (Number.isFinite(parsed)) {
          const current = useSyncStore.getState().lastSyncAt;
          if (current === null || parsed > current) {
            useSyncStore.getState().setLastSyncAt(parsed);
          }
        }
      }
    } catch (err) {
      console.error(
        '[POS][terminalStore][hydrate] lastSyncAt failed',
        serializeErrorForLog(err),
      );
    }
  } catch (error) {
    console.error('[Terminal] Failed to seed offline hash chain:', error);
  }
}

export const useTerminalStore = create<TerminalStore>()((set, get) => ({
  ...initialState,

  initialize: async (opts?: { signal?: AbortSignal }) => {
    set({ isLoading: true });
    try {
      // 1. Check localStorage for a fully-activated terminal
      const cachedTerminal = await getStoredValue<Terminal>(StorageKeys.TERMINAL);
      if (cachedTerminal) {
        // Codex round-5 P2 (PR #99) — refresh the terminal record from
        // the server BEFORE publishing it into in-memory state. Doing
        // this AFTER set({ terminal: cached }) opens a window where
        // AppRouter (which unblocks on `terminal !== null`) can render
        // cashier routes with the cached is_training_mode flag — the
        // exact stale-mode the banner is meant to prevent.
        //
        // Round-3 P2 trade-off preserved: 2s ceiling so an offline
        // boot falls back to cache rather than stalling activation.
        const expectedTerminalId = cachedTerminal.id;
        let resolvedTerminal: Terminal = cachedTerminal;
        try {
          const fresh = await Promise.race([
            apiGet<Terminal>(`/pos/terminals/${expectedTerminalId}`, undefined, {
              signal: opts?.signal,
            }),
            new Promise<never>((_, reject) =>
              setTimeout(() => reject(new Error('terminal-refresh-timeout')), TERMINAL_REFRESH_TIMEOUT_MS),
            ),
          ]);
          resolvedTerminal = fresh;
        } catch {
          // Offline / server transient / 2s-timeout — fall back to
          // cached terminal. The cashier sees the cached banner state
          // until the next sync cycle refreshes the catalog. Logging
          // is intentionally omitted: every offline boot would log,
          // and the offline banner state is the documented fallback.
        }
        if (opts?.signal?.aborted) return;

        // Codex round-6/8 P2 — race guard against logout / change-terminal
        // during the refresh await. The previous round-6 guard read
        // StorageKeys.TERMINAL post-await, but reset() schedules
        // `removeStoredValue` async-without-await, so the removal can
        // race with our re-read. Round-8: switch to a synchronous
        // in-memory signal — `useAuthStore.getState().token`. Logout
        // calls authStore.logout() which immediately set()s
        // initialState (token=null) BEFORE any localStorage scheduling,
        // so this check is race-free against the JS event loop.
        const authToken = useAuthStore.getState().token;
        if (authToken === null) {
          // Session ended mid-await — must NOT rehydrate.
          return;
        }

        // Persist + publish only after the guard. The fresh write to
        // localStorage gets the next boot the up-to-date state even
        // if the server is unreachable then.
        await setStoredValue(StorageKeys.TERMINAL, resolvedTerminal);
        if (opts?.signal?.aborted) return;
        set({ terminal: resolvedTerminal });
        // Ensure offline hash chain is seeded (may be missing after DB reset/reinstall)
        await seedOfflineHashChain(resolvedTerminal.id);
        if (opts?.signal?.aborted) return;
        await get().fetchCurrentShift();

        return;
      }

      // 2. Check if there's a pending terminal ID from a previous request
      const pendingId = await getStoredValue<string>(StorageKeys.PENDING_TERMINAL_ID);
      if (pendingId) {
        console.log('[Terminal] Found stored pending terminal ID:', pendingId);
        if (opts?.signal?.aborted) return;
        set({ pendingTerminalId: pendingId });
        // Check if it was activated while we were away
        try {
          const pending = await apiGet<Terminal>(`/pos/terminals/${pendingId}`, undefined, {
            signal: opts?.signal,
          });
          if (opts?.signal?.aborted) return;
          if (pending.is_active) {
            console.log('[Terminal] Pending terminal is now active:', pending.code);
            await setStoredValue(StorageKeys.TERMINAL, pending);
            await removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
            await seedOfflineHashChain(pending.id);
            if (opts?.signal?.aborted) return;
            set({ terminal: pending, pendingTerminalId: null });
            await get().fetchCurrentShift();
            return;
          }
        } catch (err) {
          if (opts?.signal?.aborted) return;
          // Terminal may have been deleted, clear pending
          console.warn('[Terminal] Pending terminal check failed, clearing stale ID:', pendingId, err);
          await removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
          set({ pendingTerminalId: null });
        }
        return;
      }

      // 3. Last resort: check if any terminal is assigned to this device
      try {
        const deviceId = getDeviceId();
        const found = await apiGet<Terminal | null>(
          `/pos/terminals/by-device/${deviceId}`,
          undefined,
          { signal: opts?.signal },
        );
        if (opts?.signal?.aborted) return;
        if (found?.is_active) {
          await setStoredValue(StorageKeys.TERMINAL, found);
          await seedOfflineHashChain(found.id);
          if (opts?.signal?.aborted) return;
          set({ terminal: found });
          await get().fetchCurrentShift();
        } else if (found && !found.is_active) {
          // Found but not yet activated — track it as pending
          await setStoredValue(StorageKeys.PENDING_TERMINAL_ID, found.id);
          if (opts?.signal?.aborted) return;
          set({ pendingTerminalId: found.id });
        }
      } catch {
        // No terminal for this device, that's fine
      }
    } catch (error) {
      if (opts?.signal?.aborted) return;
      console.error('Failed to initialize terminal:', error);
    } finally {
      set({ isLoading: false });
    }
  },

  fetchAvailable: async () => {
    const terminals = await apiGet<Terminal[]>('/pos/terminals/available');
    return terminals;
  },

  claimTerminal: async (terminalId: string, hardwareIdentifier: string) => {
    set({ isLoading: true });
    try {
      const terminal = await apiPost<Terminal>('/pos/terminals/claim', {
        terminal_id: terminalId,
        hardware_identifier: hardwareIdentifier,
      });
      await setStoredValue(StorageKeys.TERMINAL, terminal);
      await seedOfflineHashChain(terminal.id);
      set({ terminal, isLoading: false });
    } catch (error) {
      set({ isLoading: false });
      throw error;
    }
  },

  requestTerminal: async (locationId: string, suggestedName: string, hardwareIdentifier: string) => {
    set({ isLoading: true });
    console.log('[Terminal] Requesting terminal:', { locationId, suggestedName, hardwareIdentifier });
    try {
      const terminal = await apiPost<Terminal>('/pos/terminals/request', {
        location_id: locationId,
        hardware_identifier: hardwareIdentifier,
        suggested_name: suggestedName,
      });
      console.log('[Terminal] Request successful, pending ID:', terminal.id);
      await setStoredValue(StorageKeys.PENDING_TERMINAL_ID, terminal.id);
      set({ pendingTerminalId: terminal.id, isLoading: false });
      return terminal;
    } catch (error) {
      console.error('[Terminal] Request failed:', error);
      set({ isLoading: false });
      throw error;
    }
  },

  checkTerminalStatus: async (terminalId: string) => {
    const terminal = await apiGet<Terminal>(`/pos/terminals/${terminalId}`);
    if (terminal.is_active) {
      await setStoredValue(StorageKeys.TERMINAL, terminal);
      await removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
      await seedOfflineHashChain(terminal.id);
      set({ terminal, pendingTerminalId: null });
    }
    return terminal;
  },

  fetchCurrentShift: async () => {
    const { terminal } = get();
    if (!terminal) return;

    try {
      const shift = await apiGet<Shift | null>(`/pos/shifts/current/${terminal.code}`);
      if (shift) {
        await setStoredValue(StorageKeys.SHIFT, shift);
      } else {
        await removeStoredValue(StorageKeys.SHIFT);
      }
      set({ shift });
    } catch {
      // Offline fallback: restore from persistent storage
      const cachedShift = await getStoredValue<Shift>(StorageKeys.SHIFT);
      set({ shift: cachedShift });
    }
  },

  openShift: async (openingCash: string, cashierId?: string) => {
    const { terminal } = get();
    if (!terminal) throw new Error('No terminal configured');

    set({ isLoading: true });
    try {
      const body: Record<string, string> = {
        terminal_code: terminal.code,
        opening_cash: openingCash,
      };
      if (cashierId) {
        body['cashier_id'] = cashierId;
      }
      const shift = await apiPost<Shift>('/pos/shifts/open', body);
      await setStoredValue(StorageKeys.SHIFT, shift);
      set({ shift, isLoading: false });
    } catch {
      // Offline fallback: create local shift
      const authState = useAuthStore.getState();
      const user = authState.user;
      const offlineShift: Shift = {
        id: `offline-${crypto.randomUUID()}`,
        terminal_id: terminal.id,
        shift_number: 0,
        status: 'OPEN',
        opening_cash: openingCash,
        opened_at: new Date().toISOString(),
        user: {
          id: cashierId ?? user?.id ?? '',
          name: user?.name ?? 'Operator',
        },
      };
      await setStoredValue(StorageKeys.SHIFT, offlineShift);
      set({ shift: offlineShift, isLoading: false });
    }
  },

  closeShift: async (actualCash: string) => {
    const { shift } = get();
    if (!shift) throw new Error('No active shift');

    set({ isLoading: true });
    try {
      await apiPost<Shift>(`/pos/shifts/${shift.id}/close`, {
        actual_cash: actualCash,
      });
    } catch {
      console.warn('[Terminal] Shift close API failed (offline), closing locally');
    }
    await removeStoredValue(StorageKeys.SHIFT);
    set({ shift: null, isLoading: false });
  },

  reset: () => {
    useSyncStore.getState().scheduler?.stop();
    useSyncStore.getState().setScheduler(null);
    useSyncStore.getState().reset();
    set(initialState);
    void removeStoredValue(StorageKeys.TERMINAL);
    void removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
    void removeStoredValue(StorageKeys.SHIFT);
  },

  refreshHashChainReady: async () => {
    const { terminal } = get();
    if (!terminal) {
      set({ hashChainReady: false });
      return;
    }
    const companyId = useAuthStore.getState().companyId;
    if (!companyId) {
      set({ hashChainReady: false });
      return;
    }
    try {
      const db = await getDatabase(companyId);
      const { getTerminalState } = await import('@/lib/db/repositories/terminalStateRepository');
      const state = await getTerminalState(db, terminal.id);
      set({ hashChainReady: state !== null });
    } catch (error) {
      console.error('[Terminal] refreshHashChainReady failed:', error);
      set({ hashChainReady: false });
    }
  },

  /**
   * T2.5 / Codex round-7 P2 (PR #99) — refresh the terminal record
   * from the server during an active POS session. The boot-time
   * refresh in `initialize()` covers the cold-start path; this hook
   * covers in-session changes (a manager toggles
   * `/pos/terminals/{id}/toggle-training` while the POS stays open,
   * which is allowed when no shift is open).
   *
   * Called from `SyncScheduler.tick()` so it piggy-backs on the
   * existing 60s polling cadence — no separate timer.
   *
   * Race-guarded same as `initialize()`'s refresh:
   *   - capture expectedTerminalId pre-await
   *   - skip the write if the in-memory terminal changed during the
   *     await (logout / change-terminal flow)
   *   - keep the cached value on offline / server transient
   *
   * Errors are intentionally swallowed: a flaky sync tick should not
   * crash the cashier, and the next tick will retry.
   */
  refreshTerminalRecord: async () => {
    const current = get().terminal;
    if (!current) return;

    const expectedTerminalId = current.id;
    try {
      const fresh = await apiGet<Terminal>(`/pos/terminals/${expectedTerminalId}`);
      const stillCurrent = get().terminal;
      if (stillCurrent?.id !== expectedTerminalId) return;
      // Avoid spurious re-renders / localStorage writes on no-diff
      // ticks: only persist when something actually changed.
      if (
        stillCurrent.is_training_mode === fresh.is_training_mode
        && stillCurrent.is_active === fresh.is_active
        && stillCurrent.name === fresh.name
        && stillCurrent.code === fresh.code
        && stillCurrent.max_discount_percent === fresh.max_discount_percent
        && stillCurrent.allow_line_discounts === fresh.allow_line_discounts
        && stillCurrent.allow_transaction_discounts === fresh.allow_transaction_discounts
      ) {
        return;
      }
      const discountSettingsChanged =
        stillCurrent.max_discount_percent !== fresh.max_discount_percent
        || stillCurrent.allow_line_discounts !== fresh.allow_line_discounts
        || stillCurrent.allow_transaction_discounts !== fresh.allow_transaction_discounts;
      await setStoredValue(StorageKeys.TERMINAL, fresh);
      set({ terminal: fresh });
      if (discountSettingsChanged) {
        await refreshOperatorDiscountPermissionsAfterTerminalChange(stillCurrent.code);
      }
    } catch (error) {
      console.error('[Terminal] refreshTerminalRecord failed (non-fatal)', serializeErrorForLog(error));
    }
  },
}));
