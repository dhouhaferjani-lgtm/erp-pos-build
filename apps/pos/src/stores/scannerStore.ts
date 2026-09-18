import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * - `auto`   — decode the physical key codes only when the received text is
 *              provably the wrong layout (see `useBarcodeScanner`). Default.
 * - `system` — always keep the text the host layout produced.
 * - `us`     — always decode as a US-QWERTY scanner.
 */
export type ScannerKeyboardLayout = 'auto' | 'system' | 'us';

/** Persisted-state version. 1 = `system` default replaced by `auto`. */
const SCANNER_PERSIST_VERSION = 1;

interface ScannerState {
  /** Keyboard layout programmed into the scanner; system preserves received text. */
  keyboardLayout: ScannerKeyboardLayout;
  /** Max ms between keystrokes to consider as scanner input. Default 50. */
  keystrokeThresholdMs: number;
  /** Minimum characters to consider as a valid barcode. Default 3. */
  minBarcodeLength: number;
  /** Automatically add scanned product to cart. */
  autoAddToCart: boolean;
  /** Play a beep sound when a barcode is scanned. */
  soundOnScan: boolean;

  setKeystrokeThresholdMs: (value: number) => void;
  setMinBarcodeLength: (value: number) => void;
  setAutoAddToCart: (enabled: boolean) => void;
  setSoundOnScan: (enabled: boolean) => void;
  setKeyboardLayout: (layout: ScannerKeyboardLayout) => void;
}

/**
 * v0 → v1: installations still carrying the old `system` default (nobody ever
 * opened Paramètres › Scanner) move to `auto`. An explicit `us` choice is kept.
 * A v0 blob with no `keyboardLayout` falls through to the new `auto` default.
 */
function migrateScannerState(persisted: unknown, version: number): unknown {
  if (version >= SCANNER_PERSIST_VERSION) return persisted;
  if (persisted === null || typeof persisted !== 'object') return persisted;

  const state = persisted as Record<string, unknown>;
  if (state.keyboardLayout !== 'system') return state;

  return { ...state, keyboardLayout: 'auto' satisfies ScannerKeyboardLayout };
}

export const useScannerStore = create<ScannerState>()(
  persist(
    (set) => ({
      keyboardLayout: 'auto',
      keystrokeThresholdMs: 50,
      minBarcodeLength: 3,
      autoAddToCart: true,
      soundOnScan: true,

      setKeystrokeThresholdMs: (keystrokeThresholdMs) => set({ keystrokeThresholdMs }),
      setMinBarcodeLength: (minBarcodeLength) => set({ minBarcodeLength }),
      setAutoAddToCart: (autoAddToCart) => set({ autoAddToCart }),
      setSoundOnScan: (soundOnScan) => set({ soundOnScan }),
      setKeyboardLayout: (keyboardLayout) => set({ keyboardLayout }),
    }),
    {
      name: 'izipos-scanner',
      version: SCANNER_PERSIST_VERSION,
      migrate: migrateScannerState,
    },
  ),
);
