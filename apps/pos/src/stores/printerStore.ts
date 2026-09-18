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

/**
 * Pure forward-migration for the persisted printer `encoding`. Exported so the
 * zustand-persist `migrate` fn AND the unit test share ONE mapping.
 *
 * Device recette 2026-09-18: a staging terminal printed `Re?u` / `Op?rateur` /
 * `Qt?` / `Esp?ces` because its persisted value was `cp437`. CP437 has no
 * `é`/`è`/`ç` cell at all, so it can never print French — a terminal left on
 * it is moved to the `cp1252` default on first launch. `cp858` (which DOES
 * carry the accents) and `cp1252` pass through; the operator can still select
 * cp437/cp858 by hand afterwards.
 */
export function migratePrinterEncoding(old: string): PrinterEncoding {
  if (old === 'cp858' || old === 'cp1252') return old;
  return defaultSettings.encoding;
}

/**
 * Forward-migrate one persisted `izipos-printer` payload. Rewrites the
 * encoding only; every other stored field (printer config, auto-print, paper
 * width, cut mode, copies, footer) is carried over verbatim.
 */
export function migratePrinterState(persisted: unknown): PrinterState {
  const state = (persisted ?? {}) as Partial<PrinterState>;
  const settings = { ...defaultSettings, ...(state.settings ?? {}) };
  return {
    ...(state as PrinterState),
    settings: {
      ...settings,
      encoding: migratePrinterEncoding(String(settings.encoding)),
    },
  };
}

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
      // v1 — terminals persisted on `cp437` are moved to `cp1252`: CP437
      // cannot represent a single French accent (device recette 2026-09-18).
      version: 1,
      migrate: (persisted, _version) => migratePrinterState(persisted),
    },
  ),
);
