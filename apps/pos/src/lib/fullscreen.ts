import { useEffect } from 'react';
import { isTauriEnvironment } from '@/lib/printing';
import { useSettingsStore } from '@/stores/settingsStore';

/**
 * Apply or exit borderless fullscreen mode via Tauri window APIs.
 *
 * Uses manual window sizing with PhysicalPosition/PhysicalSize instead of
 * native OS fullscreen to reliably cover the Windows 10/11 taskbar.
 */
export async function applyFullscreen(enabled: boolean): Promise<void> {
  try {
    if (isTauriEnvironment()) {
      const { getCurrentWindow, currentMonitor } = await import('@tauri-apps/api/window');
      const { PhysicalPosition, PhysicalSize, LogicalSize } = await import('@tauri-apps/api/dpi');
      const win = getCurrentWindow();

      if (enabled) {
        const monitor = await currentMonitor();
        // Order matters: remove decorations first, position/size, then raise above taskbar last
        await win.setDecorations(false);
        if (monitor) {
          const pos = monitor.position;
          const size = monitor.size;
          await win.setPosition(new PhysicalPosition(pos.x, pos.y));
          await win.setSize(new PhysicalSize(size.width, size.height));
        } else {
          await win.setFullscreen(true);
        }
        await win.setSkipTaskbar(true);
        await win.setAlwaysOnTop(true);
      } else {
        await win.setAlwaysOnTop(false);
        await win.setSkipTaskbar(false);
        await win.setFullscreen(false);
        await win.setDecorations(true);
        await win.setSize(new LogicalSize(1280, 800));
        await win.center();
      }
    } else if (enabled) {
      await document.documentElement.requestFullscreen?.();
    } else if (document.fullscreenElement) {
      await document.exitFullscreen?.();
    }
  } catch (err: unknown) {
    console.error('[fullscreen] Failed to apply window mode:', err);
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
