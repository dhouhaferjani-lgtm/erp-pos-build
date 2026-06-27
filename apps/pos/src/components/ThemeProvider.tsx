import { useLayoutEffect, type ReactNode } from 'react';
import { useSettingsStore } from '@/stores/settingsStore';
import { applyTheme } from '@/lib/theme';

/**
 * Subscribes the document root to the appearance knobs in `settingsStore` and
 * applies them as `data-*` attributes (see `lib/theme.applyTheme`). Mount once,
 * high in the tree. Live changes from the Settings page flip the whole UI with
 * no reload because the CSS token layer is attribute-driven.
 *
 * `main.tsx` also applies the persisted settings synchronously before first
 * paint (`syncThemeFromStore`) to avoid a flash of the default theme.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const theme = useSettingsStore((s) => s.theme);
  const accent = useSettingsStore((s) => s.accent);
  const corner = useSettingsStore((s) => s.corner);
  const density = useSettingsStore((s) => s.density);
  const cartPosition = useSettingsStore((s) => s.cartPosition);

  useLayoutEffect(() => {
    applyTheme(document.documentElement, {
      theme,
      accent,
      corner,
      density,
      cartPosition,
    });
  }, [theme, accent, corner, density, cartPosition]);

  return <>{children}</>;
}
