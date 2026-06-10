/**
 * Refund checkout orchestration (Task 2b — the user-facing half of Task 53).
 *
 * State machine driving the all-return-cart Pay flow:
 *
 *   idle ── begin() ──▶ preparing ──▶ destination ──▶ confirm ──▶ approval
 *                          │ (typed error → idle + error)            │
 *                          ▼                                         ▼
 *                        idle ◀───────────── cancel() ────────  submitting
 *                                                                    │
 *                                                  success ──▶    settled
 *
 *   - `begin` runs `prepareRefundSettlement` (server-receipt resolution +
 *     line mapping) BEFORE any UI step: the approval evidence binds the
 *     mapped server line_ids, so nothing can be confirmed until mapping
 *     succeeded. Any prepare failure returns to `idle` with a typed,
 *     translatable error key. `begin` also SNAPSHOTS the return lines: the
 *     UI displays totals from the snapshot (not the live cart), and
 *     `approveAndSubmit` fail-closes if the cart's return-line set drifted
 *     from the snapshot (a scan/edit while the modal sequence was up).
 *   - `approveAndSubmit` runs the manager-PIN approval in TWO phases
 *     (shared `refundApproval` helpers): `authorRefundReturnApproval`
 *     (PIN + signed-chain authoring — at most ONCE per settlement; the
 *     evidence is cached IMMEDIATELY) then `syncRefundApprovalEvents`
 *     (re-runnable until the server confirms). A sync failure therefore
 *     never discards authored evidence: the retry re-runs sync + submit
 *     only — no second PIN entry, no duplicate approval+override pair in
 *     the signed chain. The `refund_request_id` idempotency key is likewise
 *     generated once and reused across submit retries (server replays the
 *     original return), and reset on cancel / fresh begin.
 *   - Serialization: `begin` is a no-op unless idle; `approveAndSubmit` is a
 *     no-op unless on the approval step — a second Pay press or a double-tap
 *     on Authorize cannot start a concurrent settlement.
 *   - Epoch guard: `reset` / `cancel` / `begin` bump an epoch counter; async
 *     continuations re-check it after every await and die silently if the
 *     flow was torn down mid-flight (shift close / operator switch), so a
 *     stale continuation can never resurrect a reset store or fire success
 *     side effects for an abandoned settlement.
 *   - On success the store clears the cart's return lines and hands the FULL
 *     `ReturnSettlementResponse` to the `onSettled` seam — Phase 3 wires
 *     AVOIR printing there; today HomePage records it and shows the success
 *     toast. The response also stays in `settledResponse` until the next
 *     begin/reset.
 *
 * NO user-facing strings here — errors are i18n keys the UI translates.
 */
import { create } from 'zustand';
import type Database from '@tauri-apps/plugin-sql';
import type { CartItem } from '@/types/cart';
import {
  generateRefundRequestId,
  prepareRefundSettlement,
  submitRefundReturn,
  type PickerRefundDestination,
  type PreparedRefundSettlement,
  type RefundApprovalEvidence,
  type RefundReturnReason,
  type RefundSettlementError,
  type RetryOptions,
  type ReturnSettlementResponse,
} from '@/lib/refundFlow/refundSettlementService';
import {
  authorRefundReturnApproval,
  syncRefundApprovalEvents,
} from '@/lib/refundFlow/refundApproval';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';
import { useCartStore } from '@/stores/cartStore';

// ─── Steps / errors ──────────────────────────────────────────────────────────

export type RefundCheckoutStep =
  | 'idle'
  | 'preparing'
  | 'destination'
  | 'confirm'
  | 'approval'
  | 'submitting'
  | 'settled';

/** i18n keys (namespace `pos`) for every failure the flow can surface. */
export type RefundCheckoutErrorKey =
  | 'refundFlow.checkout.errorNotSynced'
  | 'refundFlow.checkout.errorLineMapping'
  | 'refundFlow.checkout.errorOffline'
  | 'refundFlow.checkout.errorServer'
  | 'refundFlow.checkout.errorValidation'
  | 'refundFlow.checkout.errorApproval'
  | 'refundFlow.checkout.errorApprovalSync'
  | 'refundFlow.checkout.errorCartChanged'
  | 'refundFlow.checkout.errorInternal';

