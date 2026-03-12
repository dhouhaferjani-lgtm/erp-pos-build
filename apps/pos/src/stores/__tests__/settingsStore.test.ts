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
      displayMode: 'grid',
      language: 'en',
    });
    vi.clearAllMocks();
  });

  it('has correct initial defaults', () => {
    const state = useSettingsStore.getState();
    expect(state.displayMode).toBe('grid');
    expect(state.language).toBe('en');
  });

  it('sets display mode to visual', () => {
    useSettingsStore.getState().setDisplayMode('visual');
    expect(useSettingsStore.getState().displayMode).toBe('visual');
  });

  it('sets display mode to grid', () => {
    useSettingsStore.setState({ displayMode: 'visual' });
    useSettingsStore.getState().setDisplayMode('grid');
    expect(useSettingsStore.getState().displayMode).toBe('grid');
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
});
