import { useEffect, useLayoutEffect, useRef, useCallback } from 'react';
import { useScannerStore, type ScannerKeyboardLayout } from '@/stores/scannerStore';
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
 *
 * `'auto'` (the default) picks between the two buffers per scan — see
 * `pickAutoBarcode` — so a US-programmed scanner on an FR-AZERTY host works
 * with no setting to discover. `'system'` and `'us'` stay explicit overrides.
 */
export function useBarcodeScanner({ onScan, enabled = true }: UseBarcodeScannerOptions): void {
  const rawRef = useRef<string>('');
  const decodedRef = useRef<string>('');
  const decodeFailedRef = useRef<boolean>(false);
  const targetRef = useRef<EventTarget | null>(null);
  const targetMixedRef = useRef<boolean>(false);
  const lastKeystrokeRef = useRef<number>(0);
  const onScanRef = useRef(onScan);

  // "Latest ref" — synced from a layout effect, never written during render
  // (React Doctor `no-ref-current-in-render`). The keydown listener reads
  // `onScanRef.current` at event time, so every burst still reaches the
  // callback from the most recent render.
  useLayoutEffect(() => {
    onScanRef.current = onScan;
  });

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
      // Scanners never set `repeat`; Windows auto-repeat (~30 ms) is under the 50 ms threshold.
      if (e.repeat) return;

      const { keyboardLayout, keystrokeThresholdMs, minBarcodeLength, soundOnScan } =
        useScannerStore.getState();

      const now = performance.now();
      const hasBurst =
        rawRef.current.length > 0 || decodedRef.current.length > 0 || decodeFailedRef.current;

      if (hasBurst && now - lastKeystrokeRef.current > keystrokeThresholdMs) {
        resetBuffer();
      }

      if (e.key === 'Enter') {
        const barcode = pickBarcode(
          keyboardLayout,
          rawRef.current,
          decodedRef.current,
          decodeFailedRef.current,
        );
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

      const decodes = keyboardLayout === 'us' || keyboardLayout === 'auto';
      const isPrintable = e.key.length === 1;
      const isDeadKey = e.key === 'Dead';
      if (!isPrintable && !(isDeadKey && decodes)) return;

      const burstWasEmpty = !(
        rawRef.current.length > 0 ||
        decodedRef.current.length > 0 ||
        decodeFailedRef.current
      );
      lastKeystrokeRef.current = now;

      if (isPrintable) {
        // A Dead key is not printable, so it falls through here and contributes nothing to the raw token.
        rawRef.current += e.key;
      }
      if (decodes) {
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

/**
 * Characters a real barcode is made of. A token outside this class on an
 * AZERTY/QWERTZ host is the signature of a US-programmed scanner whose digits
 * and symbols were re-mapped by the host layout (`0012345678905` → `àà&é"'(-è_çà(`).
 */
const BARCODE_TOKEN = /^[0-9A-Za-z][0-9A-Za-z._\-/+ ]*$/;
const BARCODE_CHAR = /[0-9A-Za-z._\-/+ ]/;

/**
 * `'auto'`: take the decoded token only when the evidence of a layout mismatch
 * is unambiguous — the decode is complete, it looks like a barcode, it differs
 * from what the host produced, and the host token contains a character no
 * barcode would carry. Anything less (a plausible raw token, a partial decode,
 * an identical decode) keeps the received text, so a correctly configured
 * terminal is never second-guessed.
 */
function pickAutoBarcode(raw: string, decoded: string, decodeFailed: boolean): string {
  if (decodeFailed) return raw;
  if (!BARCODE_TOKEN.test(decoded)) return raw;
  if (raw === decoded) return raw;
  if (![...raw].some((char) => !BARCODE_CHAR.test(char))) return raw;
  return decoded;
}

function pickBarcode(
  layout: ScannerKeyboardLayout,
  raw: string,
  decoded: string,
  decodeFailed: boolean,
): string {
  if (layout === 'us') return decodeFailed ? raw : decoded;
  if (layout === 'auto') return pickAutoBarcode(raw, decoded, decodeFailed);
  return raw;
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
