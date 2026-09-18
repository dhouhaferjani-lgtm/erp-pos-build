import { beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => vi.resetModules());

describe('scanner keyboard layout settings', () => {
  it('preserves the system layout for an existing installation without a layout setting', async () => {
    localStorage.setItem('izipos-scanner', JSON.stringify({
      state: { keystrokeThresholdMs: 75, minBarcodeLength: 4, autoAddToCart: false, soundOnScan: false },
      version: 0,
    }));
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState()).toMatchObject({
      keyboardLayout: 'system', keystrokeThresholdMs: 75, minBarcodeLength: 4,
      autoAddToCart: false, soundOnScan: false,
    });
  });

  it('remembers the explicitly selected scanner layout after reload', async () => {
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState().keyboardLayout).toBe('system');
    useScannerStore.getState().setKeyboardLayout('us');
    vi.resetModules();
    const reloaded = await import('../scannerStore');
    expect(reloaded.useScannerStore.getState().keyboardLayout).toBe('us');
  });
});
