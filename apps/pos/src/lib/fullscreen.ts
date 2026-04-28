import { useEffect, useRef } from 'react';
import { isTauriEnvironment } from '@/lib/printing';
import { useSettingsStore } from '@/stores/settingsStore';

const RETRY_DELAY_MS = 250;
const READBACK_DELAY_MS = 500;
const WATCHDOG_INTERVAL_MS = 30_000;

type TauriWindow = {
  setDecorations: (enabled: boolean) => Promise<void>;
  setFullscreen: (enabled: boolean) => Promise<void>;
  setSkipTaskbar: (skip: boolean) => Promise<void>;
  setAlwaysOnTop: (onTop: boolean) => Promise<void>;
  setSize: (size: unknown) => Promise<void>;
  center: () => Promise<void>;
  isFullscreen: () => Promise<boolean>;
};

/**
 * Invoke a single Tauri window API, retrying once after RETRY_DELAY_MS on failure.
 * If both attempts fail, log an error and return — the caller continues the chain.
 */
async function callWithRetry(
  label: string,
  fn: () => Promise<void>,
): Promise<void> {
  try {
    await fn();
    return;
  } catch (firstErr: unknown) {
    console.warn(`[fullscreen] ${label} failed once, retrying in ${RETRY_DELAY_MS}ms:`, firstErr);
  }
  await new Promise<void>((resolve) => setTimeout(resolve, RETRY_DELAY_MS));
  try {
    await fn();
  } catch (secondErr: unknown) {
    console.error(`[fullscreen] ${label} failed on retry, continuing chain:`, secondErr);
  }
}

/**
 * Readback: compare the actual Tauri window state to the desired state.
 * Returns true when they match, false when they diverge.
 */
export async function verifyFullscreenState(desired: boolean): Promise<boolean> {
  if (!isTauriEnvironment()) {
    return Boolean(document.fullscreenElement) === desired;
  }
  try {
    const { getCurrentWindow } = await import('@tauri-apps/api/window');
    const win = getCurrentWindow() as unknown as TauriWindow;
    const actual = await win.isFullscreen();
    return actual === desired;
  } catch (err: unknown) {
    console.warn('[fullscreen] verifyFullscreenState failed:', err);
    return false;
  }
}

/**
 * Apply or exit borderless fullscreen mode via Tauri window APIs.
 *
 * Resilience strategy:
 * 1. Each of the four Tauri APIs gets its own try/catch via `callWithRetry`,
 *    so a single throw never halts the chain.
 * 2. After the chain, a readback via `isFullscreen()` compares actual state
 *    to desired; on mismatch we schedule one more `setFullscreen(desired)`
 *    retry after READBACK_DELAY_MS.
 */
export async function applyFullscreen(enabled: boolean): Promise<void> {
  if (!isTauriEnvironment()) {
    try {
      if (enabled) {
        await document.documentElement.requestFullscreen?.();
      } else if (document.fullscreenElement) {
        await document.exitFullscreen?.();
      }
    } catch (err: unknown) {
      console.error('[fullscreen] Browser fullscreen toggle failed:', err);
    }
    return;
  }

  const { getCurrentWindow } = await import('@tauri-apps/api/window');
  const { LogicalSize } = await import('@tauri-apps/api/dpi');
  const win = getCurrentWindow() as unknown as TauriWindow;

  if (enabled) {
    await callWithRetry('setDecorations(false)', () => win.setDecorations(false));
    await callWithRetry('setFullscreen(true)', () => win.setFullscreen(true));
    await callWithRetry('setSkipTaskbar(true)', () => win.setSkipTaskbar(true));
    await callWithRetry('setAlwaysOnTop(true)', () => win.setAlwaysOnTop(true));
  } else {
    await callWithRetry('setAlwaysOnTop(false)', () => win.setAlwaysOnTop(false));
    await callWithRetry('setSkipTaskbar(false)', () => win.setSkipTaskbar(false));
    await callWithRetry('setFullscreen(false)', () => win.setFullscreen(false));
    await callWithRetry('setSize(1024x700)', () => win.setSize(new LogicalSize(1024, 700)));
    await callWithRetry('setDecorations(true)', () => win.setDecorations(true));
    await callWithRetry('center()', () => win.center());
  }

  // Readback verification with one more shot at fixing a desync.
  const ok = await verifyFullscreenState(enabled);
  if (!ok) {
    console.warn(`[fullscreen] Readback mismatch — retrying setFullscreen(${enabled}) in ${READBACK_DELAY_MS}ms`);
    await new Promise<void>((resolve) => setTimeout(resolve, READBACK_DELAY_MS));
    await callWithRetry(`setFullscreen(${enabled}) [post-readback]`, () => win.setFullscreen(enabled));
  }
}

/**
 * React hook: listens for the Escape key and exits fullscreen mode.
 * Only active when fullscreen is enabled.
 */
export function useFullscreenEscapeKey(): void {
  const fullscreen = useSettingsStore((s) => s.fullscreen);

  useEffect(() => {
    if (!fullscreen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        useSettingsStore.getState().setFullscreen(false);
        void applyFullscreen(false);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [fullscreen]);
}

/**
 * React hook: low-frequency watchdog that detects desync between the store's
 * `fullscreen` flag and the actual Tauri window state, and re-applies if needed.
 *
 * Runs every WATCHDOG_INTERVAL_MS (30s). Kept low-frequency because Tauri
 * window APIs are not free and this is a safety net, not a hot path.
 */
export function useFullscreenWatchdog(): void {
  const fullscreen = useSettingsStore((s) => s.fullscreen);
  const desiredRef = useRef(fullscreen);
  desiredRef.current = fullscreen;

  useEffect(() => {
    if (!isTauriEnvironment()) return;

    const tick = async () => {
      const desired = desiredRef.current;
      const ok = await verifyFullscreenState(desired);
      if (!ok) {
        console.info('[fullscreen] Watchdog detected desync — re-applying', { desired });
        await applyFullscreen(desired);
      }
    };

    // Fire once soon after mount, then on interval.
    const initial = setTimeout(() => { void tick(); }, 2_000);
    const interval = setInterval(() => { void tick(); }, WATCHDOG_INTERVAL_MS);

    return () => {
      clearTimeout(initial);
      clearInterval(interval);
    };
  }, []);
}
