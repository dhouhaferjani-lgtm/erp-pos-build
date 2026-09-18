import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type ScannerKeyboardLayout = 'system' | 'us';

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

export const useScannerStore = create<ScannerState>()(
  persist(
    (set) => ({
      keyboardLayout: 'system',
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
    { name: 'izipos-scanner' },
  ),
);
