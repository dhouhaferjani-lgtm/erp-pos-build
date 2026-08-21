import { create } from 'zustand';

/**
 * Cross-screen request channel for the ONE real shift-closure flow.
 *
 * The real close-shift UX is `EndOfDayPreviewModal`, mounted by `Header` (which
 * owns the fraud-settings / authorized-manager / manager-PIN-throttle wiring and
 * the Z generation). `Header` is mounted on every route, so any screen can ask
 * for that flow instead of standing up a second, weaker closure path.
 *
 * A monotonically increasing request id — not a boolean — so `Header` keeps
 * ownership of the modal's open state and still runs its fail-closed
 * `handleOpenEndOfDay` policy reset on every request, including repeats.
 */
interface ShiftActionsState {
  endOfDayRequestId: number;
  requestEndOfDay: () => void;
}

export const useShiftActionsStore = create<ShiftActionsState>()((set) => ({
  endOfDayRequestId: 0,
  requestEndOfDay: () => set((state) => ({ endOfDayRequestId: state.endOfDayRequestId + 1 })),
}));
