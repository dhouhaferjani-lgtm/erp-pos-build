/**
 * T0.3 regression coverage for the AbortController-based read-timeout wrapper.
 *
 * Pre-T0.3, every fetch in apps/pos went through @tauri-apps/plugin-http
 * with `connectTimeout` only. A request whose body sent + committed
 * server-side but whose response was dropped would hang the JS-side fetch
 * indefinitely (Opus sync audit Finding 1 + Codex sync audit). Combined
 * with T0.2's stable idempotency key, T0.3 makes the timeout-then-retry
 * path safe.
 *
 * Tests use vi.useFakeTimers so the 30s/10s/5s timeouts execute
 * deterministically without slowing the suite. Each test confirms the
 * RED-on-old-code property by virtue of the FetchTimeoutError class not
 * existing on dev tip 5ac844cd — every assertion against
 * `instanceof FetchTimeoutError` fails on a tree that doesn't import
 * the helper.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

import { fetch as tauriFetch } from '@tauri-apps/plugin-http';
import { fetchWithTimeout, FetchTimeoutError } from '@/lib/fetchWithTimeout';

describe('fetchWithTimeout', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('throws FetchTimeoutError when the underlying fetch hangs past the timeout', async () => {
    // Mock a fetch that never resolves on its own. The Rust-side bypass case
    // (Tauri ignores AbortSignal) is what this guards against — the JS-side
    // Promise.race against the explicit timeout-rejection unblocks the caller
    // even when fetch hangs forever.
    vi.mocked(tauriFetch).mockImplementation(
      () =>
        new Promise(() => {
          // Never resolves.
        }),
    );

    const promise = fetchWithTimeout(
      'https://example.test/pos/receipts/sync',
      { method: 'POST', body: '{}' },
      30_000,
    );
    promise.catch(() => undefined); // suppress unhandled-rejection log during fake-timer wait

    await vi.advanceTimersByTimeAsync(30_000);

    await expect(promise).rejects.toBeInstanceOf(FetchTimeoutError);
    await expect(promise).rejects.toMatchObject({
      url: 'https://example.test/pos/receipts/sync',
      timeoutMs: 30_000,
      method: 'POST',
      name: 'FetchTimeoutError',
    });
  });

  it('clears the timer on success so it does not leak (no timer count after fast resolution)', async () => {
    const mockResponse = new Response('{"data":"ok"}', { status: 200 });
    vi.mocked(tauriFetch).mockResolvedValueOnce(mockResponse);

    const result = await fetchWithTimeout(
      'https://example.test/health',
      { method: 'GET' },
      5_000,
    );

    expect(result).toBe(mockResponse);
    // If the timer hadn't been cleared, vitest's fake timer would still be
    // pending. After a successful resolution there must be no scheduled
    // timer left from this call.
    expect(vi.getTimerCount()).toBe(0);
  });

  it('clears the timer on non-timeout error (DNS/connect failure rethrows the original)', async () => {
    const networkError = new Error('connect EHOSTUNREACH');
    networkError.name = 'NetworkError';
    vi.mocked(tauriFetch).mockRejectedValueOnce(networkError);

    await expect(
      fetchWithTimeout('https://unreachable.test/x', { method: 'GET' }, 10_000),
    ).rejects.toBe(networkError);

    // Original error class preserved (not translated to FetchTimeoutError).
    // No leaked timer.
    expect(vi.getTimerCount()).toBe(0);
  });

  it('translates a signal-honored AbortError to FetchTimeoutError', async () => {
    // When Tauri's plugin-http honors AbortSignal, the inner fetch promise
    // rejects with `name === 'AbortError'`. The wrapper must translate that
    // to the typed FetchTimeoutError so callers see a uniform class
    // regardless of whether the abort fired Tauri-side or via Promise.race.
    vi.mocked(tauriFetch).mockImplementation(
      (_input, init) =>
        new Promise((_resolve, reject) => {
          const signal = (init as RequestInit | undefined)?.signal;
          if (signal) {
            signal.addEventListener('abort', () => {
              const err = new Error('The operation was aborted.');
              err.name = 'AbortError';
              reject(err);
            });
          }
        }),
    );

    const promise = fetchWithTimeout(
      'https://example.test/pos/products',
      { method: 'GET' },
      10_000,
    );
    promise.catch(() => undefined);

    await vi.advanceTimersByTimeAsync(10_000);

    await expect(promise).rejects.toBeInstanceOf(FetchTimeoutError);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('does not fire the timeout if fetch resolves within the window', async () => {
    const mockResponse = new Response('{"data":"ok"}', { status: 200 });
    vi.mocked(tauriFetch).mockResolvedValueOnce(mockResponse);

    const promise = fetchWithTimeout(
      'https://example.test/x',
      { method: 'GET' },
      30_000,
    );

    // Advance only 100ms — well under the 30s timeout — and resolve.
    await vi.advanceTimersByTimeAsync(100);
    const result = await promise;

    expect(result).toBe(mockResponse);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('passes AbortSignal to the underlying fetch so Tauri can abort the Rust-side request', async () => {
    let capturedSignal: AbortSignal | null = null;
    vi.mocked(tauriFetch).mockImplementation((_input, init) => {
      capturedSignal = (init as RequestInit | undefined)?.signal ?? null;
      return Promise.resolve(new Response('{}', { status: 200 }));
    });

    await fetchWithTimeout('https://example.test/x', { method: 'GET' }, 5_000);

    // Defense-in-depth: even if Rust ignores the signal, we passed it. The
    // Promise.race in the wrapper covers the Rust-bypass case.
    expect(capturedSignal).toBeInstanceOf(AbortSignal);
  });
});

describe('FetchTimeoutError', () => {
  it('keeps the .message opaque so the cashier-visible banner does not leak the URL', async () => {
    // T0.3 round-1 Codex fix: paymentStore.formatCheckoutError returns
    // `error.message` verbatim for any non-empty Error. The URL must NOT
    // appear in the message — query params / customer-id path segments
    // would otherwise reach the cashier banner. The diagnostic detail
    // lives on typed instance fields (`url`, `timeoutMs`, `method`).
    const err = new FetchTimeoutError('https://x.test/customers/abc-123?token=secret', 30_000, 'POST');

    expect(err.message).toBe('Request timed out');
    expect(err.message).not.toContain('customers');
    expect(err.message).not.toContain('token');
    expect(err.message).not.toContain('30000');
    expect(err.message).not.toContain('POST');

    // Typed fields still carry the full diagnostic detail for explicit
    // devtools logging by callers (e.g. syncService's structured catch).
    expect(err.url).toBe('https://x.test/customers/abc-123?token=secret');
    expect(err.timeoutMs).toBe(30_000);
    expect(err.method).toBe('POST');
    expect(err.name).toBe('FetchTimeoutError');
  });

  it('serializes through serializeErrorForLog with the opaque message + typed name', async () => {
    const { serializeErrorForLog } = await import('@/lib/errorLogging');
    const err = new FetchTimeoutError('https://x.test/y', 30_000, 'POST');

    const serialized = serializeErrorForLog(err);

    // serializeErrorForLog emits .name + .message + .stack. The opaque
    // message keeps the structured-log payload safe for crash reports etc.
    // Callers that need URL/method/timeoutMs in their log should spread
    // the typed fields explicitly (see syncService timeout branch).
    expect(serialized.errorType).toBe('object');
    expect(serialized.errorName).toBe('FetchTimeoutError');
    expect(serialized.message).toBe('Request timed out');
    expect(typeof serialized.stack).toBe('string');
  });
});
