/**
 * refundConfirmation.ts
 *
 * Pure helper that maps an API error to a UI action for the refund-confirm flow.
 *
 * The server is expected to return HTTP 422 with a typed `error.code` when a
 * business rule blocks the refund. The mapping is:
 *
 *   MANAGER_OVERRIDE_REQUIRED  → 'manager-pin'
 *   DAILY_REFUND_CAP_EXCEEDED  → 'daily-cap'
 *   REFUND_WINDOW_CLOSED       → 'window-closed'
 *   BUSINESS_ERROR             → 'generic'  (fallback — see TODO below)
 *   (anything else)            → 'generic'
 *
 * TODO (follow-up — API exception handler session):
 *   Today, `apps/api/bootstrap/app.php` catches all `DomainException` subclasses
 *   with a single generic handler that returns `error.code = 'BUSINESS_ERROR'`.
 *   Until specific render closures are added for each named exception class
 *   (ManagerOverrideRequiredException, DailyRefundCapExceededException,
 *   RefundWindowClosedException, RefundDestinationNotAllowedException), the
 *   frontend will receive BUSINESS_ERROR for all of them and cannot distinguish
 *   which PIN prompt to show. The fallback to 'generic' is intentional.
 *   See: apps/api/app/Modules/POS/Domain/Exceptions/
 *
 * No imports from `@/lib/api` here — this is a pure decision function.
 * Callers supply the error shape; this file has zero side effects.
 */

// ─── Types ────────────────────────────────────────────────────────────────────

/** The possible UI actions the refund-confirm flow can take after an API error. */
export type RefundConfirmUiAction =
  | 'manager-pin'     // Show ManagerPinPanel inline; re-submit with authorized_by_user_id
  | 'daily-cap'       // Manager PIN needed for daily-cap override
  | 'window-closed'   // Manager PIN needed for window-override permission
  | 'generic';        // Show error toast; cannot determine specific action

/** Minimal shape of an API error as thrown by `ApiRequestError` in @/lib/api. */
export interface RefundApiError {
  /** HTTP status code, typically 422 for business rule violations. */
  status: number;
  /** The `error.code` field from the API response body. */
  code: string;
  /** The `error.message` field from the API response body. */
  apiMessage: string;
}

// ─── Known typed error codes ──────────────────────────────────────────────────

const MANAGER_OVERRIDE_REQUIRED = 'MANAGER_OVERRIDE_REQUIRED';
const DAILY_REFUND_CAP_EXCEEDED = 'DAILY_REFUND_CAP_EXCEEDED';
const REFUND_WINDOW_CLOSED = 'REFUND_WINDOW_CLOSED';

/**
 * Maps a raw API error to a UI action for the refund-confirm flow.
 *
 * Only 422 responses with a known typed code yield a specific action.
 * All other errors (non-422, unknown codes, BUSINESS_ERROR) fall through to 'generic'.
 *
 * @param error - The error thrown by apiPost / apiPut. Should be an `ApiRequestError`
 *                or any object with at least { status, code } fields.
 * @returns The UI action to take.
 */
export function mapRefundErrorToUiAction(error: RefundApiError): RefundConfirmUiAction {
  if (error.status !== 422) return 'generic';

  switch (error.code) {
    case MANAGER_OVERRIDE_REQUIRED:
      return 'manager-pin';
    case DAILY_REFUND_CAP_EXCEEDED:
      return 'daily-cap';
    case REFUND_WINDOW_CLOSED:
      return 'window-closed';
    default:
      // Covers BUSINESS_ERROR (generic DomainException) and unknown codes.
      return 'generic';
  }
}

/**
 * Returns true if the given UI action requires showing the ManagerPinPanel.
 * Convenience predicate to keep conditional rendering readable in the component.
 */
export function requiresManagerPin(action: RefundConfirmUiAction): boolean {
  return action === 'manager-pin' || action === 'daily-cap' || action === 'window-closed';
}
