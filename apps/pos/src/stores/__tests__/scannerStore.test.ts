import { beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => {
  vi.resetModules();
  localStorage.clear();
});

describe('scanner keyboard layout settings', () => {
  it('defaults a fresh installation to automatic layout detection', async () => {
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState().keyboardLayout).toBe('auto');
  });

  it('migrates an existing installation left on the system layout to automatic', async () => {
    localStorage.setItem('izipos-scanner', JSON.stringify({
      state: {
        keyboardLayout: 'system',
        keystrokeThresholdMs: 75,
        minBarcodeLength: 4,
        autoAddToCart: false,
        soundOnScan: false,
      },
      version: 0,
    }));
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState()).toMatchObject({
      keyboardLayout: 'auto',
      keystrokeThresholdMs: 75,
      minBarcodeLength: 4,
      autoAddToCart: false,
      soundOnScan: false,
    });
  });

  it('migrates an existing installation with no persisted layout to automatic', async () => {
    localStorage.setItem('izipos-scanner', JSON.stringify({
      state: { keystrokeThresholdMs: 75, minBarcodeLength: 4, autoAddToCart: false, soundOnScan: false },
      version: 0,
    }));
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState()).toMatchObject({
      keyboardLayout: 'auto',
      keystrokeThresholdMs: 75,
      minBarcodeLength: 4,
    });
  });

  it('leaves an explicitly chosen US layout untouched across the migration', async () => {
    localStorage.setItem('izipos-scanner', JSON.stringify({
      state: { keyboardLayout: 'us', keystrokeThresholdMs: 50, minBarcodeLength: 3 },
      version: 0,
    }));
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState().keyboardLayout).toBe('us');
  });

  it('does not re-run the migration on an already migrated installation', async () => {
    localStorage.setItem('izipos-scanner', JSON.stringify({
      state: { keyboardLayout: 'system', keystrokeThresholdMs: 50, minBarcodeLength: 3 },
      version: 1,
    }));
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState().keyboardLayout).toBe('system');
  });

  it('remembers the explicitly selected scanner layout after reload', async () => {
    const { useScannerStore } = await import('../scannerStore');
    expect(useScannerStore.getState().keyboardLayout).toBe('auto');
    useScannerStore.getState().setKeyboardLayout('system');
    vi.resetModules();
    const reloaded = await import('../scannerStore');
    expect(reloaded.useScannerStore.getState().keyboardLayout).toBe('system');
  });
});
