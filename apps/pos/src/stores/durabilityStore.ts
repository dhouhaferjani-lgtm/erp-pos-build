/**
 * Round-2 T32-B2: Zustand store for spec §12 off-device durability state.
 *
 * Spec §12 requires an operator-visible unsynced-risk indicator + a forced-
 * archive/export threshold the operator workflow gates on. Round-1 created
 * the `OffDeviceDurabilityService` + `UnsyncedRiskIndicator` but neither was
 * wired to a real UX surface — that left the conservation control dead.
 *
 * This store owns the Phase 1 operator-visible state:
 *
 *   - `riskLevel` — last polled value of `service.unsyncedRisk()`.
 *   - `forceArchiveRequired` — last polled value of `service.shouldForceArchive()`.
 *     When true, the operator workflow MUST gate further fiscal authoring
 *     on the operator acknowledging the gate (Phase 1 stub action).
 *   - `acknowledgedUntil` — epoch-millis grace window the operator gets after
 *     pressing "Acknowledge & Continue" on the durability gate modal. Phase 1
 *     records the acknowledgment without doing the actual archive transfer
 *     (that's Phase 2). The grace expires after one hour by default so the
 *     gate remains a real conservation control rather than a one-time
 *     dismissal — the operator must re-acknowledge if the unsynced backlog
 *     stays above threshold.
 *
 * The polling loop lives in `useFiscalDurabilityPolling` (a hook the
 * AppShell mounts once the company DB + service are available). The
 * indicator + gate modal read state from here, so the wiring is decoupled
 * from how the service is constructed.
 */

import { create } from 'zustand';

import type { UnsyncedRiskLevel } from '@/lib/fiscal/OffDeviceDurabilityService';

/**
 * Default Phase 1 acknowledgment grace window. After the operator presses
 * "Acknowledge & Continue" on the durability gate, the gate re-arms after
 * this many ms have elapsed. One hour matches the spec §12 age-warn default
 * — the gate stays a real conservation control, not a one-time dismissal.
 */
export const DURABILITY_ACKNOWLEDGMENT_GRACE_MS = 60 * 60 * 1000;

interface DurabilityState {
  /** Last polled risk level; `null` before the first poll completes. */
  riskLevel: UnsyncedRiskLevel | null;
  /** Last polled value of `service.shouldForceArchive()`. */
  forceArchiveRequired: boolean;
  /** Epoch-millis the current acknowledgment grace expires; `null` if no live ack. */
  acknowledgedUntil: number | null;
  /** Last error from the polling loop (operational visibility). */
  lastPollError: string | null;
}

interface DurabilityActions {
  /** Push the latest polled result from the polling hook. */
  setPollResult: (result: { riskLevel: UnsyncedRiskLevel; forceArchiveRequired: boolean }) => void;
  /** Record a polling error (does NOT mutate riskLevel — the operator keeps the last good value). */
  setPollError: (message: string | null) => void;
  /**
   * Operator pressed "Acknowledge & Continue". Records a grace window of
   * `DURABILITY_ACKNOWLEDGMENT_GRACE_MS` from now. Phase 1 stub action — Phase 2
   * will wire the actual off-device archive transfer here.
   */
  acknowledgeDurabilityGate: () => void;
  /** Test-only — clear the acknowledgment grace (resets to gate-armed). */
  resetAcknowledgment: () => void;
  /** Test-only — full reset between vitest runs. */
  reset: () => void;
}

type DurabilityStore = DurabilityState & DurabilityActions;

const initialState: DurabilityState = {
  riskLevel: null,
  forceArchiveRequired: false,
  acknowledgedUntil: null,
  lastPollError: null,
};

export const useDurabilityStore = create<DurabilityStore>()((set) => ({
  ...initialState,

  setPollResult: ({ riskLevel, forceArchiveRequired }) => {
    set({ riskLevel, forceArchiveRequired, lastPollError: null });
  },

  setPollError: (message) => {
    set({ lastPollError: message });
  },

  acknowledgeDurabilityGate: () => {
    set({ acknowledgedUntil: Date.now() + DURABILITY_ACKNOWLEDGMENT_GRACE_MS });
  },

  resetAcknowledgment: () => {
    set({ acknowledgedUntil: null });
  },

  reset: () => {
    set(initialState);
  },
}));

/**
 * Selector — `true` when the operator workflow MUST gate authoring on a
 * durability acknowledgment. The gate is armed when `forceArchiveRequired`
 * is true AND no live acknowledgment grace covers the current moment.
 */
export function isDurabilityGateArmed(state: DurabilityState): boolean {
  if (!state.forceArchiveRequired) return false;
  if (state.acknowledgedUntil === null) return true;
  return Date.now() >= state.acknowledgedUntil;
}
