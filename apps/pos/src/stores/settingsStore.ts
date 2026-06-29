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

interface SettingsState {
  displayMode: 'grid' | 'visual';
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
  /** Appearance — light/dark theme. */
  theme: ThemeMode;
  /** Appearance — UX accent colour (not brand-locked). */
  accent: AccentName;
  /** Appearance — corner-radius style. */
  corner: CornerStyle;
  /** Appearance — product-grid density (column step). */
  density: Density;
  setDisplayMode: (mode: 'grid' | 'visual') => void;
  setLanguage: (lang: string) => void;
  setTouchMode: (enabled: boolean) => void;
  setFullscreen: (enabled: boolean) => void;
  setCartPosition: (position: 'start' | 'end') => void;
  setInactivityTimeout: (seconds: number) => void;
  setLockAfterSale: (enabled: boolean) => void;
  setConfirmLineDelete: (enabled: boolean) => void;
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
      displayMode: 'grid',
      language: 'en',
      touchMode: false,
      fullscreen: false,
      cartPosition: 'start',
      inactivityTimeout: 300,
      lockAfterSale: false,
      confirmLineDelete: true,
      theme: DEFAULT_THEME_SETTINGS.theme,
      accent: DEFAULT_THEME_SETTINGS.accent,
      corner: DEFAULT_THEME_SETTINGS.corner,
      density: DEFAULT_THEME_SETTINGS.density,

      setDisplayMode: (mode: 'grid' | 'visual') => {
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
      onRehydrateStorage: () => (state) => {
        if (state?.language) {
          void i18n.changeLanguage(state.language);
        }
      },
    },
  ),
);
