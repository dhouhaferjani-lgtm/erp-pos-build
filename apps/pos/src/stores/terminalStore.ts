import { create } from 'zustand';
import { apiGet, apiPost } from '@/lib/api';
import { getDeviceId } from '@/lib/device';
import { getStoredValue, setStoredValue, removeStoredValue, StorageKeys } from '@/lib/storage';
import { getDatabase } from '@/lib/db';
import { pullTerminalState, pullZChainState, pullLocationStock } from '@/lib/sync/syncService';
import { SyncScheduler } from '@/lib/sync/syncScheduler';
import { useAuthStore } from '@/stores/authStore';
import { useSyncStore } from '@/stores/syncStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { getCurrencyDecimals } from '@/lib/currency';
import { authorZSessionOpenWithOpeningFloat } from '@/lib/fiscal/zSessionAuthoring';
import { uuidv7 } from '@/lib/uuidv7';
import {
  getCurrentOpenShift,
  type LocalShift,
} from '@/lib/db/repositories/localShiftRepository';

/**
 * Company-level stock-enforcement policy delivered on the terminal payload
 * (spec 2026-06-11 §4.2). Absent on payloads predating the field — consumers
 * default to 'block' (fail-safe for retail).
 */
export type PosStockPolicy = 'block' | 'warn' | 'off';

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
  fiscal_schema_version: 2 | 3;
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
  /**
   * Task 11 — company-level POS stock enforcement policy, serialized onto the
   * terminal payload by TerminalResource (Task 2):
   *   'block' → over-stock adds are rejected at the cart gate
   *   'warn'  → over-stock adds are allowed but surfaced to the cashier
   *   'off'   → availability is never consulted (Menu tenants, backfilled)
   * Optional because cached pre-Task-2 terminal payloads lack the field —
   * the stock gate treats an absent value as 'block' (fail-safe for retail).
   */
  pos_stock_policy?: PosStockPolicy;
  max_discount_percent?: number;
  allow_line_discounts?: boolean;
  allow_transaction_discounts?: boolean;
  hardware_identifier: string | null;
  location: {
    id: string;
    name: string;
    code: string;
    tax_id: string | null;
    vat_number: string | null;
    legal_identifiers: Record<string, unknown> | null;
    /**
     * Establishment address (spec 2026-06-11 §4.6) — serialized by
     * TerminalResource. Optional because cached pre-§4.6 terminal payloads
     * lack the fields; the atomic seller resolver treats absent values as an
     * incomplete location (wholesale company identity).
     */
    address_street?: string | null;
    address_city?: string | null;
    address_postal_code?: string | null;
    address_country?: string | null;
  };
}

