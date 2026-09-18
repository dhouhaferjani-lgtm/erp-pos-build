import { cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';
import { useScannerStore } from '@/stores/scannerStore';

// A US keyboard-wedge scanner emitting 0012345678905 on a French Windows layout.
const frenchBarcodeKeys: KeyboardEventInit[] = [
  { key: 'à', code: 'Digit0' },
  { key: 'à', code: 'Digit0' },
  { key: '&', code: 'Digit1' },
  { key: 'é', code: 'Digit2' },
  { key: '"', code: 'Digit3' },
  { key: "'", code: 'Digit4' },
  { key: '(', code: 'Digit5' },
  { key: '-', code: 'Digit6' },
  { key: 'è', code: 'Digit7' },
  { key: '_', code: 'Digit8' },
  { key: 'ç', code: 'Digit9' },
  { key: 'à', code: 'Digit0' },
  { key: '(', code: 'Digit5' },
];

let now = 1000;

function press(target: HTMLElement | Window, key: KeyboardEventInit, intervalMs = 5): KeyboardEvent {
  now += intervalMs;
  const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...key });
  fireEvent(target, event);
  return event;
}

function scan(target: HTMLElement | Window, keys: KeyboardEventInit[], intervalMs = 5): KeyboardEvent {
  for (const key of keys) press(target, key, intervalMs);
  return press(target, { key: 'Enter', code: 'Enter' }, intervalMs);
}

function keysFor(text: string): KeyboardEventInit[] {
  return [...text].map((key) => ({ key }));
}

