import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { PrinterConfig } from '@/lib/printing';

interface PrinterState {
  /** Currently configured receipt printer. */
  printerConfig: PrinterConfig | null;
  /** Whether to automatically print receipts after checkout. */
  autoPrint: boolean;
  setPrinterConfig: (config: PrinterConfig) => void;
  clearPrinterConfig: () => void;
  setAutoPrint: (enabled: boolean) => void;
}

export const usePrinterStore = create<PrinterState>()(
  persist(
    (set) => ({
      printerConfig: null,
      autoPrint: true,

      setPrinterConfig: (config: PrinterConfig) => {
        set({ printerConfig: config });
      },

      clearPrinterConfig: () => {
        set({ printerConfig: null });
      },

      setAutoPrint: (enabled: boolean) => {
        set({ autoPrint: enabled });
      },
    }),
    {
      name: 'izipos-printer',
    },
  ),
);
