import { useEffect, useRef } from 'react';
import { getEcho, peekEcho } from '@/lib/echo';
import { useAuthStore } from '@/stores/authStore';
import { useProductStore } from '@/stores/productStore';

const REFRESH_DEBOUNCE_MS = 500;

/**
 * Bug 1 — subscribe to the company-level POS catalog channel and refresh
 * the local catalog whenever the server signals "catalog changed."
 *
 * Channel: `private-tenant.{tenantId}.company.{companyId}.catalog`
 * Event:   `.catalog.changed` (payload is coarse — `{ reason, timestamp }`)
 *
 * On any event, the hook debounces 500ms and calls
 * `productStore.fetchProducts(true)` once. Debounce coalesces bulk admin
 * operations (e.g. an import that triggers N events back-to-back) into a
 * single refetch.
 *
 * The 60s polling tick in `runFullSync` is preserved as a fallback for
 * when the WebSocket is down — this hook only narrows the latency from
 * 60s to ~real-time. No UI state is exposed because the catalog refresh
 * is invisible to the cashier; connection diagnostics live in the
 * existing `useTerminalActivation` hook for the activation surface.
 */
export function useCatalogChannel(): void {
  const { user, companyId } = useAuthStore();
  const fetchProducts = useProductStore((s) => s.fetchProducts);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!user || !companyId) return;

    const tenantId = user.tenantId;
    const channelName = `tenant.${tenantId}.company.${companyId}.catalog`;
    let subscribed = true;

    const scheduleRefresh = (): void => {
      if (debounceRef.current !== null) {
        clearTimeout(debounceRef.current);
      }
      debounceRef.current = setTimeout(() => {
        debounceRef.current = null;
        if (!subscribed) return;
        void fetchProducts(true);
      }, REFRESH_DEBOUNCE_MS);
    };

    try {
      console.log(`[WS][catalog] Subscribing to private-${channelName}`);
      const echo = getEcho();
      const channel = echo.private(channelName);

      channel
        .subscribed(() => {
          console.log(`[WS][catalog] Subscribed to private-${channelName}`);
        })
        .error((err: unknown) => {
          console.warn(`[WS][catalog] Channel error for private-${channelName}:`, err);
        })
        .listen('.catalog.changed', (payload: unknown) => {
          console.log('[WS][catalog] Received catalog.changed', payload);
          if (subscribed) scheduleRefresh();
        });
    } catch (err) {
      console.warn('[WS][catalog] Echo initialization failed:', err);
    }

    return () => {
      subscribed = false;
      if (debounceRef.current !== null) {
        clearTimeout(debounceRef.current);
        debounceRef.current = null;
      }
      // Codex r5 P2 — use peekEcho() so cleanup never lazily creates a
      // fresh Echo instance. On the logout path, authStore.logout() calls
      // disconnectEcho() before clearing user/companyId, which fires this
      // effect cleanup; calling getEcho() there would re-create an
      // unauthenticated WebSocket just to leave a channel that no longer
      // exists.
      const echo = peekEcho();
      if (echo !== null) {
        try {
          echo.leave(channelName);
        } catch {
          // Echo may already be disconnected.
        }
      }
    };
  }, [user, companyId, fetchProducts]);
}