export interface Shift {
  id: string;
  terminal_id: string;
  shift_number: number;
  status: 'OPEN' | 'CLOSED';
  opening_cash: string;
  opened_at: string;
  fiscal_shift_id?: string;
  fiscal_session_id?: string;
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

function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

function fiscalCurrencyScale(currencyCode: string): 0 | 2 | 3 {
  const scale = getCurrencyDecimals(currencyCode);
  if (scale === 0 || scale === 2 || scale === 3) return scale;
  throw new Error(`Unsupported fiscal currency scale ${scale} for ${currencyCode}`);
}

function fiscalSessionIdForShift(shift: Shift): string {
  return shift.fiscal_session_id ?? (isUuid(shift.id) ? shift.id : crypto.randomUUID());
}

function fiscalShiftIdForShift(shift: Shift): string {
  return shift.fiscal_shift_id ?? (isUuid(shift.id) ? shift.id : crypto.randomUUID());
}

/**
 * The shift id to stamp into fiscal canonical payloads (SALE_RECEIPT,
 * ACCOUNT_PAYMENT, ACCOUNT_CHARGE). Payload validation requires a
 * lowercase-hex UUID, and raw `shift.id` is NOT one for offline-opened
 * shifts (`offline-<uuid>`). Resolution order:
 *   1. the device-minted `fiscal_shift_id` (matches the SESSION_OPEN event),
 *   2. the shift id itself when it is a UUID (server-opened shifts),
 *   3. the UUID inside an `offline-` prefixed id (deterministic — the same
 *      value on every call, unlike a random fallback which would scatter
 *      receipts across phantom shift ids and corrupt the Z window),
 *   4. fail loud. A blocked sale beats silently mis-attributed fiscal data.
 */
export function fiscalShiftIdForReceipt(shift: Shift): string {
  if (shift.fiscal_shift_id) return shift.fiscal_shift_id;
  const raw = shift.id.startsWith('offline-') ? shift.id.slice('offline-'.length) : shift.id;
  if (isUuid(raw)) return raw.toLowerCase();
  throw new Error(`Shift ${shift.id} has no usable fiscal shift id; cannot author fiscal events.`);
}

/**
 * Server shift payloads (ShiftResource) carry `cashier_id` (+ `cashier` when
 * the relation is loaded) but never `user` — that object exists only on
 * device-built shifts. Synthesize it at the boundary so the fiscal v3 open
 * path and every report surface can rely on `shift.user` (the 2026-06-12
 * live crash: "undefined is not an object (evaluating 'shift.user.id')").
 */
interface ServerShiftPayload extends Omit<Shift, 'user'> {
  user?: Shift['user'];
  cashier_id?: string;
  cashier?: { id: string; name: string };
}

function withShiftUser(serverShift: ServerShiftPayload, cached: Shift | null): Shift {
  if (serverShift.user?.id) return serverShift as Shift;

  const auth = useAuthStore.getState();
  const cashierId = serverShift.cashier_id ?? auth.user?.id ?? '';
  let name = serverShift.cashier?.name ?? '';
  if (!name && cached && cached.id === serverShift.id && cached.user?.id === cashierId) {
    name = cached.user.name;
  }
  if (!name && auth.user && cashierId === auth.user.id) {
    name = auth.user.name;
  }
  return { ...serverShift, user: { id: cashierId, name } };
}

/** Map a device `local_shifts` row to the in-memory `Shift` (one-id model). */
function localShiftToShift(open: LocalShift): Shift {
  return {
    id: open.id,
    terminal_id: open.terminal_id,
    shift_number: open.shift_number,
    status: open.status,
    opening_cash: open.opening_cash,
    opened_at: open.opened_at,
    fiscal_shift_id: open.fiscal_shift_id,
    fiscal_session_id: open.session_id,
    user: { id: open.cashier_id, name: open.cashier_name },
  };
}

async function authorShiftOpenFiscalEvents(
  terminal: Terminal,
  shift: Shift,
  openingCash: string,
): Promise<Shift> {
  const auth = useAuthStore.getState();
  if (!auth.user?.tenantId || !auth.companyId) {
    throw new Error('Cannot author Z-session opening event without active tenant and company.');
  }

  const company = auth.companies.find((candidate) => candidate.id === auth.companyId);
  const currencyCode = company?.currency ?? 'EUR';
  const fiscalShiftId = fiscalShiftIdForShift(shift);
  const fiscalSessionId = fiscalSessionIdForShift(shift);
  const openedAtDevice = new Date(shift.opened_at);
  if (Number.isNaN(openedAtDevice.getTime())) {
    throw new Error(`Cannot author Z-session opening event with invalid shift opened_at ${shift.opened_at}.`);
  }

  // SESSION_OPEN + OPENING_FLOAT, the M3 receipt anchor, and the device-
  // authoritative `local_shifts` row are authored atomically inside the fiscal
  // write-gate transaction (see authorZSessionOpenWithOpeningFloatOnDb). The
  // anchor records the terminal's receipt hash_sequence at open time so the Z
  // window selects THIS shift's receipts by monotonic, clock-rollback-immune
  // sequence; the local_shifts row is the SQLite-first source of truth for
  // "the current open shift". `shift_number` is minted in-tx and returned.
  const { shiftNumber } = await authorZSessionOpenWithOpeningFloat({
    tenantId: auth.user.tenantId,
    companyId: auth.companyId,
    terminalId: terminal.id,
    terminalLabel: terminal.code,
    shiftId: fiscalShiftId,
    sessionId: fiscalSessionId,
    businessDate: shift.opened_at.slice(0, 10),
    operatorId: shift.user.id || auth.user.id,
    operatorName: shift.user.name || auth.user.name,
    currencyCode,
    currencyScale: fiscalCurrencyScale(currencyCode),
    openingFloatAmount: openingCash,
    isTraining: terminal.is_training_mode,
    openedAtDevice,
    openingCashDrawerOperationId: null,
  });

  return {
    ...shift,
    shift_number: shiftNumber,
    fiscal_shift_id: fiscalShiftId,
    fiscal_session_id: fiscalSessionId,
  };
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

    // Sub-Spec C — audit-outbox boot maintenance. Same crash-stranding
    // pattern as the receipt/cash-drawer recoveries above: a SIGKILL between
    // markAuditEventsSyncing(... 'syncing') and the response handler strands
    // the row at 'syncing', invisible to getPendingAuditEvents's
    // `status IN ('pending','failed')` filter — demote it back to 'pending'.
    // Then prune 'synced' rows past the 14-day retention window so the outbox
    // stays bounded. Best-effort: an audit-maintenance blip must never block
    // scheduler start (audit is the lowest-priority sync surface).
    try {
      const {
        recoverStrandedSyncingAuditEvents,
        pruneSyncedAuditEvents,
      } = await import('@/lib/db/repositories/queuedAuditEventRepository');
      const recovered = await recoverStrandedSyncingAuditEvents(db);
      if (recovered > 0) {
        console.info(
          `[POS][terminalStore][recover] demoted ${String(recovered)} stranded 'syncing' audit event(s) on boot`,
        );
      }
      await pruneSyncedAuditEvents(db, 14);
    } catch (err) {
      console.error(
        '[POS][terminalStore][recover] audit-outbox maintenance failed',
        serializeErrorForLog(err),
      );
    }

    // Start the background sync scheduler
    const scheduler = new SyncScheduler(db, terminalId);
    useSyncStore.getState().setScheduler(scheduler);
    scheduler.start();

    // Task 9 — full location-stock baseline on terminal claim / boot /
    // activation (spec §4.3). seedOfflineHashChain is the single funnel
    // every activation path goes through (initialize cached/pending/
    // by-device, claimTerminal, checkTerminalStatus), so wiring here
    // covers them all. `terminalId` is passed EXPLICITLY because most of
    // those callers run before `set({ terminal })` publishes the terminal
    // into the store. Fire-and-forget: a stock-pull failure must never
    // block activation or selling — the scheduler's 60s delta tick
    // self-heals (and degrades to full while the cursor is unset).
    void pullLocationStock(db, 'full', { terminalId }).catch((err: unknown) => {
      console.error(
        '[POS][terminalStore][seed] location-stock full pull failed (non-fatal)',
        serializeErrorForLog(err),
      );
    });

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

    // v3 terminals: SQLite-FIRST. "Current open shift" is read purely from the
    // local_shifts projection — no network. The server is a downstream
    // projection of the device's fiscal events and is never consulted for the
    // live shift (an app restart must not sever the shift from its SESSION_OPEN
    // fiscal event).
    if (terminal.fiscal_schema_version === 3) {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) {
        const cached = await getStoredValue<Shift>(StorageKeys.SHIFT);
        set({ shift: cached ?? null });
        return;
      }
      const db = await getDatabase(companyId);
      const open = await getCurrentOpenShift(db, terminal.id);
      if (open) {
        const shift = localShiftToShift(open);
        await setStoredValue(StorageKeys.SHIFT, shift);
        set({ shift });
      } else {
        await removeStoredValue(StorageKeys.SHIFT);
        set({ shift: null });
      }
      return;
    }

