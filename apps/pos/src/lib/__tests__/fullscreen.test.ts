import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock the Tauri environment detection to claim we ARE in Tauri.
vi.mock('@/lib/printing', () => ({
  isTauriEnvironment: () => true,
}));

// Mock the dynamic imports used by applyFullscreen.
const mockSetDecorations = vi.fn();
const mockSetFullscreen = vi.fn();
const mockSetSkipTaskbar = vi.fn();
const mockSetAlwaysOnTop = vi.fn();
const mockSetSize = vi.fn();
const mockCenter = vi.fn();
const mockIsFullscreen = vi.fn();

vi.mock('@tauri-apps/api/window', () => ({
  getCurrentWindow: () => ({
    setDecorations: mockSetDecorations,
    setFullscreen: mockSetFullscreen,
    setSkipTaskbar: mockSetSkipTaskbar,
    setAlwaysOnTop: mockSetAlwaysOnTop,
    setSize: mockSetSize,
    center: mockCenter,
    isFullscreen: mockIsFullscreen,
  }),
}));

vi.mock('@tauri-apps/api/dpi', () => ({
  LogicalSize: class {
    constructor(public width: number, public height: number) {}
  },
}));

import { applyFullscreen, verifyFullscreenState } from '../fullscreen';

describe('applyFullscreen — per-API resilience', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    mockSetDecorations.mockReset().mockResolvedValue(undefined);
    mockSetFullscreen.mockReset().mockResolvedValue(undefined);
    mockSetSkipTaskbar.mockReset().mockResolvedValue(undefined);
    mockSetAlwaysOnTop.mockReset().mockResolvedValue(undefined);
    mockSetSize.mockReset().mockResolvedValue(undefined);
    mockCenter.mockReset().mockResolvedValue(undefined);
    mockIsFullscreen.mockReset().mockResolvedValue(true);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('invokes all four enter-fullscreen APIs when enabled', async () => {
    await applyFullscreen(true);
    expect(mockSetDecorations).toHaveBeenCalledWith(false);
    expect(mockSetFullscreen).toHaveBeenCalledWith(true);
    expect(mockSetSkipTaskbar).toHaveBeenCalledWith(true);
    expect(mockSetAlwaysOnTop).toHaveBeenCalledWith(true);
  });

  it('retries a failed API once after ~250ms without halting the chain', async () => {
    mockSetFullscreen.mockRejectedValueOnce(new Error('window not ready'));
    mockSetFullscreen.mockResolvedValueOnce(undefined);

    const promise = applyFullscreen(true);
    // Advance through the retry delay.
    await vi.advanceTimersByTimeAsync(300);
    await promise;

    expect(mockSetFullscreen).toHaveBeenCalledTimes(2);
    // The later APIs still got called even though setFullscreen failed first.
    expect(mockSetSkipTaskbar).toHaveBeenCalled();
    expect(mockSetAlwaysOnTop).toHaveBeenCalled();
  });

  it('logs and continues when an API fails both initial call and retry', async () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    mockSetSkipTaskbar.mockRejectedValue(new Error('persistent failure'));

    const promise = applyFullscreen(true);
    await vi.advanceTimersByTimeAsync(300);
    await promise;

    expect(mockSetSkipTaskbar).toHaveBeenCalledTimes(2);
    // The chain continues to setAlwaysOnTop.
    expect(mockSetAlwaysOnTop).toHaveBeenCalled();
    expect(err).toHaveBeenCalledWith(
      expect.stringContaining('[fullscreen]'),
      expect.anything(),
    );
    err.mockRestore();
  });

  it('verifyFullscreenState returns true when window matches desired state', async () => {
    mockIsFullscreen.mockResolvedValue(true);
    await expect(verifyFullscreenState(true)).resolves.toBe(true);
  });

  it('verifyFullscreenState returns false when window desynced', async () => {
    mockIsFullscreen.mockResolvedValue(false);
    await expect(verifyFullscreenState(true)).resolves.toBe(false);
  });

  it('applyFullscreen schedules a second-chance retry when readback mismatches', async () => {
    // First readback says false — retry needed. Second readback says true.
    mockIsFullscreen.mockResolvedValueOnce(false).mockResolvedValueOnce(true);

    const promise = applyFullscreen(true);
    await vi.advanceTimersByTimeAsync(600); // past the ~500ms post-apply retry
    await promise;

    // setFullscreen was called twice: the initial pass + the post-readback retry.
    expect(mockSetFullscreen).toHaveBeenCalledTimes(2);
  });
});
