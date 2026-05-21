/**
 * Round-2 T32-B2: useFiscalDurabilityPolling pushes service results into the
 * durability store. This is the wire-up that turns spec §12 conservation
 * state into operator-visible UI — the polling hook is the bridge from
 * `OffDeviceDurabilityService` into `useDurabilityStore`, which both the
 * indicator + the gate modal read.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor, cleanup } from '@testing-library/react';

import { useDurabilityStore } from '@/stores/durabilityStore';
import { useFiscalDurabilityPolling } from '../useFiscalDurabilityPolling';

interface FakeService {
  unsyncedRisk: ReturnType<typeof vi.fn>;
  shouldForceArchive: ReturnType<typeof vi.fn>;
}

function makeFakeService(opts: {
  riskLevel?: 'normal' | 'elevated' | 'escalated';
  forceArchive?: boolean;
  riskError?: Error;
} = {}): FakeService {
  const risk = opts.riskLevel ?? 'normal';
  const force = opts.forceArchive ?? false;

  return {
    unsyncedRisk: vi.fn(async () => {
      if (opts.riskError) throw opts.riskError;
      return risk;
    }),
    shouldForceArchive: vi.fn(async () => force),
  };
}

afterEach(() => {
  cleanup();
  useDurabilityStore.getState().reset();
});

describe('useFiscalDurabilityPolling', () => {
  it('is a no-op when service is null (pre-DB-ready path)', () => {
    renderHook(() => useFiscalDurabilityPolling(null));
    expect(useDurabilityStore.getState().riskLevel).toBeNull();
    expect(useDurabilityStore.getState().forceArchiveRequired).toBe(false);
  });

  it('pushes the first poll result into the store on mount', async () => {
    const service = makeFakeService({ riskLevel: 'elevated', forceArchive: true });
    renderHook(() =>
      useFiscalDurabilityPolling(
        service as unknown as Parameters<typeof useFiscalDurabilityPolling>[0],
        { intervalMs: 0 },
      ),
    );

    await waitFor(() => {
      expect(useDurabilityStore.getState().riskLevel).toBe('elevated');
    });
    expect(useDurabilityStore.getState().forceArchiveRequired).toBe(true);
    expect(useDurabilityStore.getState().lastPollError).toBeNull();
  });

  it('records the error message in the store without clobbering the last good riskLevel', async () => {
    // First a clean poll …
    const okService = makeFakeService({ riskLevel: 'normal', forceArchive: false });
    const { rerender } = renderHook(
      ({ svc }: { svc: ReturnType<typeof makeFakeService> | null }) =>
        useFiscalDurabilityPolling(
          svc as unknown as Parameters<typeof useFiscalDurabilityPolling>[0],
          { intervalMs: 0 },
        ),
      { initialProps: { svc: okService } },
    );
    await waitFor(() => {
      expect(useDurabilityStore.getState().riskLevel).toBe('normal');
    });

    // … then a service that throws.
    const badService = makeFakeService({ riskError: new Error('SQLite unavailable') });
    rerender({ svc: badService });

    await waitFor(() => {
      expect(useDurabilityStore.getState().lastPollError).toBe('SQLite unavailable');
    });
    // The last good riskLevel is preserved so the indicator does not flash
    // to null on a transient SQL hiccup.
    expect(useDurabilityStore.getState().riskLevel).toBe('normal');
  });
});
