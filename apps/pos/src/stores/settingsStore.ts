import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import i18n from '@/lib/i18n';

interface SettingsState {
  displayMode: 'grid' | 'visual';
  language: string;
  setDisplayMode: (mode: 'grid' | 'visual') => void;
  setLanguage: (lang: string) => void;
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

      setDisplayMode: (mode: 'grid' | 'visual') => {
        set({ displayMode: mode });
      },

      setLanguage: (lang: string) => {
        set({ language: lang });
        void i18n.changeLanguage(lang);
      },
    }),
    {
      name: 'izipos-settings',
    },
  ),
);