export interface RefundCheckoutError {
  key: RefundCheckoutErrorKey;
  /** Raw server message — populated for VALIDATION_FAILED only (the UI wraps it). */
  serverMessage: string | null;
}

function settlementErrorToUi(error: RefundSettlementError): RefundCheckoutError {
  switch (error.code) {
    case 'NOT_SYNCED':
      return { key: 'refundFlow.checkout.errorNotSynced', serverMessage: null };
    case 'LINE_MAPPING_FAILED':
      return { key: 'refundFlow.checkout.errorLineMapping', serverMessage: null };
    case 'OFFLINE':
      return { key: 'refundFlow.checkout.errorOffline', serverMessage: null };
    case 'SERVER_ERROR':
      return { key: 'refundFlow.checkout.errorServer', serverMessage: null };
    case 'VALIDATION_FAILED':
      return { key: 'refundFlow.checkout.errorValidation', serverMessage: error.message };
  }
}

/**
 * Canonical fingerprint of a return-line set (ids + quantities, order-blind).
 * Used to fail-close `approveAndSubmit` when the cart drifted from the
 * lines snapshotted (and mapped/prepared) at `begin()`.
 */
function returnLinesFingerprint(items: readonly CartItem[]): string {
  return items
    .map((item) => `${item.id}:${item.quantity}`)
    .sort()
    .join('|');
}

// ─── Inputs ──────────────────────────────────────────────────────────────────

export interface BeginRefundCheckoutInput {
  db: Database;
  /** Raw scanned QR token (null for resumed drafts / offline-issued receipts). */
  receiptToken: string | null;
  receiptNumber: string;
  /** The cart's `kind: 'return'` items AS-IS (edited quantities pass through). */
  refundItems: CartItem[];
  retry?: RetryOptions;
}

export interface ApproveAndSubmitInput {
  approvalContext: PosOverrideContext;
  managerPin: string;
  /** Cashier-entered reason; also sent as notes/override_reason when non-empty. */
  reason: string;
  terminalId: string;
  returnReason?: RefundReturnReason;
  retry?: RetryOptions;
  /**
   * Settled seam — receives the FULL /return response (incl. qr_token and
   * issued_voucher). Phase 3 wires AVOIR printing here.
   */
  onSettled?: (response: ReturnSettlementResponse) => void;
}

// ─── Store ───────────────────────────────────────────────────────────────────

interface RefundCheckoutState {
  step: RefundCheckoutStep;
  error: RefundCheckoutError | null;
  prepared: PreparedRefundSettlement | null;
  receiptNumber: string | null;
  destination: PickerRefundDestination | null;
  /**
   * Return lines as they were when begin() prepared the settlement. The UI
   * renders refund totals from THIS (frozen at begin), and approveAndSubmit
   * fail-closes when the live cart's return-line set no longer matches.
   */
  refundItemsSnapshot: CartItem[] | null;
  /** Idempotency key — ONE per settlement attempt, reused across retries. */
  refundRequestId: string | null;
  /** Cached approval evidence — authored once, reused across submit retries. */
  approval: RefundApprovalEvidence | null;
  /** True once the authored approval events were confirmed synced server-side. */
  approvalSynced: boolean;
  /** Last settled response (Phase-3 print seam reads this until the next begin). */
  settledResponse: ReturnSettlementResponse | null;
  /**
   * Teardown counter (epoch guard). Bumped by reset/cancel/begin; async
   * continuations captured under an older epoch bail instead of mutating
   * state that no longer belongs to them.
   */
  epoch: number;
}

