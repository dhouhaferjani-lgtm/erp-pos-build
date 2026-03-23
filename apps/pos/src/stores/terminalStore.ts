import { create } from 'zustand';
import { apiGet, apiPost } from '@/lib/api';
import { getDeviceId } from '@/lib/device';
import { getStoredValue, setStoredValue, removeStoredValue, StorageKeys } from '@/lib/storage';
import { getDatabase } from '@/lib/db';
import { pullTerminalState, pullZChainState } from '@/lib/sync/syncService';
import { SyncScheduler } from '@/lib/sync/syncScheduler';
import { useAuthStore } from '@/stores/authStore';
import { useSyncStore } from '@/stores/syncStore';

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
}

interface TerminalActions {
  initialize: () => Promise<void>;
  fetchAvailable: () => Promise<Terminal[]>;
  claimTerminal: (terminalId: string, hardwareIdentifier: string) => Promise<void>;
  requestTerminal: (locationId: string, suggestedName: string, hardwareIdentifier: string) => Promise<Terminal>;
  checkTerminalStatus: (terminalId: string) => Promise<Terminal>;
  fetchCurrentShift: () => Promise<void>;
  openShift: (openingCash: string, cashierId?: string) => Promise<void>;
  closeShift: (actualCash: string) => Promise<void>;
  reset: () => void;
}

type TerminalStore = TerminalState & TerminalActions;

const initialState: TerminalState = {
  terminal: null,
  pendingTerminalId: null,
  shift: null,
  isLoading: false,
};

/**
 * Seed the local SQLite terminal_state and Z-chain state from the server.
 * Must run after a terminal becomes active so offline receipts and Z-reports
 * can compute fiscal hashes.
 */
async function seedOfflineHashChain(terminalId: string): Promise<void> {
  const companyId = useAuthStore.getState().companyId;
  if (!companyId) return;
  try {
    const db = await getDatabase(companyId);
    await pullTerminalState(db, terminalId);
    await pullZChainState(db, terminalId);

    // Start the background sync scheduler
    const scheduler = new SyncScheduler(db, terminalId);
    useSyncStore.getState().setScheduler(scheduler);
    scheduler.start();
  } catch (error) {
    console.error('[Terminal] Failed to seed offline hash chain:', error);
  }
}

export const useTerminalStore = create<TerminalStore>()((set, get) => ({
  ...initialState,

  initialize: async () => {
    set({ isLoading: true });
    try {
      // 1. Check localStorage for a fully-activated terminal
      const terminal = await getStoredValue<Terminal>(StorageKeys.TERMINAL);
      if (terminal) {
        set({ terminal });
        // Ensure offline hash chain is seeded (may be missing after DB reset/reinstall)
        await seedOfflineHashChain(terminal.id);
        await get().fetchCurrentShift();
        return;
      }

      // 2. Check if there's a pending terminal ID from a previous request
      const pendingId = await getStoredValue<string>(StorageKeys.PENDING_TERMINAL_ID);
      if (pendingId) {
        console.log('[Terminal] Found stored pending terminal ID:', pendingId);
        set({ pendingTerminalId: pendingId });
        // Check if it was activated while we were away
        try {
          const pending = await apiGet<Terminal>(`/pos/terminals/${pendingId}`);
          if (pending.is_active) {
            console.log('[Terminal] Pending terminal is now active:', pending.code);
            await setStoredValue(StorageKeys.TERMINAL, pending);
            await removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
            await seedOfflineHashChain(pending.id);
            set({ terminal: pending, pendingTerminalId: null });
            await get().fetchCurrentShift();
            return;
          }
        } catch (err) {
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
        const found = await apiGet<Terminal | null>(`/pos/terminals/by-device/${deviceId}`);
        if (found?.is_active) {
          await setStoredValue(StorageKeys.TERMINAL, found);
          await seedOfflineHashChain(found.id);
          set({ terminal: found });
          await get().fetchCurrentShift();
        } else if (found && !found.is_active) {
          // Found but not yet activated — track it as pending
          await setStoredValue(StorageKeys.PENDING_TERMINAL_ID, found.id);
          set({ pendingTerminalId: found.id });
        }
      } catch {
        // No terminal for this device, that's fine
      }
    } catch (error) {
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
      set({ shift });
    } catch {
      set({ shift: null });
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
      set({ shift, isLoading: false });
    } catch (error) {
      set({ isLoading: false });
      throw error;
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
      set({ shift: null, isLoading: false });
    } catch (error) {
      set({ isLoading: false });
      throw error;
    }
  },

  reset: () => {
    useSyncStore.getState().scheduler?.stop();
    useSyncStore.getState().setScheduler(null);
    useSyncStore.getState().reset();
    set(initialState);
    void removeStoredValue(StorageKeys.TERMINAL);
    void removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
  },
}));
