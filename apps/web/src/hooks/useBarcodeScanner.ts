import { useEffect, useRef, useCallback } from 'react'

/**
 * Threshold in milliseconds between keystrokes to detect barcode scanner input.
 * USB barcode scanners typically fire keystrokes < 50ms apart.
 * Normal human typing is usually > 100ms between keystrokes.
 */
const SCANNER_KEYSTROKE_THRESHOLD_MS = 50

/**
 * Minimum barcode length to consider as valid scanner input.
 * Prevents accidental detection of short key sequences.
 */
const MIN_BARCODE_LENGTH = 3

interface UseBarcodeScannerOptions {
  /** Called when a barcode scan is detected */
  onScan: (barcode: string) => void
  /** Whether the scanner detection is active (default: true) */
  enabled?: boolean
  /** Let focused inputs handle their own completed value instead of raw key buffering. */
  ignoreInputElements?: boolean
}

/**
 * Hook that detects barcode scanner input (keyboard wedge mode).
 *
 * USB barcode scanners emulate keyboard input — they type characters rapidly
 * (< 50ms between keystrokes) and end with Enter. This hook distinguishes
 * scanner input from normal typing by measuring inter-keystroke timing.
 *
 * The hook listens globally (on `window`) so it works regardless of which
 * element has focus, which is important for POS workflows where the cashier
 * should not need to click on a specific input before scanning.
 */
export function useBarcodeScanner({ onScan, enabled = true, ignoreInputElements = false }: UseBarcodeScannerOptions): void {
  const bufferRef = useRef<string>('')
  const lastKeystrokeRef = useRef<number>(0)
  const onScanRef = useRef(onScan)

  useEffect(() => {
    onScanRef.current = onScan
  }, [onScan])

  const resetBuffer = useCallback(() => {
    bufferRef.current = ''
    lastKeystrokeRef.current = 0
  }, [])

  useEffect(() => {
    if (!enabled) {
      return
    }

    const handleKeyDown = (e: KeyboardEvent) => {
      if (ignoreInputElements) {
        const target = e.target
        if (
          target instanceof HTMLInputElement ||
          target instanceof HTMLTextAreaElement ||
          (target instanceof HTMLElement && target.isContentEditable)
        ) {
          return
        }
      }

      // Ignore modifier keys and special key combos (Ctrl+K, etc.)
      if (e.ctrlKey || e.metaKey || e.altKey) {
        return
      }

      const now = performance.now()
      const timeSinceLastKeystroke = now - lastKeystrokeRef.current

      // If too much time has passed since the last keystroke, reset buffer.
      // This means either this is the first character of a new scan,
      // or the user is typing normally.
      if (timeSinceLastKeystroke > SCANNER_KEYSTROKE_THRESHOLD_MS && bufferRef.current.length > 0) {
        resetBuffer()
      }

      if (e.key === 'Enter') {
        // Check if we have a valid barcode in the buffer
        if (bufferRef.current.length >= MIN_BARCODE_LENGTH) {
          e.preventDefault()
          e.stopPropagation()
          const barcode = bufferRef.current
          resetBuffer()
          onScanRef.current(barcode)
        } else {
          // Not a barcode — reset and let Enter propagate normally
          resetBuffer()
        }
        return
      }

      // Only accumulate printable single characters
      if (e.key.length === 1) {
        lastKeystrokeRef.current = now
        bufferRef.current += e.key
      }
    }

    // Use capture phase so we can intercept before other handlers
    window.addEventListener('keydown', handleKeyDown, { capture: true })

    return () => {
      window.removeEventListener('keydown', handleKeyDown, { capture: true })
    }
  }, [enabled, ignoreInputElements, resetBuffer])
}
