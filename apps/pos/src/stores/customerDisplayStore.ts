import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface MonitorInfo {
  name: string | null;
  position: [number, number];
  size: [number, number];
  scale_factor: number;
  is_primary: boolean;
}

interface CustomerDisplayPersistedState {
  /** Whether the CFD feature is active. */
  enabled: boolean;
  /** Monitor index to use. null = auto-detect secondary. */
  monitorIndex: number | null;
  /** Path or URL to the idle image. Empty = default logo. */
  idleImagePath: string;
}

interface CustomerDisplayRuntimeState {
  /** Whether the display window is currently open. */
  isOpen: boolean;
  /** Cached available monitors. */
  availableMonitors: MonitorInfo[];
}

interface CustomerDisplayActions {
  setEnabled: (enabled: boolean) => void;
  setMonitorIndex: (index: number | null) => void;
  setIdleImagePath: (path: string) => void;
  setIsOpen: (open: boolean) => void;
  setAvailableMonitors: (monitors: MonitorInfo[]) => void;
}

type CustomerDisplayStore = CustomerDisplayPersistedState &
  CustomerDisplayRuntimeState &
  CustomerDisplayActions;

export const useCustomerDisplayStore = create<CustomerDisplayStore>()(
  persist(
    (set) => ({
      // Persisted
      enabled: false,
      monitorIndex: null,
      idleImagePath: '',

      // Runtime (not persisted via partialize)
      isOpen: false,
      availableMonitors: [],

      setEnabled: (enabled) => set({ enabled }),
      setMonitorIndex: (monitorIndex) => set({ monitorIndex }),
      setIdleImagePath: (idleImagePath) => set({ idleImagePath }),
      setIsOpen: (isOpen) => set({ isOpen }),
      setAvailableMonitors: (availableMonitors) => set({ availableMonitors }),
    }),
    {
      name: 'izipos-customer-display',
      partialize: (state) => ({
        enabled: state.enabled,
        monitorIndex: state.monitorIndex,
        idleImagePath: state.idleImagePath,
      }),
    },
  ),
);