    try {
      const serverShift = await apiGet<ServerShiftPayload | null>(`/pos/shifts/current/${terminal.code}`);
      if (serverShift) {
        // fiscal_shift_id / fiscal_session_id are minted on the DEVICE at
        // shift open (Z-session authoring) — the server payload never
        // carries them. Merge them back from the cached shift, or an app
        // restart severs the live shift from its SESSION_OPEN fiscal event
        // and X/Z authoring breaks for the rest of the shift.
        const cached = await getStoredValue<Shift>(StorageKeys.SHIFT);
        const normalized = withShiftUser(serverShift, cached);
        const shift: Shift = cached && cached.id === normalized.id
          ? {
              ...normalized,
              fiscal_shift_id: normalized.fiscal_shift_id ?? cached.fiscal_shift_id,
              fiscal_session_id: normalized.fiscal_session_id ?? cached.fiscal_session_id,
            }
          : normalized;
        await setStoredValue(StorageKeys.SHIFT, shift);
        set({ shift });
      } else {
        await removeStoredValue(StorageKeys.SHIFT);
        set({ shift: null });
      }
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

    // Task 9 — full location-stock re-baseline on shift open (spec §4.3): the
    // opening cashier starts the shift against fresh availability.
    // Fire-and-forget + swallow-and-log: it must never block the open (offline
    // it just times out).
    const pullLocationStockNonFatal = (): void => {
      void (async () => {
        const companyId = useAuthStore.getState().companyId;
        if (!companyId) return;
        const db = await getDatabase(companyId);
        await pullLocationStock(db, 'full', { terminalId: terminal.id });
      })().catch((err: unknown) => {
        console.error(
          '[POS][terminalStore][openShift] location-stock full pull failed (non-fatal)',
          serializeErrorForLog(err),
        );
      });
    };

    // v3 terminals are DEVICE-AUTHORITATIVE (mirror the receipt model): the
    // device mints the shift id (UUIDv7) + per-terminal monotone shift_number
    // and authors SESSION_OPEN + OPENING_FLOAT locally inside the fiscal
    // write-gate — NO server round-trip, fully offline. `pos_shifts` is a
    // projection of the synced fiscal events. The old server-first POST,
    // `offline-<uuid>` fork, and SHIFT_ALREADY_OPEN adoption are gone: there is
    // exactly one open path and one shift id.
    if (terminal.fiscal_schema_version === 3) {
      const auth = useAuthStore.getState();
      const id = uuidv7();
      const shift: Shift = {
        id,
        terminal_id: terminal.id,
        shift_number: 0, // assigned in-tx by the fiscal authoring; filled below
        status: 'OPEN',
        opening_cash: openingCash,
        opened_at: new Date().toISOString(),
        fiscal_shift_id: id,
        fiscal_session_id: id,
        user: {
          id: cashierId ?? auth.user?.id ?? '',
          name: auth.user?.name ?? 'Operator',
        },
      };
      try {
        const fiscalShift = await authorShiftOpenFiscalEvents(terminal, shift, openingCash);
        await setStoredValue(StorageKeys.SHIFT, fiscalShift);
        set({ shift: fiscalShift, isLoading: false });
      } catch (error) {
        set({ isLoading: false });
        throw error;
      }
      pullLocationStockNonFatal();
      return;
    }

    // Legacy pre-cutover (v<3) terminals remain server-authoritative.
    let shift: Shift;
    try {
      const body: Record<string, string> = {
        terminal_code: terminal.code,
        opening_cash: openingCash,
      };
      if (cashierId) {
        body['cashier_id'] = cashierId;
      }
      shift = withShiftUser(await apiPost<ServerShiftPayload>('/pos/shifts/open', body), null);
    } catch (error) {
      set({ isLoading: false });
      throw error;
    }

    pullLocationStockNonFatal();
    await setStoredValue(StorageKeys.SHIFT, shift);
    set({ shift, isLoading: false });
  },

  closeShift: async (actualCash: string) => {
    const { terminal, shift } = get();
    if (!shift) throw new Error('No active shift');

    set({ isLoading: true });

    // v3 is device-authoritative: SESSION_CLOSE + Z_REPORT are authored locally
    // (generateZReport, called before this) and the local_shifts row is already
    // closed; the server pos_shifts row is closed by the projection. The REST
    // close is retired (returns 409), so this just clears device state.
    if (terminal?.fiscal_schema_version !== 3) {
      try {
        await apiPost<Shift>(`/pos/shifts/${shift.id}/close`, {
          actual_cash: actualCash,
        });
      } catch {
        console.warn('[Terminal] Shift close API failed (offline), closing locally');
      }
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
        && stillCurrent.pos_stock_policy === fresh.pos_stock_policy
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
