import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, act } from '@testing-library/react';

vi.mock('@/lib/i18n', () => ({
  default: { changeLanguage: vi.fn().mockResolvedValue(undefined) },
}));
vi.mock('zustand/middleware', () => ({
  persist: (config: (...a: unknown[]) => unknown) => config,
}));

import { ThemeProvider } from '../ThemeProvider';
import { syncThemeFromStore } from '@/lib/initTheme';
import { useSettingsStore } from '@/stores/settingsStore';

describe('ThemeProvider', () => {
  beforeEach(() => {
    useSettingsStore.setState({
      theme: 'light',
      accent: 'orange',
      corner: 'rounded',
      density: 'comfortable',
      cartPosition: 'start',
    });
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-accent');
  });

  it('applies the current settings to <html> on mount', () => {
    render(
      <ThemeProvider>
        <div>child</div>
      </ThemeProvider>,
    );
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.documentElement.dataset.accent).toBe('orange');
    expect(document.documentElement.dataset.corner).toBe('rounded');
    expect(document.documentElement.dataset.density).toBe('comfortable');
    expect(document.documentElement.dataset.cartSide).toBe('start');
  });

  it('reflows when a setting changes', () => {
    render(
      <ThemeProvider>
        <div>child</div>
      </ThemeProvider>,
    );
    act(() => {
      useSettingsStore.getState().setTheme('dark');
      useSettingsStore.getState().setAccent('teal');
    });
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(document.documentElement.dataset.accent).toBe('teal');
  });

  it('renders children', () => {
    const { getByText } = render(
      <ThemeProvider>
        <span>hello</span>
      </ThemeProvider>,
    );
    expect(getByText('hello')).toBeTruthy();
  });

  it('syncThemeFromStore applies persisted settings synchronously', () => {
    useSettingsStore.setState({ theme: 'dark', accent: 'blue' });
    syncThemeFromStore();
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(document.documentElement.dataset.accent).toBe('blue');
  });
});
