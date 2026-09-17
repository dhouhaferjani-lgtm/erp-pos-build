import { useEffect, useRef, useCallback } from 'react';
import { useScannerStore } from '@/stores/scannerStore';
import { decodeUsKey } from '@/lib/scan/usKeyboardLayout';

interface UseBarcodeScannerOptions {
  /**
   * Called when a barcode scan is detected. `target` is the element every
   * keydown of the burst was dispatched on (normally the focused input), or
   * `null` when focus moved during the burst.
   */
  onScan: (barcode: string, target: EventTarget | null) => void;
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
 * Two buffers run in parallel: `raw` holds `e.key` exactly as the host layout
 * produced it (what `'system'` mode delivers); `decoded` holds the US-QWERTY
 * character for each physical `e.code` (what `'us'` mode delivers). If any
 * event in a burst has no US mapping, the whole token falls back to `raw` so
 * a scan is never a mixed-layout hybrid. Character keydowns are never
 * intercepted — only the Enter terminator of a qualifying burst is.
 */
export function useBarcodeScanner({ onScan, enabled = true }: UseBarcodeScannerOptions): void {
  const rawRef = useRef<string>('');
  const decodedRef = useRef<string>('');
  const decodeFailedRef = useRef<boolean>(false);
  const targetRef = useRef<EventTarget | null>(null);
  const targetMixedRef = useRef<boolean>(false);
  const lastKeystrokeRef = useRef<number>(0);
  const onScanRef = useRef(onScan);

  onScanRef.current = onScan;

  const resetBuffer = useCallback(() => {
    rawRef.current = '';
    decodedRef.current = '';
    decodeFailedRef.current = false;
    targetRef.current = null;
    targetMixedRef.current = false;
    lastKeystrokeRef.current = 0;
  }, []);

  useEffect(() => {
    if (!enabled) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;

      const { keyboardLayout, keystrokeThresholdMs, minBarcodeLength, soundOnScan } =
        useScannerStore.getState();

      const now = performance.now();
      const hasBurst =
        rawRef.current.length > 0 || decodedRef.current.length > 0 || decodeFailedRef.current;

      if (hasBurst && now - lastKeystrokeRef.current > keystrokeThresholdMs) {
        resetBuffer();
      }

      if (e.key === 'Enter') {
        const barcode =
          keyboardLayout === 'us' && !decodeFailedRef.current ? decodedRef.current : rawRef.current;
        const target = targetMixedRef.current ? null : targetRef.current;
        resetBuffer();

        if (barcode.length >= minBarcodeLength) {
          e.preventDefault();
          e.stopPropagation();
          if (soundOnScan) {
            playBeep();
          }
          onScanRef.current(barcode, target);
        }
        return;
      }

      const isPrintable = e.key.length === 1;
      const isDeadKey = e.key === 'Dead';
      if (!isPrintable && !(isDeadKey && keyboardLayout === 'us')) return;

      const burstWasEmpty = !(
        rawRef.current.length > 0 ||
        decodedRef.current.length > 0 ||
        decodeFailedRef.current
      );
      lastKeystrokeRef.current = now;

      if (isPrintable) {
        rawRef.current += e.key;
      }
      if (keyboardLayout === 'us') {
        const decoded = decodeUsKey(e.code, e.shiftKey);
        if (decoded === null) {
          decodeFailedRef.current = true;
        } else {
          decodedRef.current += decoded;
        }
      }

      if (burstWasEmpty) {
        targetRef.current = e.target;
      } else if (e.target !== targetRef.current) {
        targetMixedRef.current = true;
      }
    };

    window.addEventListener('keydown', handleKeyDown, { capture: true });

    return () => {
      window.removeEventListener('keydown', handleKeyDown, { capture: true });
      // A burst interrupted by `enabled` flipping (e.g. shift closed) must not
      // replay on the next Enter after re-enable.
      resetBuffer();
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
