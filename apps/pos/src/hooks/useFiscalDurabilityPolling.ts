/**
 * Round-2 T32-B2: poll the off-device durability service into the durability
 * store. The hook is the single wiring point that turns spec §12 conservation
 * state into operator-visible UI:
 *
 *   service.unsyncedRisk()       → durabilityStore.riskLevel        → UnsyncedRiskIndicator
 *   service.shouldForceArchive() → durabilityStore.forceArchiveRequired → DurabilityGateModal
 *
 * AppShell mounts this once with the service instance produced by the Phase 1
 * durability service factory. Passing `null` (no service yet — e.g. before
 * the company DB is ready) makes the hook a no-op so the AppShell can mount
 * unconditionally without waiting on the durability surface.
 *
 * Polling defaults to 30s — matches the round-1 indicator's prop default
 * and stays within the spec §12 read-budget for the SQLite fiscal_events
 * count + age queries (both use the existing partial sync-pending index).
 */

import { useEffect } from 'react';

import type { OffDeviceDurabilityService } from '@/lib/fiscal/OffDeviceDurabilityService';
import { useDurabilityStore } from '@/stores/durabilityStore';

interface UseFiscalDurabilityPollingOptions {
  /** Poll interval (ms). Defaults to 30s; pass 0 in tests to disable the interval. */
  intervalMs?: number;
}

export function useFiscalDurabilityPolling(
  service: OffDeviceDurabilityService | null,
  options: UseFiscalDurabilityPollingOptions = {},
): void {
  const { intervalMs = 30_000 } = options;
  const setPollResult = useDurabilityStore((s) => s.setPollResult);
  const setPollError = useDurabilityStore((s) => s.setPollError);

  useEffect(() => {
    if (service === null) return;

    let cancelled = false;

    const tick = async (): Promise<void> => {
      try {
        const [riskLevel, forceArchiveRequired] = await Promise.all([
          service.unsyncedRisk(),
          service.shouldForceArchive(),
        ]);
        if (!cancelled) {
          setPollResult({ riskLevel, forceArchiveRequired });
        }
      } catch (error) {
        if (!cancelled) {
          setPollError(error instanceof Error ? error.message : String(error));
        }
      }
    };

    void tick();

    if (intervalMs > 0) {
      const handle = setInterval(() => {
        void tick();
      }, intervalMs);
      return () => {
        cancelled = true;
        clearInterval(handle);
      };
    }

    return () => {
      cancelled = true;
    };
  }, [service, intervalMs, setPollResult, setPollError]);
}