interface RefundCheckoutActions {
  /**
   * Pay pressed on an all-return cart. No-op unless idle (a second Pay press
   * while a checkout is active does nothing). Runs prepare; on success the
   * flow advances to the destination step.
   */
  begin: (input: BeginRefundCheckoutInput) => Promise<void>;
  /** Destination chosen in the picker → confirm step. */
  selectDestination: (destination: PickerRefundDestination) => void;
  /** Refund summary accepted in RefundConfirmModal → approval (PIN) step. */
  confirmAccepted: () => void;
  /**
   * Authorize (manager PIN) + submit. No-op unless on the approval step —
   * the UI disables the button while submitting, and this guard serializes
   * even a double-dispatch. On failure the flow returns to the approval step
   * with a typed error (cached approval/refundRequestId survive for retry).
   */
  approveAndSubmit: (input: ApproveAndSubmitInput) => Promise<void>;
  /** Abort from any interactive step back to the untouched cart. */
  cancel: () => void;
  /** Dismiss the idle-state error banner. */
  clearError: () => void;
  /** HomePage acknowledged the settled state (toast shown, draft cleaned). */
  acknowledgeSettled: () => void;
  /** Full reset (shift close / operator switch). */
  reset: () => void;
}

export type RefundCheckoutStore = RefundCheckoutState & RefundCheckoutActions;

const initialState: RefundCheckoutState = {
  step: 'idle',
  error: null,
  prepared: null,
  receiptNumber: null,
  destination: null,
  refundItemsSnapshot: null,
  refundRequestId: null,
  approval: null,
  approvalSynced: false,
  settledResponse: null,
  epoch: 0,
};

