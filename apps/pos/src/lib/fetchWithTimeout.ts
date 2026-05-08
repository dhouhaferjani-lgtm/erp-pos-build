/**
 * AbortController-based read-timeout wrapper around Tauri's plugin-http fetch.
 *
 * T0.3 (Opus + Codex sync audits 2026-04-30, Finding 1): Tauri's plugin-http
 * accepts a `connectTimeout` (limits the TCP-connect phase) but no overall /
 * read timeout. A request whose body sent + committed server-side but whose
 * response was dropped on the wire would hang the JS-side fetch forever. The
 * cashier's UI would never resolve, the receipt would sit in `status='syncing'`
 * locally while server-side it had already finalized, and the eventual retry
 * (with T0.2's now-stable idempotency key) would dedup correctly — but only
 * if the JS side ever gives up on the original request.
 *
 * This wrapper races the inner fetch against a JS-side timeout that does TWO
 * things on fire:
 *   1. Calls AbortController.abort() — Tauri plugin-http v2.5.7 honors
 *      AbortSignal in the standard `RequestInit` shape, so the Rust-side
 *      request is told to terminate.
 *   2. Rejects the race promise with a typed `FetchTimeoutError` —
 *      defends against the case where Tauri ignores the signal and the
 *      inner fetch never rejects. The leaked Rust-side request completes
 *      silently and its response is discarded.
 *
 * On signal-honored fetches the inner promise rejects with a DOMException
 * shaped as `name === 'AbortError'`. The catch translates that to
 * `FetchTimeoutError` so callers see a uniform error class regardless of
 * which side won the race.
 *
 * Caller contract:
 *   - Pass `timeoutMs` explicitly. The codebase's three timeout classes:
 *       30_000  for POST /pos/receipts/sync (the receipt sync path —
 *               server-side fiscal hash + voucher resolution can be slow).
 *       10_000  default for read-only GETs and other writes.
 *        5_000  health/connectivity probe (kept short so a stuck probe
 *               doesn't block UI degradation to "offline" mode).
 *   - On timeout: `FetchTimeoutError` is thrown. Per the orchestrator
 *     T0.3 brief, callers should treat timeout as "unknown sync state" and
 *     NOT mark receipts as failed locally — the next sync tick reconciles
 *     via T0.2's idempotency lookup (this is the T0.4 chain-break-recovery
 *     downstream contract).
 *   - On non-timeout error: original error rethrown verbatim (no translation).
 *   - On success: response returned; no leaked timer.
 */

import { fetch as tauriFetch } from '@tauri-apps/plugin-http';

export class FetchTimeoutError extends Error {
  constructor(
    public readonly url: string,
    public readonly timeoutMs: number,
    public readonly method: string,
  ) {
    // T0.3 round-1 Codex fix: keep the message OPAQUE. The URL can carry
    // customer-id path segments / query params and must not flow into the
    // cashier-visible banner via paymentStore's formatCheckoutError, which
    // returns `error.message` verbatim for any non-empty Error (T0.1 opacity
    // contract). Diagnostic detail (url, timeoutMs, method) is still
    // available on the typed instance fields — callers that log to devtools
    // (e.g. syncService's catch) read them explicitly.
    super('Request timed out');
    this.name = 'FetchTimeoutError';
  }
}

type TauriRequestInit = RequestInit & { connectTimeout?: number };

function urlOf(input: URL | Request | string): string {
  if (typeof input === 'string') return input;
  if (input instanceof URL) return input.href;
  return input.url;
}

export async function fetchWithTimeout(
  input: URL | Request | string,
  init: TauriRequestInit | undefined,
  timeoutMs: number,
): Promise<Response> {
  const controller = new AbortController();
  const method = init?.method ?? 'GET';
  const url = urlOf(input);
  const userSignal = init?.signal;

  // T1.1 Step 1.5: forward the caller's AbortSignal (e.g. LoginPage's
  // Cancel-after-8s button) into our internal controller so a user-
  // initiated abort terminates the underlying Tauri fetch the same way
  // a timeout abort does. We track WHICH path fired so the catch can
  // distinguish:
  //   - timer fired      → throw FetchTimeoutError (T0.3 contract)
  //   - userSignal fired → propagate the user's AbortError verbatim
  // If neither fires (Rust-side TCP RST etc.), the underlying error
  // surfaces unchanged.
  let timedOut = false;
  let userAborted = false;

  const onUserAbort = () => {
    userAborted = true;
    controller.abort();
  };

  if (userSignal) {
    if (userSignal.aborted) {
      // Synchronously honor a pre-fired caller signal.
      onUserAbort();
    } else {
      userSignal.addEventListener('abort', onUserAbort, { once: true });
    }
  }

  // Single timer drives both legs of the defense:
  //   (a) controller.abort() — signals Tauri's plugin-http to terminate
  //       the Rust-side request (honored in v2.5.7).
  //   (b) rejectTimeout() — explicit Promise.race rejection so the JS
  //       caller is unblocked even if Rust ignores the signal.
  let rejectTimeout!: (err: Error) => void;
  const timeoutPromise = new Promise<never>((_resolve, reject) => {
    rejectTimeout = reject;
  });

  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
    rejectTimeout(new FetchTimeoutError(url, timeoutMs, method));
  }, timeoutMs);

  try {
    return await Promise.race([
      tauriFetch(input, { ...init, signal: controller.signal }),
      timeoutPromise,
    ]);
  } catch (err) {
    // If Tauri's plugin-http honored AbortSignal and rejected with
    // `AbortError` before our explicit rejection fired the race, translate
    // to the typed FetchTimeoutError ONLY if the timeout fired. A user-
    // initiated abort propagates the AbortError unchanged so the caller
    // can distinguish "user pressed Cancel" from "request timed out".
    if (timedOut && err instanceof Error && err.name === 'AbortError') {
      throw new FetchTimeoutError(url, timeoutMs, method);
    }
    if (userAborted) {
      // Re-throw the AbortError as-is so caller's catch sees it.
      throw err;
    }
    throw err;
  } finally {
    // Always clear the timer to prevent a stale 30s reference from
    // outliving a 100ms successful fetch, and unhook the user-signal
    // listener so it doesn't leak past the request.
    clearTimeout(timer);
    if (userSignal) {
      userSignal.removeEventListener('abort', onUserAbort);
    }
  }
}
