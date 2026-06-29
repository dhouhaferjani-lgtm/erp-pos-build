import { describe, it, expect, beforeEach } from 'vitest';
import {
  applyTheme,
  ACCENTS,
  THEME_MODES,
  CORNER_STYLES,
  DENSITIES,
  type ThemeSettings,
} from '../theme';

const baseSettings: ThemeSettings = {
  theme: 'light',
  accent: 'orange',
  corner: 'rounded',
  density: 'comfortable',
  cartPosition: 'start',
};

describe('applyTheme', () => {
  let root: HTMLElement;

  beforeEach(() => {
    root = document.createElement('html');
  });

  it('writes every knob as a data-* attribute on the root element', () => {
    applyTheme(root, baseSettings);
    expect(root.dataset.theme).toBe('light');
    expect(root.dataset.accent).toBe('orange');
    expect(root.dataset.corner).toBe('rounded');
    expect(root.dataset.density).toBe('comfortable');
    expect(root.dataset.cartSide).toBe('start');
  });

  it('reflects dark + alternate accent/corner/density', () => {
    applyTheme(root, {
      theme: 'dark',
      accent: 'teal',
      corner: 'sharp',
      density: 'dense',
      cartPosition: 'end',
    });
    expect(root.dataset.theme).toBe('dark');
    expect(root.dataset.accent).toBe('teal');
    expect(root.dataset.corner).toBe('sharp');
    expect(root.dataset.density).toBe('dense');
    expect(root.dataset.cartSide).toBe('end');
  });

  it('is idempotent — re-applying overwrites prior values', () => {
    applyTheme(root, { ...baseSettings, theme: 'dark', accent: 'blue' });
    applyTheme(root, baseSettings);
    expect(root.dataset.theme).toBe('light');
    expect(root.dataset.accent).toBe('orange');
  });

  it('exposes the canonical option lists used by the settings UI', () => {
    expect(THEME_MODES).toEqual(['light', 'dark']);
    expect(ACCENTS).toEqual(['orange', 'green', 'blue', 'teal']);
    expect(CORNER_STYLES).toEqual(['rounded', 'sharp']);
    expect(DENSITIES).toEqual(['comfortable', 'dense']);
  });
});
