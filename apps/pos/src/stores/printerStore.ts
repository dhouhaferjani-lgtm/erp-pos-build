import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { PrinterConfig } from '@/lib/printing';

export type PaperWidth = '80mm' | '58mm';
export type CutMode = 'partial' | 'full' | 'none';
export type PrinterEncoding = 'cp437' | 'cp858' | 'cp1252';

export interface PrinterSettings {
  paperWidth: PaperWidth;
  cutMode: CutMode;
  copies: number;
  footerText: string;
  encoding: PrinterEncoding;
}

interface PrinterState {
  /** Currently configured receipt printer. */
  printerConfig: PrinterConfig | null;
  /** Whether to automatically print receipts after checkout. */
  autoPrint: boolean;
  /** Advanced printer settings. */
  settings: PrinterSettings;
  setPrinterConfig: (config: PrinterConfig) => void;
  clearPrinterConfig: () => void;
  setAutoPrint: (enabled: boolean) => void;
  updateSettings: (partial: Partial<PrinterSettings>) => void;
}

const defaultSettings: PrinterSettings = {
  paperWidth: '80mm',
  cutMode: 'partial',
  copies: 1,
  footerText: '',
  encoding: 'cp1252',
};

export const usePrinterStore = create<PrinterState>()(
  persist(
    (set) => ({
      printerConfig: null,
      autoPrint: true,
      settings: defaultSettings,

      setPrinterConfig: (config: PrinterConfig) => {
        set({ printerConfig: config });
      },

      clearPrinterConfig: () => {
        set({ printerConfig: null });
      },

      setAutoPrint: (enabled: boolean) => {
        set({ autoPrint: enabled });
      },

      updateSettings: (partial: Partial<PrinterSettings>) => {
        set((state) => ({
          settings: { ...state.settings, ...partial },
        }));
      },
    }),
    {
      name: 'izipos-printer',
    },
  ),
);
