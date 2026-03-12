import { create } from 'zustand';
import { checkServerHealth, isBrowserOnline } from '@/lib/connectivity';

const ONLINE_INTERVAL_MS = 30_000;
const OFFLINE_INTERVAL_MS = 10_000;

interface ConnectivityState {
  isOnline: boolean;
  serverReachable: boolean;
  lastCheckedAt: number | null;
}

interface ConnectivityActions {
  checkNow: () => Promise<void>;
  startMonitoring: () => () => void;
}

type ConnectivityStore = ConnectivityState & ConnectivityActions;

export const useConnectivityStore = create<ConnectivityStore>()((set, get) => ({
  isOnline: isBrowserOnline(),
  serverReachable: false,
  lastCheckedAt: null,

  checkNow: async () => {
    // Quick offline hint
    if (!isBrowserOnline()) {
      set({ isOnline: false, serverReachable: false, lastCheckedAt: Date.now() });
      return;
    }

    const reachable = await checkServerHealth();
    set({
      isOnline: reachable,
      serverReachable: reachable,
      lastCheckedAt: Date.now(),
    });
  },

  startMonitoring: () => {
    // Initial check
    void get().checkNow();

    const intervalId = setInterval(() => {
      const { isOnline } = get();
      // Check more frequently when offline
      const interval = isOnline ? ONLINE_INTERVAL_MS : OFFLINE_INTERVAL_MS;
      const { lastCheckedAt } = get();
      if (lastCheckedAt && Date.now() - lastCheckedAt < interval) return;
      void get().checkNow();
    }, OFFLINE_INTERVAL_MS);

    // Listen for browser online/offline events
    function handleOnline() {
      void get().checkNow();
    }
    function handleOffline() {
      set({ isOnline: false, serverReachable: false, lastCheckedAt: Date.now() });
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      clearInterval(intervalId);
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  },
}));