export const useRefundCheckoutStore = create<RefundCheckoutStore>()((set, get) => ({
  ...initialState,

  begin: async (input) => {
    if (get().step !== 'idle') return;

    const epoch = get().epoch + 1;
    set({
      ...initialState,
      epoch,
      step: 'preparing',
      receiptNumber: input.receiptNumber,
      // Freeze the return lines: display totals + the stale-cart guard both
      // read this snapshot, not the live cart.
      refundItemsSnapshot: input.refundItems.map((item) => ({ ...item })),
    });

    const result = await prepareRefundSettlement({
      db: input.db,
      receiptToken: input.receiptToken,
      receiptNumber: input.receiptNumber,
      refundItems: input.refundItems,
      ...(input.retry !== undefined ? { retry: input.retry } : {}),
    });
    if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

    if (!result.ok) {
      set({
        step: 'idle',
        error: settlementErrorToUi(result.error),
        prepared: null,
        refundItemsSnapshot: null,
      });
      return;
    }

    set({ step: 'destination', prepared: result.value, error: null });
  },

  selectDestination: (destination) => {
    if (get().step !== 'destination') return;
    set({ step: 'confirm', destination, error: null });
  },

  confirmAccepted: () => {
    if (get().step !== 'confirm') return;
    set({ step: 'approval', error: null });
  },

  approveAndSubmit: async (input) => {
    const state = get();
    if (state.step !== 'approval') return;
    const epoch = state.epoch;

    const { prepared, receiptNumber, destination, refundItemsSnapshot } = state;
    if (
      prepared === null ||
      receiptNumber === null ||
      destination === null ||
      refundItemsSnapshot === null
    ) {
      // Defensive — a corrupted machine aborts to idle (with a visible,
      // translated error) rather than guessing.
      console.error('[refundCheckout] corrupted approval state — aborting to idle', {
        hasPrepared: prepared !== null,
        hasReceiptNumber: receiptNumber !== null,
        hasDestination: destination !== null,
        hasSnapshot: refundItemsSnapshot !== null,
      });
      set({
        ...initialState,
        epoch: epoch + 1,
        error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
      });
      return;
    }

    // Fail-closed stale-cart guard: the prepared/mapped lines (and the
    // approval evidence about to bind them) describe the snapshot taken at
    // begin(). If the cart's return-line set drifted since (scan-hydration /
    // quantity edit behind the modals), submitting would settle the WRONG
    // lines — abort to idle instead.
    const liveReturnItems = useCartStore.getState().returnItems();
    if (returnLinesFingerprint(liveReturnItems) !== returnLinesFingerprint(refundItemsSnapshot)) {
      set({
        ...initialState,
        epoch: epoch + 1,
        error: { key: 'refundFlow.checkout.errorCartChanged', serverMessage: null },
      });
      return;
    }

    set({ step: 'submitting', error: null });

    // 1a. Approval evidence — authored AT MOST ONCE per settlement attempt
    //     and cached IMMEDIATELY, so no later failure can ever discard it
    //     (a retry must never re-append fiscal events for the same target).
    let approval = state.approval;
    if (approval === null) {
      try {
        approval = await authorRefundReturnApproval({
          context: input.approvalContext,
          managerPin: input.managerPin,
          reason: input.reason,
          serverReceiptId: prepared.serverReceiptId,
          receiptNumber,
          lineIds: prepared.lines.map((line) => line.line_id),
        });
      } catch {
        if (get().epoch !== epoch) return;
        set({
          step: 'approval',
          error: { key: 'refundFlow.checkout.errorApproval', serverMessage: null },
        });
        return;
      }
      if (get().epoch !== epoch) return;
      set({ approval, approvalSynced: false });
    }

    // 1b. Force-sync the authored events (re-runnable). A failure here keeps
    //     the cached evidence — the retry re-runs sync only, no second PIN.
    if (!get().approvalSynced) {
      try {
        await syncRefundApprovalEvents(input.approvalContext.companyId, approval);
      } catch {
        if (get().epoch !== epoch) return;
        set({
          step: 'approval',
          error: { key: 'refundFlow.checkout.errorApprovalSync', serverMessage: null },
        });
        return;
      }
      if (get().epoch !== epoch) return;
      set({ approvalSynced: true });
    }

    // 2. Idempotency key — generated once, REUSED on caller-level retries so
    //    the server replays the original return instead of paying out twice.
    const refundRequestId = get().refundRequestId ?? generateRefundRequestId();
    set({ refundRequestId });

    const reasonText = input.reason.trim();
    const result = await submitRefundReturn({
      serverReceiptId: prepared.serverReceiptId,
      terminalId: input.terminalId,
      returnReason: input.returnReason ?? 'other',
      lines: prepared.lines,
      destination,
      refundRequestId,
      approval,
      ...(reasonText !== '' ? { notes: reasonText, overrideReason: reasonText } : {}),
      ...(input.retry !== undefined ? { retry: input.retry } : {}),
    });
    if (get().epoch !== epoch) return; // Torn down mid-submit — no side effects.

    if (!result.ok) {
      // The service already did bounded retries — NO auto-retry here. The
      // cashier retries explicitly from the approval step (same request id).
      set({ step: 'approval', error: settlementErrorToUi(result.error) });
      return;
    }

    // Success — the refund cart is done: clear the return lines, record the
    // response, and hand it to the Phase-3 print seam.
    useCartStore.getState().clearReturnItems();
    set({ step: 'settled', error: null, settledResponse: result.response });
    input.onSettled?.(result.response);
  },

  cancel: () => {
    const { step, epoch } = get();
    // In-flight phases cannot be cancelled (the UI disables their controls);
    // everything else aborts cleanly back to the untouched cart.
    if (step === 'preparing' || step === 'submitting') return;
    set({ ...initialState, epoch: epoch + 1 });
  },

  clearError: () => {
    set({ error: null });
  },

  acknowledgeSettled: () => {
    if (get().step !== 'settled') return;
    // Keep settledResponse readable for the Phase-3 print seam until the
    // next begin()/reset() wipes it.
    set({
      step: 'idle',
      error: null,
      prepared: null,
      destination: null,
      refundItemsSnapshot: null,
      refundRequestId: null,
      approval: null,
      approvalSynced: false,
    });
  },

  reset: () => {
    set({ ...initialState, epoch: get().epoch + 1 });
  },
}));
