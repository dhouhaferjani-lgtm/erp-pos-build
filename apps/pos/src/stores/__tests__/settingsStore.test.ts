import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@/lib/i18n', () => ({
  default: {
    changeLanguage: vi.fn().mockResolvedValue(undefined),
  },
}));

// Mock zustand persist to be a passthrough (no actual persistence)
vi.mock('zustand/middleware', () => ({
  persist: (config: Function) => config,
}));

import { useSettingsStore, SUPPORTED_LANGUAGES } from '../settingsStore';
import i18n from '@/lib/i18n';

describe('settingsStore', () => {
  beforeEach(() => {
    useSettingsStore.setState({
      displayMode: 'liste',
      language: 'en',
      cartPosition: 'start',
    });
    vi.clearAllMocks();
  });

  it('has correct initial defaults', () => {
    const state = useSettingsStore.getState();
    expect(state.displayMode).toBe('liste');
    expect(state.language).toBe('en');
  });

  it('sets display mode to vitrine', () => {
    useSettingsStore.getState().setDisplayMode('vitrine');
    expect(useSettingsStore.getState().displayMode).toBe('vitrine');
  });

  it('sets display mode to tableau', () => {
    useSettingsStore.setState({ displayMode: 'vitrine' });
    useSettingsStore.getState().setDisplayMode('tableau');
    expect(useSettingsStore.getState().displayMode).toBe('tableau');
  });

  it('sets language and calls i18n.changeLanguage', () => {
    useSettingsStore.getState().setLanguage('fr');

    expect(useSettingsStore.getState().language).toBe('fr');
    expect(i18n.changeLanguage).toHaveBeenCalledWith('fr');
  });

  it('SUPPORTED_LANGUAGES includes en and fr', () => {
    const codes = SUPPORTED_LANGUAGES.map((l) => l.code);
    expect(codes).toContain('en');
    expect(codes).toContain('fr');
  });

  it('defaults cartPosition to start', () => {
    expect(useSettingsStore.getState().cartPosition).toBe('start');
  });

  it('allows setting cartPosition to end', () => {
    useSettingsStore.getState().setCartPosition('end');
    expect(useSettingsStore.getState().cartPosition).toBe('end');
  });

  describe('confirmLineDelete (cart mis-tap guard)', () => {
    it('defaults to true (confirmation on)', () => {
      expect(useSettingsStore.getState().confirmLineDelete).toBe(true);
    });

    it('allows turning the confirmation off', () => {
      useSettingsStore.getState().setConfirmLineDelete(false);
      expect(useSettingsStore.getState().confirmLineDelete).toBe(false);
    });
  });

  describe('optional display fields (Task 16 — default-off field toggles)', () => {
    it('defaults showSkuOnRows and showSkinTypeOnTiles to false', () => {
      const s = useSettingsStore.getState();
      expect(s.showSkuOnRows).toBe(false);
      expect(s.showSkinTypeOnTiles).toBe(false);
    });

    it('setShowSkuOnRows flips the flag', () => {
      useSettingsStore.getState().setShowSkuOnRows(true);
      expect(useSettingsStore.getState().showSkuOnRows).toBe(true);
    });

    it('setShowSkinTypeOnTiles flips the flag', () => {
      useSettingsStore.getState().setShowSkinTypeOnTiles(true);
      expect(useSettingsStore.getState().showSkinTypeOnTiles).toBe(true);
    });
  });

  describe('appearance (theme knobs)', () => {
    it('defaults to light / orange / rounded / comfortable', () => {
      const s = useSettingsStore.getState();
      expect(s.theme).toBe('light');
      expect(s.accent).toBe('orange');
      expect(s.corner).toBe('rounded');
      expect(s.density).toBe('comfortable');
    });

    it('sets theme, accent, corner and density', () => {
      const s = useSettingsStore.getState();
      s.setTheme('dark');
      s.setAccent('teal');
      s.setCorner('sharp');
      s.setDensity('dense');
      const next = useSettingsStore.getState();
      expect(next.theme).toBe('dark');
      expect(next.accent).toBe('teal');
      expect(next.corner).toBe('sharp');
      expect(next.density).toBe('dense');
    });
  });
});
