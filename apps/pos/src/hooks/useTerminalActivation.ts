import { useEffect, useRef, useState } from 'react';
import { getEcho } from '@/lib/echo';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';

const POLL_INTERVAL_MS = 10_000;

/**
 * Hook that listens for real-time terminal activation via WebSocket
 * and falls back to 10s polling when WebSocket is unavailable.
 *
 * When activation is detected (via either channel), it calls
 * `checkTerminalStatus` which stores the terminal and triggers navigation.
 */
export function useTerminalActivation(terminalId: string | null): {
  wsConnected: boolean;
} {
  const [wsConnected, setWsConnected] = useState(false);
  const { user, companyId } = useAuthStore();
  const { checkTerminalStatus } = useTerminalStore();
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (!terminalId || !user || !companyId) return;

    const tenantId = user.tenantId;
    const channelName = `tenant.${tenantId}.company.${companyId}.pos.terminal.${terminalId}`;

    let subscribed = true;

    // --- WebSocket subscription ---
    try {
      console.log(`[WS] Subscribing to channel: private-${channelName}`);
      const echo = getEcho();
      const channel = echo.private(channelName);

      channel
        .subscribed(() => {
          console.log(`[WS] Subscribed to private-${channelName}`);
          if (subscribed) setWsConnected(true);
        })
        .error((err: unknown) => {
          console.warn(`[WS] Channel error for private-${channelName}:`, err);
          if (subscribed) setWsConnected(false);
        })
        .listen('.terminal.activated', () => {
          console.log('[WS] Received terminal.activated event');
          if (subscribed) {
            void checkTerminalStatus(terminalId);
          }
        });
    } catch (err) {
      console.warn('[WS] Echo initialization failed:', err);
      setWsConnected(false);
    }

    // --- Polling fallback ---
    // Always set up polling; it serves as resilience even if WS is connected
    // but primarily useful when WS is down.
    console.log(`[WS] Polling fallback active — every ${POLL_INTERVAL_MS / 1000}s`);
    pollRef.current = setInterval(() => {
      if (subscribed) {
        console.log('[WS] Polling: checking terminal status');
        void checkTerminalStatus(terminalId);
      }
    }, POLL_INTERVAL_MS);

    return () => {
      subscribed = false;

      // Leave channel
      try {
        const echo = getEcho();
        echo.leave(channelName);
      } catch {
        // Echo may already be disconnected
      }

      // Clear polling
      if (pollRef.current) {
        clearInterval(pollRef.current);
        pollRef.current = null;
      }
    };
  }, [terminalId, user, companyId, checkTerminalStatus]);

  return { wsConnected };
}
