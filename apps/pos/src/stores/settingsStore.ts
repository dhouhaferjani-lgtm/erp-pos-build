import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import i18n from '@/lib/i18n';

interface SettingsState {
  displayMode: 'grid' | 'visual';
  language: string;
  /** Enables on-screen numpad/keyboard for touchscreen use. */
  touchMode: boolean;
  /** Launches the app in fullscreen mode. */
  fullscreen: boolean;
  setDisplayMode: (mode: 'grid' | 'visual') => void;
  setLanguage: (lang: string) => void;
  setTouchMode: (enabled: boolean) => void;
  setFullscreen: (enabled: boolean) => void;
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
    }),
    {
      name: 'izipos-settings',
    },
  ),
);
