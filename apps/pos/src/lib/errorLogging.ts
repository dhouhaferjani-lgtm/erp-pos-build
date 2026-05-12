/**
 * Structured error-log helper for the cashier critical path.
 *
 * Codex review 2026-05-08 findings (e) + (f) on T0.1:
 *
 * Never spread the raw thrown value into a console.error payload. A thrown
 * ApiRequestError, axios error, or Tauri IPC rejection can carry attached
 * fields like `.body`, `.response`, or `.data` whose content varies by
 * upstream (card-decline payloads, customer lookup results, query strings
 * that interpolated user data, etc.). Spreading the raw reference puts those
 * fields in devtools logs and Tauri crash reports — an unbounded PII surface.
 *
 * Always convert to this flat, bounded shape before logging. Also guards
 * `String(error)` against `.toString()` overrides that throw, which would
 * otherwise propagate past the catch block.
 */
export interface ErrorLogPayload {
  errorType: string;
  errorName: string | undefined;
  message: string;
  stack: string | undefined;
}

export function serializeErrorForLog(error: unknown): ErrorLogPayload {
  if (error instanceof Error) {
    let message: string;
    try {
      message = error.message;
    } catch {
      message = '[unreadable]';
    }
    let errorName: string;
    try {
      errorName = error.name;
    } catch {
      errorName = '[unreadable]';
    }
    let stack: string | undefined;
    try {
      stack = error.stack;
    } catch {
      stack = undefined;
    }
    return {
      errorType: typeof error,
      errorName,
      message,
      stack,
    };
  }
  let message: string;
  try {
    message = String(error).slice(0, 200);
  } catch {
    message = '[unstringifiable]';
  }
  return {
    errorType: typeof error,
    errorName: undefined,
    message,
    stack: undefined,
  };
}
