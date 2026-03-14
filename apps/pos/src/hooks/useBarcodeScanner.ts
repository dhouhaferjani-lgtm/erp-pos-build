import { useEffect, useRef, useCallback } from 'react';
import { useScannerStore } from '@/stores/scannerStore';

interface UseBarcodeScannerOptions {
  /** Called when a barcode scan is detected. */
  onScan: (barcode: string) => void;
  /** Whether the scanner detection is active (default: true). */
  enabled?: boolean;
}

/**
 * Hook that detects barcode scanner input (keyboard wedge mode).
 *
 * USB barcode scanners emulate keyboard input — they type characters rapidly
 * and end with Enter. This hook distinguishes scanner input from normal typing
 * by measuring inter-keystroke timing.
 *
 * Reads threshold and min-length from the scanner settings store.
 * Optionally plays an audible beep via the Web Audio API on scan.
 */
export function useBarcodeScanner({ onScan, enabled = true }: UseBarcodeScannerOptions): void {
  const bufferRef = useRef<string>('');
  const lastKeystrokeRef = useRef<number>(0);
  const onScanRef = useRef(onScan);

  onScanRef.current = onScan;

  const resetBuffer = useCallback(() => {
    bufferRef.current = '';
    lastKeystrokeRef.current = 0;
  }, []);

  useEffect(() => {
    if (!enabled) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;

      const { keystrokeThresholdMs, minBarcodeLength, soundOnScan } =
        useScannerStore.getState();

      const now = performance.now();
      const timeSinceLastKeystroke = now - lastKeystrokeRef.current;

      if (timeSinceLastKeystroke > keystrokeThresholdMs && bufferRef.current.length > 0) {
        resetBuffer();
      }

      if (e.key === 'Enter') {
        if (bufferRef.current.length >= minBarcodeLength) {
          e.preventDefault();
          e.stopPropagation();
          const barcode = bufferRef.current;
          resetBuffer();

          if (soundOnScan) {
            playBeep();
          }

          onScanRef.current(barcode);
        } else {
          resetBuffer();
        }
        return;
      }

      if (e.key.length === 1) {
        lastKeystrokeRef.current = now;
        bufferRef.current += e.key;
      }
    };

    window.addEventListener('keydown', handleKeyDown, { capture: true });

    return () => {
      window.removeEventListener('keydown', handleKeyDown, { capture: true });
    };
  }, [enabled, resetBuffer]);
}

/** Play a short beep (~100ms, 1000Hz) via the Web Audio API. */
function playBeep(): void {
  try {
    const ctx = new AudioContext();
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();

    oscillator.type = 'square';
    oscillator.frequency.setValueAtTime(1000, ctx.currentTime);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);

    oscillator.connect(gain);
    gain.connect(ctx.destination);

    oscillator.start();
    oscillator.stop(ctx.currentTime + 0.1);

    oscillator.onended = () => {
      void ctx.close();
    };
  } catch {
    // Web Audio not available — silently ignore
  }
}
