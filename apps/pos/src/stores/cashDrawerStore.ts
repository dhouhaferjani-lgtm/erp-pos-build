import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface CashDrawerState {
  /** Pin connector: 0 = Pin 2 (standard), 1 = Pin 5. */
  pin: 0 | 1;
  /** Pulse on-time in 2ms units. Default 50 = 100ms. */
  pulseOnTime: number;
  /** Pulse off-time in 2ms units. Default 50 = 100ms. */
  pulseOffTime: number;
  /** Auto-open drawer when a cash sale completes. */
  openOnCashSale: boolean;
  /** Send BEL (beep) command after drawer kick. */
  beepOnOpen: boolean;

  setPin: (pin: 0 | 1) => void;
  setPulseOnTime: (value: number) => void;
  setPulseOffTime: (value: number) => void;
  setOpenOnCashSale: (enabled: boolean) => void;
  setBeepOnOpen: (enabled: boolean) => void;
}

export const useCashDrawerStore = create<CashDrawerState>()(
  persist(
    (set) => ({
      pin: 0,
      pulseOnTime: 50,
      pulseOffTime: 50,
      openOnCashSale: true,
      beepOnOpen: false,

      setPin: (pin) => set({ pin }),
      setPulseOnTime: (pulseOnTime) => set({ pulseOnTime }),
      setPulseOffTime: (pulseOffTime) => set({ pulseOffTime }),
      setOpenOnCashSale: (openOnCashSale) => set({ openOnCashSale }),
      setBeepOnOpen: (beepOnOpen) => set({ beepOnOpen }),
    }),
    { name: 'izipos-cash-drawer' },
  ),
);
