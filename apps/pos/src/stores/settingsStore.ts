import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import i18n from '@/lib/i18n';
import {
  DEFAULT_THEME_SETTINGS,
  type AccentName,
  type CornerStyle,
  type Density,
  type ThemeMode,
} from '@/lib/theme';

/**
 * Product-grid VIEW mode — the tri-density switch (Task 14).
 *
 * NOT to be confused with `ProductCard`'s own `displayMode` prop
 * (`'grid'|'visual'`, the card *layout*): a `'tableau'` card is nonsensical.
 * `ProductGrid` maps this view-mode → a card-layout for the vitrine path.
 *   - `vitrine` → image cards (`ProductCard` layout `'visual'`)
 *   - `liste`   → touch rows (`ProductListRow`)
 *   - `tableau` → desktop-dense grid (`ProductTable`)
 */
export type DisplayMode = 'vitrine' | 'liste' | 'tableau';

const DISPLAY_MODES: readonly DisplayMode[] = ['vitrine', 'liste', 'tableau'];

/**
 * Touchscreen-first default. The primary target is a 15" coarse-pointer POS,
 * so `liste` is the safe fallback everywhere `resolveDefaultMode` can't prove
 * a fine pointer.
 */
const DEFAULT_DISPLAY_MODE: DisplayMode = 'liste';

/**
 * Pure forward-migration for the persisted `displayMode`. Exported so the
 * zustand-persist `migrate` fn AND the unit test share ONE mapping.
 *   legacy 'grid'   → 'liste'
 *   legacy 'visual' → 'vitrine'
 *   new triplet     → itself
 *   anything else   → the default
 */
export function migrateDisplayMode(old: string): DisplayMode {
  if (old === 'grid') return 'liste';
  if (old === 'visual') return 'vitrine';
  if ((DISPLAY_MODES as readonly string[]).includes(old)) return old as DisplayMode;
  return DEFAULT_DISPLAY_MODE;
}

/**
 * Initial view-mode for a fresh install (no persisted state). Picks `tableau`
 * for a fine pointer (mouse desktop) and `liste` for coarse/touch. Guards
 * `window`/`matchMedia` so it never throws under SSR or jsdom tests.
 */
export function resolveDefaultMode(): DisplayMode {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return DEFAULT_DISPLAY_MODE;
  }
  try {
    if (window.matchMedia('(pointer: fine)').matches) return 'tableau';
    if (window.matchMedia('(pointer: coarse)').matches) return 'liste';
  } catch {
    return DEFAULT_DISPLAY_MODE;
  }
  return DEFAULT_DISPLAY_MODE;
}

interface SettingsState {
  displayMode: DisplayMode;
  language: string;
  /** Enables on-screen numpad/keyboard for touchscreen use. */
  touchMode: boolean;
  /** Launches the app in fullscreen mode. */
  fullscreen: boolean;
  /** Controls which side the cart panel appears on. 'start' = left, 'end' = right. */
  cartPosition: 'start' | 'end';
  /** Inactivity timeout in seconds before auto-lock. 0 = never. */
  inactivityTimeout: number;
  /** Lock the screen after completing a sale. */
  lockAfterSale: boolean;
  /** Require a second confirming tap before a cart line is removed (mis-tap guard). */
  confirmLineDelete: boolean;
  /** Shows parapharmacy skin and routine filters in the Filtres drawer. */
  parapharmacySkinFiltersEnabled: boolean;
  /** Appearance — light/dark theme. */
  theme: ThemeMode;
  /** Appearance — UX accent colour (not brand-locked). */
  accent: AccentName;
  /** Appearance — corner-radius style. */
  corner: CornerStyle;
  /** Appearance — product-grid density (column step). */
  density: Density;
  setDisplayMode: (mode: DisplayMode) => void;
  setLanguage: (lang: string) => void;
  setTouchMode: (enabled: boolean) => void;
  setFullscreen: (enabled: boolean) => void;
  setCartPosition: (position: 'start' | 'end') => void;
  setInactivityTimeout: (seconds: number) => void;
  setLockAfterSale: (enabled: boolean) => void;
  setConfirmLineDelete: (enabled: boolean) => void;
  setParapharmacySkinFiltersEnabled: (enabled: boolean) => void;
  setTheme: (theme: ThemeMode) => void;
  setAccent: (accent: AccentName) => void;
  setCorner: (corner: CornerStyle) => void;
  setDensity: (density: Density) => void;
}

export const SUPPORTED_LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'fr', label: 'Français' },
];

export const useSettingsStore = create<SettingsState>()(
  persist(
    (set) => ({
      displayMode: resolveDefaultMode(),
      language: 'en',
      touchMode: false,
      fullscreen: false,
      cartPosition: 'start',
      inactivityTimeout: 300,
      lockAfterSale: false,
      confirmLineDelete: true,
      parapharmacySkinFiltersEnabled: true,
      theme: DEFAULT_THEME_SETTINGS.theme,
      accent: DEFAULT_THEME_SETTINGS.accent,
      corner: DEFAULT_THEME_SETTINGS.corner,
      density: DEFAULT_THEME_SETTINGS.density,

      setDisplayMode: (mode: DisplayMode) => {
        set({ displayMode: mode });
      },

      setLanguage: (lang: string) => {
        set({ language: lang });
        void i18n.changeLanguage(lang);
      },

      setTouchMode: (enabled: boolean) => {
        set({ touchMode: enabled });
      },

      setFullscreen: (enabled: boolean) => {
        set({ fullscreen: enabled });
      },

      setCartPosition: (position: 'start' | 'end') => {
        set({ cartPosition: position });
      },

      setInactivityTimeout: (seconds: number) => {
        set({ inactivityTimeout: seconds });
      },

      setLockAfterSale: (enabled: boolean) => {
        set({ lockAfterSale: enabled });
      },

      setConfirmLineDelete: (enabled: boolean) => {
        set({ confirmLineDelete: enabled });
      },

      setParapharmacySkinFiltersEnabled: (enabled: boolean) => {
        set({ parapharmacySkinFiltersEnabled: enabled });
      },

      setTheme: (theme: ThemeMode) => {
        set({ theme });
      },

      setAccent: (accent: AccentName) => {
        set({ accent });
      },

      setCorner: (corner: CornerStyle) => {
        set({ corner });
      },

      setDensity: (density: Density) => {
        set({ density });
      },
    }),
    {
      name: 'izipos-settings',
      // v1 — widened `displayMode` from 'grid'|'visual' to the tri-mode triplet.
      version: 1,
      migrate: (persisted, _version) => {
        const state = (persisted ?? {}) as Partial<SettingsState> & {
          displayMode?: string;
        };
        if (typeof state.displayMode === 'string') {
          state.displayMode = migrateDisplayMode(state.displayMode);
        }
        return state as SettingsState;
      },
      onRehydrateStorage: () => (state) => {
        if (state?.language) {
          void i18n.changeLanguage(state.language);
        }
      },
    },
  ),
);
