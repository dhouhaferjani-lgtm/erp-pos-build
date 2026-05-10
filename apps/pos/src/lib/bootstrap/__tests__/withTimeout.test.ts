import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { withTimeout, BootstrapTimeoutError, BootstrapAbortedError } from '../withTimeout';

describe('withTimeout', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('resolves with the wrapped promise value when it settles before the timeout', async () => {
    const promise = withTimeout('authenticating', Promise.resolve('ok'), 1000);
    await expect(promise).resolves.toBe('ok');
  });

  it('rejects with BootstrapTimeoutError when the timeout fires first', async () => {
    const slow = new Promise<never>(() => {
      // never resolves — the timeout (or abort) is the only path that settles
      // the outer withTimeout promise.
    });

    const promise = withTimeout('fetching-terminal', slow, 5000);
    promise.catch(() => {}); // attach handler synchronously to avoid unhandled rejection

    vi.advanceTimersByTime(5001);
    await expect(promise).rejects.toBeInstanceOf(BootstrapTimeoutError);
    await expect(promise).rejects.toMatchObject({
      name: 'BootstrapTimeoutError',
      phase: 'fetching-terminal',
      timeoutMs: 5000,
    });
  });

  it('propagates the underlying rejection (not a timeout) when the wrapped promise rejects first', async () => {
    const inner = new Error('network down');
    inner.name = 'TypeError';
    const promise = withTimeout('authenticating', Promise.reject(inner), 5000);

    await expect(promise).rejects.toBe(inner);
  });

  it('cancels the timer when the wrapped promise resolves before the timeout', async () => {
    const promise = withTimeout('authenticating', Promise.resolve('done'), 5000);
    await expect(promise).resolves.toBe('done');

    // After resolution, advancing timers must NOT trigger any pending timeout
    // — the timer was cleared. Vitest's fake-timer queue should be empty.
    expect(vi.getTimerCount()).toBe(0);
  });

  it('rejects with BootstrapAbortedError when the abort signal fires (Codex r1 P2)', async () => {
    const controller = new AbortController();
    const slow = new Promise<never>(() => {
      // never resolves — the abort is the only path that settles the outer
      // withTimeout promise. Codex round-1 P2 fix: prior shape only cleared
      // the timer and left the outer promise pending, hanging any awaiter.
    });

    const promise = withTimeout('fetching-terminal', slow, 60_000, controller.signal);
    promise.catch(() => {}); // suppress unhandled rejection so vitest doesn't flag it

    controller.abort();

    await expect(promise).rejects.toBeInstanceOf(BootstrapAbortedError);
    await expect(promise).rejects.toMatchObject({
      name: 'AbortError',
      phase: 'fetching-terminal',
    });
    expect(vi.getTimerCount()).toBe(0);
  });

  it('rejects immediately when the signal is already aborted at call time (Codex r2 P2)', async () => {
    const controller = new AbortController();
    controller.abort(); // pre-abort

    const slow = new Promise<never>(() => {});
    const promise = withTimeout('authenticating', slow, 60_000, controller.signal);

    // Must reject without waiting either the wrapped promise or the timeout.
    await expect(promise).rejects.toBeInstanceOf(BootstrapAbortedError);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('drains the wrapped promise rejection when pre-aborted (no unhandled rejection — Codex r3 P2)', async () => {
    const controller = new AbortController();
    controller.abort();

    let rejectInner!: (e: Error) => void;
    const inner = new Promise<never>((_, reject) => {
      rejectInner = reject;
    });

    const unhandled: unknown[] = [];
    const onUnhandled = (e: PromiseRejectionEvent | { reason: unknown }): void => {
      unhandled.push('reason' in e ? e.reason : e);
    };
    process.on('unhandledRejection', onUnhandled);

    try {
      const promise = withTimeout('fetching-terminal', inner, 60_000, controller.signal);
      await expect(promise).rejects.toBeInstanceOf(BootstrapAbortedError);

      // Now the underlying work rejects AFTER the caller aborted. Without
      // the drain, this would land as an unhandled rejection.
      rejectInner(new Error('inner rejection after pre-abort'));

      // Yield so any unhandledRejection event has a chance to fire.
      await Promise.resolve();
      await Promise.resolve();

      expect(unhandled).toHaveLength(0);
    } finally {
      process.off('unhandledRejection', onUnhandled);
    }
  });

  it('only settles once when timer + wrapped resolve race (idempotent settlement)', async () => {
    // If the wrapped promise resolves and the timer also fires in the same
    // tick, the outer promise must settle exactly once with the wrapped
    // value — not double-resolve, not race-reject.
    const promise = withTimeout(
      'authenticating',
      Promise.resolve('first'),
      0,
    );
    await expect(promise).resolves.toBe('first');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('BootstrapTimeoutError carries phase + timeoutMs and a readable message', () => {
    const err = new BootstrapTimeoutError('checking-pins', 15_000);
    expect(err).toBeInstanceOf(Error);
    expect(err.name).toBe('BootstrapTimeoutError');
    expect(err.phase).toBe('checking-pins');
    expect(err.timeoutMs).toBe(15_000);
    expect(err.message).toContain('checking-pins');
    expect(err.message).toContain('15000');
  });
});
