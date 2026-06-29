import { useSettingsStore } from '@/stores/settingsStore';
import { applyTheme, type ThemeSettings } from '@/lib/theme';

/**
 * Apply the currently-persisted appearance settings to `<html>` immediately.
 * Call once from `main.tsx` before React renders to prevent a theme flash.
 * Kept out of ThemeProvider.tsx so that file only exports a component
 * (react-refresh / fast-refresh discipline).
 */
export function syncThemeFromStore(): void {
  const s = useSettingsStore.getState();
  const settings: ThemeSettings = {
    theme: s.theme,
    accent: s.accent,
    corner: s.corner,
    density: s.density,
    cartPosition: s.cartPosition,
  };
  applyTheme(document.documentElement, settings);
}