beforeEach(() => {
  now = 1000;
  vi.spyOn(performance, 'now').mockImplementation(() => now);
  useScannerStore.setState({
    keyboardLayout: 'system',
    keystrokeThresholdMs: 50,
    minBarcodeLength: 3,
    autoAddToCart: true,
    soundOnScan: false,
  });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('useBarcodeScanner', () => {
  it('given a dead key on the host layout, decodes its US scanner position', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));
    scan(window, [
      { key: 'Dead', code: 'BracketLeft' },
      { key: 'à', code: 'Digit0' },
      { key: '$', code: 'BracketRight' },
    ]);
    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['[0]']);
  });

  it('given unsupported physical codes, falls back for the whole token without mixing layouts', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));
    scan(window, [
      { key: 'à', code: 'Digit0' },
      { key: 'λ', code: 'Unidentified' },
      { key: 'é', code: 'Digit2' },
    ]);
    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['àλé']);
  });

  it('given focus changes during a burst, does not assign the scan to either input', () => {
    const onScan = vi.fn();
    render(<><input aria-label="First" /><input aria-label="Second" /></>);
    renderHook(() => useBarcodeScanner({ onScan }));
    press(screen.getByRole('textbox', { name: 'First' }), { key: '0' });
    scan(screen.getByRole('textbox', { name: 'Second' }), keysFor('01234'));
    expect(onScan).toHaveBeenCalledExactlyOnceWith('001234', null);
  });

  it('given detection is disabled mid-burst, does not reuse it after re-enabling', () => {
    const onScan = vi.fn();
    const { rerender } = renderHook(({ enabled }) => useBarcodeScanner({ onScan, enabled }), {
      initialProps: { enabled: true },
    });
    for (const key of keysFor('001234')) press(window, key);
    rerender({ enabled: false });
    rerender({ enabled: true });
    press(window, { key: 'Enter' });
    expect(onScan).not.toHaveBeenCalled();
  });

  it('given US scanner mode, delivers the complete French-layout scan once with leading zeros', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const terminator = scan(window, frenchBarcodeKeys);

    expect(onScan).toHaveBeenCalledTimes(1);
    expect(onScan.mock.calls[0]?.[0]).toBe('0012345678905');
    expect(terminator.defaultPrevented).toBe(true);
  });

  it('given system mode, preserves intentional accents and punctuation', () => {
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, frenchBarcodeKeys);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['àà&é"\'(-è_çà(']);
  });

  it('given US scanner mode and an already matching layout, preserves the barcode exactly', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: '0', code: 'Digit0' },
      { key: '0', code: 'Digit0' },
      { key: '1', code: 'Digit1' },
      { key: '2', code: 'Digit2' },
      { key: '3', code: 'Digit3' },
      { key: '4', code: 'Digit4' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['001234']);
  });

  it('given US scanner mode, decodes letters and shifted symbols without treating the barcode as a number', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: 'Q', code: 'KeyA', shiftKey: true },
      { key: 'A', code: 'KeyQ', shiftKey: true },
      { key: 'Z', code: 'KeyW', shiftKey: true },
      { key: 'W', code: 'KeyZ', shiftKey: true },
      { key: ')', code: 'Minus' },
      { key: 'à', code: 'Digit0' },
      { key: 'ç', code: 'Digit9' },
      { key: '!', code: 'Slash' },
      { key: '2', code: 'Digit2', shiftKey: true },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['AQWZ-09/@']);
  });

  it('given an unknown physical key code, preserves its received character', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: 'é', code: 'Unidentified' },
      { key: '?', code: '' },
      { key: 'λ', code: 'Unidentified' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['é?λ']);
  });

  it('given successive scans, delivers each complete barcode once without carrying characters over', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, frenchBarcodeKeys);
    scan(window, frenchBarcodeKeys);
    press(window, { key: 'Enter', code: 'Enter' });

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual([
      '0012345678905',
      '0012345678905',
    ]);
  });

  it('given a scan in the search field, identifies that field to the scan consumer', () => {
    const onScan = vi.fn();
    render(<input aria-label="Product search" />);
    const search = screen.getByRole('textbox', { name: 'Product search' });
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(search, keysFor('001234'));

    expect(onScan).toHaveBeenCalledExactlyOnceWith('001234', search);
  });

  it('given US scanner mode, leaves character events untouched until a completed scan', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    render(<input aria-label="Product search" />);
    const search = screen.getByRole('textbox', { name: 'Product search' });
    const onKeyDown = vi.fn();
    search.addEventListener('keydown', onKeyDown);
    renderHook(() => useBarcodeScanner({ onScan }));

    for (const key of frenchBarcodeKeys) {
      const event = press(search, key);
      expect(event.defaultPrevented).toBe(false);
      expect(event.key).toBe(key.key);
    }

    expect(onKeyDown).toHaveBeenCalledTimes(13);
    expect(onScan).not.toHaveBeenCalled();
  });

  it('given slow manual search, leaves Enter available and does not emit a scan', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const terminator = scan(window, keysFor('café-001'), 120);

    expect(onScan).not.toHaveBeenCalled();
    expect(terminator.defaultPrevented).toBe(false);
  });

  it('given disabled scanner detection, leaves rapid input and Enter available', () => {
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan, enabled: false }));

    const terminator = scan(window, frenchBarcodeKeys);

    expect(onScan).not.toHaveBeenCalled();
    expect(terminator.defaultPrevented).toBe(false);
  });

  it.each(['ctrlKey', 'altKey', 'metaKey'] as const)(
    'given %s keyboard shortcuts, does not mistake them for scanned data',
    (modifier) => {
      const onScan = vi.fn();
      renderHook(() => useBarcodeScanner({ onScan }));

      scan(window, keysFor('abc').map((key) => ({ ...key, [modifier]: true })));

      expect(onScan).not.toHaveBeenCalled();
    },
  );

  it('given a held key auto-repeating below the threshold, does not emit a phantom scan', () => {
    useScannerStore.setState({ keyboardLayout: 'us' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    press(window, { key: '0', code: 'Digit0' });
    for (let i = 0; i < 5; i++) {
      press(window, { key: '0', code: 'Digit0', repeat: true }, 5);
    }
    const terminator = press(window, { key: 'Enter', code: 'Enter' }, 5);

    expect(onScan).not.toHaveBeenCalled();
    expect(terminator.defaultPrevented).toBe(false);
  });

  it('given a repeated keydown inside a real burst, ignores only the repeat', () => {
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const keys = keysFor('001234');
    keys.splice(3, 0, { ...keys[2], repeat: true });
    scan(window, keys);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['001234']);
  });

  // React Doctor `no-ref-current-in-render`: the onScan callback is held in a
  // "latest ref" synced from an effect, never written during render. A burst
  // completed after a re-render must reach the CURRENT callback, not the one
  // captured when the listener was attached.
  it('given a new onScan after a re-render, delivers the burst to the latest callback', () => {
    const firstOnScan = vi.fn();
    const latestOnScan = vi.fn();
    const { rerender } = renderHook(({ onScan }) => useBarcodeScanner({ onScan }), {
      initialProps: { onScan: firstOnScan },
    });

    rerender({ onScan: latestOnScan });
    scan(window, keysFor('001234'));

    expect(firstOnScan).not.toHaveBeenCalled();
    expect(latestOnScan.mock.calls.map(([barcode]) => barcode)).toEqual(['001234']);
  });

  // --- 'auto' mode (the default): decode only when the raw token is provably
  // the wrong layout, so a cashier never has to touch Paramètres › Scanner.
  it('given automatic mode and a layout mismatch, delivers the decoded barcode', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const terminator = scan(window, frenchBarcodeKeys);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['0012345678905']);
    expect(terminator.defaultPrevented).toBe(true);
  });

  it('given automatic mode and a matching layout, delivers the received characters untouched', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: '0', code: 'Digit0' },
      { key: '0', code: 'Digit0' },
      { key: '1', code: 'Digit1' },
      { key: '2', code: 'Digit2' },
      { key: '3', code: 'Digit3' },
      { key: '4', code: 'Digit4' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['001234']);
  });

  it('given automatic mode and a barcode with a legitimate symbol on a matching layout, leaves it unchanged', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: 'A', code: 'KeyA', shiftKey: true },
      { key: 'B', code: 'KeyB', shiftKey: true },
      { key: '-', code: 'Minus' },
      { key: '1', code: 'Digit1' },
      { key: '2', code: 'Digit2' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['AB-12']);
  });

  it('given automatic mode and manual typing at human speed, does not intercept the Enter', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const terminator = scan(window, frenchBarcodeKeys, 120);

    expect(onScan).not.toHaveBeenCalled();
    expect(terminator.defaultPrevented).toBe(false);
  });

  it('given automatic mode and an unmapped physical code, keeps the received characters', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: 'à', code: 'Digit0' },
      { key: 'λ', code: 'Unidentified' },
      { key: 'é', code: 'Digit2' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['àλé']);
  });

  it('given automatic mode and a plausible raw token, prefers the received text over a decoded alternative', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    // AZERTY host, US scanner sending "qbc": raw and decoded differ, but every raw
    // character is barcode-plausible, so automatic mode must not guess.
    scan(window, [
      { key: 'a', code: 'KeyQ' },
      { key: 'b', code: 'KeyB' },
      { key: 'c', code: 'KeyC' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['abc']);
  });

  it('given automatic mode and a dead key on the host layout, decodes its US scanner position', () => {
    useScannerStore.setState({ keyboardLayout: 'auto' });
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    scan(window, [
      { key: 'Dead', code: 'Digit2' },
      { key: 'à', code: 'Digit0' },
      { key: 'ç', code: 'Digit9' },
      { key: '&', code: 'Digit1' },
    ]);

    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['2091']);
  });

  it('given a too-short fragment, clears it before the next complete scan', () => {
    const onScan = vi.fn();
    renderHook(() => useBarcodeScanner({ onScan }));

    const fragmentTerminator = scan(window, keysFor('00'));
    scan(window, keysFor('001234'));

    expect(fragmentTerminator.defaultPrevented).toBe(false);
    expect(onScan.mock.calls.map(([barcode]) => barcode)).toEqual(['001234']);
  });
});
