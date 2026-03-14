import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface ScannerState {
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
}

export const useScannerStore = create<ScannerState>()(
  persist(
    (set) => ({
      keystrokeThresholdMs: 50,
      minBarcodeLength: 3,
      autoAddToCart: true,
      soundOnScan: true,

      setKeystrokeThresholdMs: (keystrokeThresholdMs) => set({ keystrokeThresholdMs }),
      setMinBarcodeLength: (minBarcodeLength) => set({ minBarcodeLength }),
      setAutoAddToCart: (autoAddToCart) => set({ autoAddToCart }),
      setSoundOnScan: (soundOnScan) => set({ soundOnScan }),
    }),
    { name: 'izipos-scanner' },
  ),
);
