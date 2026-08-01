/**
 * Refund checkout orchestration (Task 2b — the user-facing half of Task 53).
 *
 * v3-refund-chain-integration spec §9.2/§9.3 — this store now runs TWO
 * parallel settlement paths behind the terminal's local
 * `v4_refund_authoring_enabled` capability flag, discriminated by
 * `BeginRefundCheckoutInput.v4CapabilityEnabled` (read once, by
 * `HomePage.tsx`'s dispatch seam, at `begin()` time — never re-read
 * mid-flight):
 *
 *   - DISABLED (the common case until this terminal's §9.3 two-phase
 *     rollout completes) → the LEGACY flow, BYTE-INTACT: server-side
 *     receipt resolution (`prepareRefundSettlement`), the legacy
 *     `/return` POST (`submitRefundReturn`), the legacy manager-PIN
 *     handshake (`authorRefundReturnApproval`/`syncRefundApprovalEvents`),
 *     and the legacy Z-accounting mirror (`recordRefundSettlementForZ`).
 *     Settles with `settledResponse: ReturnSettlementResponse`, calling
 *     `onSettled`. Unchanged from before this feature — the server's own
 *     `LegacyCorrectionGuard` (§9.1/§9.3) keeps accepting `/return` for
 *     any terminal that has not yet acknowledged the v4 capability, so
 *     this path must keep working exactly as it always has.
 *   - ENABLED → the NEW v4 flow: the original is resolved PURELY LOCALLY
 *     (`resolveOriginalFiscalEventLocally`, no server round trip), refused
 *     up front (§3.5/§3.7, BEFORE any approval authoring) for a training
 *     or whole-discount original, a `refund_intents` row is drafted/reused
 *     (§4.4), the manager-PIN handshake authors DEVICE-SIGNED approval
 *     events (`authorRefundReturnApprovalV3`, §4.2), and the refund's own
 *     v4 fiscal event + `offline_receipts` row are appended atomically
 *     (`createRefundReceipt`, §4.3/§7.2). Settles with a discriminated
 *     `v4SettledResult`, calling `onV4Settled` — never `onSettled`/
 *     `settledResponse`, which stay meaningless (null) for this path. Cash
 *     destination only (§3.4) — the `destination` step is skipped
 *     entirely (never transitions to `'destination'`); `destination` is
 *     stamped `'cash'` programmatically at `begin()`.
 *
 * State machine (both paths share the same step names):
 *
 *   idle ── begin() ──▶ preparing ──▶ [destination — LEGACY only] ──▶ confirm ──▶ approval
 *                          │ (typed error → idle + error)                           │
 *                          ▼                                                        ▼
 *                        idle ◀───────────────────────── cancel() ──────────  submitting
 *                                                                                    │
 *                                                                  success ──▶    settled
 *
 *   - `begin` SNAPSHOTS the return lines on both paths: the UI displays
 *     totals from the snapshot (not the live cart), and `approveAndSubmit`
 *     fail-closes if the cart's return-line set drifted from the snapshot
 *     (a scan/edit while the modal sequence was up) — shared logic,
 *     identical fingerprint guard on both paths.
 *   - `approveAndSubmit` runs the manager-PIN approval in TWO phases on
 *     BOTH paths (author once, cache immediately; sync/append is
 *     re-runnable): a later failure never discards the authored evidence,
 *     so a retry never re-appends fiscal events or asks for a second PIN.
 *   - Serialization: `begin` is a no-op unless idle; `approveAndSubmit` is
 *     a no-op unless on the approval step.
 *   - Epoch guard: `reset` / `cancel` / `begin` bump an epoch counter; async
 *     continuations re-check it after every await and die silently if the
 *     flow was torn down mid-flight.
 *   - On a v4 success, `useRefundReconciliationStore.getState().refresh()`
 *     is bumped so `RefundPayoutReconciliationModal` (§4.5) shows the
 *     payout-confirmation prompt immediately, not only on next app start.
 *
 * NO user-facing strings here — errors are i18n keys the UI translates.
 */
import { create } from 'zustand';
import type Database from '@tauri-apps/plugin-sql';
import type { CartItem } from '@/types/cart';
import { getDatabase } from '@/lib/db';
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
import { recordRefundSettlementForZ } from '@/lib/refundFlow/refundZAccounting';
import { authorRefundReturnApprovalV3 } from '@/lib/refundFlow/refundApprovalV3';
import type { PosOverrideContext, PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore, fiscalShiftIdForReceipt } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import { resolveSellerIdentity } from '@/lib/fiscal/sellerIdentity';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import {
  resolveOriginalFiscalEventLocally,
  type OriginalFiscalEventLocalView,
} from '@/lib/db/repositories/fiscalEventRepository';
import {
  createOrReuseActiveRefundIntent,
  markApprovalAuthored,
  type RefundIntentRow,
} from '@/lib/db/repositories/refundIntentRepository';
import {
  assertOriginalRefundable,
  type ReturnLineDisposition,
  type RefundLineInput,
} from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import { createRefundReceipt } from '@/lib/offline/refundReceiptService';

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
  | 'refundFlow.checkout.errorCartChangedAfterSettle'
  | 'refundFlow.checkout.errorInternal'
  /**
   * v3-refund-chain-integration spec §3.7 — v4-only: the resolved
   * original's own signed `training_flag` is true. Checked at the LOOKUP
   * level (begin(), immediately after resolving the original), before any
   * approval authoring — mirrors `assertOriginalRefundable()`'s own
   * primary enforcement point.
   */
  | 'refundFlow.trainingOriginalRefused'
  /**
   * v3-refund-chain-integration spec §3.5 — v4-only: the resolved
   * original's own signed `transaction_discount_amount` is non-zero.
   * Same lookup-level enforcement point as above.
   */
  | 'refundFlow.wholeDiscountReceiptRefused';

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

// ─── v4-only helpers ─────────────────────────────────────────────────────────

/**
 * v3-refund-chain-integration spec §3.3 — recovers the ORIGINAL's own
 * `line_items[]` index a return `CartItem` refunds, from the id
 * convention `hydrateFromReceipt.ts` stamps at scan-hydration time
 * (`return-${receiptUuid}-${idx}`, unchanged, not this item's file).
 * `receiptUuid` is itself a UUID (contains hyphens), but `idx` is always
 * the LAST `-`-delimited segment and is never itself hyphenated, so a
 * trailing-segment match is unambiguous regardless of the UUID's own
 * hyphen count. A quantity edit on the cart line only changes `quantity`,
 * never `id`, so this stays valid after a partial-refund edit.
 */
function resolveOriginalLineIndex(cartItemId: string): number | null {
  const match = /-(\d+)$/.exec(cartItemId);
  if (match === null || match[1] === undefined) return null;
  const parsed = Number.parseInt(match[1], 10);
  return Number.isFinite(parsed) ? parsed : null;
}

/**
 * v3-refund-chain-integration spec §3.3 — builds the strict parallel-array
 * `RefundLineInput[]` `createRefundReceipt()`/`buildRefundReceiptV4Payload()`
 * need, pairing each snapshotted return line with the ORIGINAL's own line
 * index. Returns `null` (never throws) when any line cannot be resolved —
 * the caller treats this as `errorLineMapping`, mirroring the legacy
 * path's own failure vocabulary for the same class of problem.
 *
 * **Disposition — launch default, stated visibly (⚖️ coordinator ruling):**
 * every line is stamped `'restock'` unconditionally — there is no
 * disposition-picker UI at launch. Safe against the launch's one known
 * hazard because the server projector's `RestockPolicyResolver` already
 * overrides regulated never-restock items regardless of the payload's own
 * disposition; the accepted gap is a genuinely damaged (non-regulated)
 * item restocking and needing a manual adjustment. See
 * `RETURN_LINE_DISPOSITIONS`'s own docblock in `RefundReceiptV4Payload.ts`.
 */
function buildV4RefundLines(
  refundItems: readonly CartItem[],
  original: OriginalFiscalEventLocalView,
): RefundLineInput[] | null {
  const lines: RefundLineInput[] = [];
  const disposition: ReturnLineDisposition = 'restock';
  for (const item of refundItems) {
    const originalLineIndex = resolveOriginalLineIndex(item.id);
    if (originalLineIndex === null || original.lineItems[originalLineIndex] === undefined) {
      return null;
    }
    lines.push({ cartItem: item, originalLineIndex, disposition });
  }
  return lines;
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
  /**
   * v3-refund-chain-integration spec §9.2 — read ONCE by `HomePage.tsx`'s
   * dispatch seam (the local `terminal_state.v4_refund_authoring_enabled`
   * flag) and handed to `begin()` rather than re-read here: routes to the
   * NEW v4 flow when true, the LEGACY flow (unchanged) when false. The two
   * flows coexist behind this flag — a non-acknowledged terminal keeps
   * using the legacy `/return` path exactly as it always has (§9.3's own
   * two-phase design; the server's `LegacyCorrectionGuard` keeps accepting
   * it until acknowledgement).
   */
  v4CapabilityEnabled: boolean;
  /**
   * v4-only, REQUIRED when `v4CapabilityEnabled` is true: the device-local
   * original receipt identity (`offline_receipts.id` — the same uuid
   * `HomePage.tsx` already tracks as `activeRefundReceiptUuid`). Ignored
   * on the legacy path (which resolves the original server-side instead).
   */
  originalLocalReceiptId?: string;
}

export interface V4RefundSettledResult {
  fiscalEventId: string;
  offlineReceiptId: string;
  receiptNumber: string;
  refundIntentId: string;
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
   * LEGACY settled seam — receives the FULL /return response (incl.
   * qr_token and issued_voucher). Never called on the v4 path (which has
   * no server response to hand back — see `onV4Settled`).
   */
  onSettled?: (response: ReturnSettlementResponse) => void;
  /**
   * v4 settled seam — receives the device-authored settlement artifact.
   * Never called on the legacy path. Deliberately a DIFFERENT shape from
   * `ReturnSettlementResponse` rather than a fabricated one: a v4 refund
   * has no `qr_token`/`issued_voucher` (those are server-response fields
   * that do not exist for a purely local, device-authored settlement) —
   * inventing them would be exactly the class of phantom-field lie this
   * program exists to eliminate. Printing/payout-confirmation for this
   * result flows through `RefundPayoutReconciliationModal` (§4.5), driven
   * by `refund_intents`/`getOfflineReceiptForPrint`, not this callback.
   */
  onV4Settled?: (result: V4RefundSettledResult) => void;
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
  /** Idempotency key — ONE per settlement attempt, reused across retries.
   *  LEGACY path only. */
  refundRequestId: string | null;
  /** Cached approval evidence — authored once, reused across submit
   *  retries. Shared shape on both paths (`authorRefundReturnApprovalV3`
   *  returns the same seven-field `PosOverrideEvidence` the legacy
   *  `authorRefundReturnApproval` does). */
  approval: RefundApprovalEvidence | PosOverrideEvidence | null;
  /** True once the authored approval events were confirmed synced
   *  server-side. LEGACY path only — the v4 path's approval events sync
   *  via the normal fiscal-event queue, tracked by `refund_intents.state`,
   *  not this flag. */
  approvalSynced: boolean;
  /** Last settled response (Phase-3 print seam reads this until the next
   *  begin/reset). LEGACY path only — always null on a v4 settlement. */
  settledResponse: ReturnSettlementResponse | null;
  /** v4-only settled result — always null on a legacy settlement. */
  v4SettledResult: V4RefundSettledResult | null;
  /**
   * Phase 4 (fiscal audit B2) — whether the settled refund was mirrored into
   * `local_refund_records` for the device Z aggregation (LEGACY path). On
   * the v4 path this is always `true` on a successful settle:
   * `createRefundReceipt()` writes the `offline_receipts` refund row in
   * the SAME atomic write-gate transaction as the fiscal event itself
   * (§4.3/§7.2) — there is no separate write that can fail independently
   * the way the legacy best-effort `local_refund_records` mirror can, so
   * the "Z totals need reconciliation" warning class this flag exists to
   * drive is structurally unreachable for v4.
   */
  settledZAccountingRecorded: boolean | null;
  /**
   * Teardown counter (epoch guard). Bumped by reset/cancel/begin; async
   * continuations captured under an older epoch bail instead of mutating
   * state that no longer belongs to them.
   */
  epoch: number;
  /** True for the DURATION of the current settlement attempt when it is
   *  running the v4 flow (set once at begin(), from the caller-supplied
   *  capability flag; never re-read mid-flight). */
  isV4: boolean;
  /** v4-only: the refund_intents row driving this settlement attempt. */
  refundIntent: RefundIntentRow | null;
  /** v4-only: the original resolved locally at begin() — reused by
   *  approveAndSubmit so the original is never re-resolved mid-flow. */
  v4Original: OriginalFiscalEventLocalView | null;
}

interface RefundCheckoutActions {
  /**
   * Pay pressed on an all-return cart. No-op unless idle (a second Pay press
   * while a checkout is active does nothing). Runs prepare; on success the
   * flow advances to the destination step (LEGACY) or straight to confirm
   * (v4 — cash-only, §3.4, no destination choice to make).
   */
  begin: (input: BeginRefundCheckoutInput) => Promise<void>;
  /** Destination chosen in the picker → confirm step. LEGACY-reachable
   *  only in practice (v4 stamps 'cash' at begin() and skips this step),
   *  but left callable — a no-op unless step === 'destination'. */
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
  v4SettledResult: null,
  settledZAccountingRecorded: null,
  epoch: 0,
  isV4: false,
  refundIntent: null,
  v4Original: null,
};

type SetFn = (partial: Partial<RefundCheckoutState>) => void;
type GetFn = () => RefundCheckoutStore;

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
      isV4: input.v4CapabilityEnabled,
    });

    if (input.v4CapabilityEnabled) {
      await beginV4(set, get, epoch, input);
      return;
    }

    // ─── LEGACY (unchanged) ───────────────────────────────────────────────
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

    if (state.isV4) {
      await approveAndSubmitV4(set, get, epoch, input);
      return;
    }

    // ─── LEGACY (unchanged) ─────────────────────────────────────────────
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
    let approval = state.approval as RefundApprovalEvidence | null;
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
    // Record-at-settle (fiscal audit B2): the refund SETTLED server-side, so
    // the local Z-accounting mirror MUST be attempted even when the UI flow
    // was torn down mid-submit — the epoch guard runs AFTER this write. The
    // recorder never throws and is idempotent on the server return receipt
    // id; a `false` outcome means the device Z will undercount this refund
    // until server reconciliation (surfaced as a warning, never un-settled).
    let zAccountingRecorded = false;
    if (result.ok) {
      zAccountingRecorded = await recordRefundSettlementForZ({
        companyId: input.approvalContext.companyId,
        terminalId: input.terminalId,
        destination,
        originalReceiptNumber: receiptNumber,
        response: result.response,
      });
    }

    if (get().epoch !== epoch) return; // Torn down mid-submit — no UI side effects.

    if (!result.ok) {
      // The service already did bounded retries — NO auto-retry here. The
      // cashier retries explicitly from the approval step (same request id).
      set({ step: 'approval', error: settlementErrorToUi(result.error) });
      return;
    }

    // Success — the refund cart is done. Codex r1 M2 defense-in-depth: the
    // pre-submit fingerprint check ran ONCE before the async approval/sync/
    // submit chain, so re-check the live return lines NOW (after the epoch
    // guard, before any cart mutation). If a scan-hydration swapped the
    // return lines mid-submit, the server settled the OLD prepared lines —
    // clearing the cart would eat the NEW, unrelated lines. The settled
    // refund itself is correct either way: record it, hand it to the print
    // seam, and surface the drift so the cashier re-checks the cart.
    const liveAfterSettle = useCartStore.getState().returnItems();
    const driftedAfterSettle =
      returnLinesFingerprint(liveAfterSettle) !== returnLinesFingerprint(refundItemsSnapshot);
    if (!driftedAfterSettle) {
      useCartStore.getState().clearReturnItems();
    }
    set({
      step: 'settled',
      error: driftedAfterSettle
        ? { key: 'refundFlow.checkout.errorCartChangedAfterSettle', serverMessage: null }
        : null,
      settledResponse: result.response,
      settledZAccountingRecorded: zAccountingRecorded,
    });
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
    // Keep settledResponse/v4SettledResult readable for the print seam
    // until the next begin()/reset() wipes it. The error is PRESERVED (not
    // nulled): a post-settle drift error (M2, legacy-only) must survive
    // into the idle banner — on a clean settle it is already null.
    set({
      step: 'idle',
      prepared: null,
      destination: null,
      refundItemsSnapshot: null,
      refundRequestId: null,
      approval: null,
      approvalSynced: false,
      refundIntent: null,
      v4Original: null,
    });
  },

  reset: () => {
    set({ ...initialState, epoch: get().epoch + 1 });
  },
}));

// ─── v4 flow internals ───────────────────────────────────────────────────────

/**
 * v3-refund-chain-integration spec §4.1/§4.4 — the v4 `begin()` branch.
 * Purely local: resolves the original from SQLite (no server round trip),
 * refuses up front for a training/whole-discount original (§3.5/§3.7,
 * BEFORE any approval authoring), and drafts/reuses the `refund_intents`
 * row. Skips the `'destination'` step entirely (cash-only, §3.4) —
 * advances straight from `'preparing'` to `'confirm'`.
 */
async function beginV4(
  set: SetFn,
  get: GetFn,
  epoch: number,
  input: BeginRefundCheckoutInput,
): Promise<void> {
  const originalLocalReceiptId = input.originalLocalReceiptId;
  if (originalLocalReceiptId === undefined || originalLocalReceiptId === '') {
    console.error('[refundCheckout] v4 begin() called without originalLocalReceiptId');
    set({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }

  const terminal = useTerminalStore.getState().terminal;
  const operator = useOperatorStore.getState().operator;
  if (terminal === null || operator === null) {
    console.error('[refundCheckout] v4 begin() called without an active terminal/operator');
    set({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }

  const original = await resolveOriginalFiscalEventLocally(input.db, originalLocalReceiptId);
  if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

  if (original === null) {
    set({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }

  // §3.5/§3.7 — the PRIMARY enforcement point for both refusals (lookup
  // level, before any approval authoring). Shares the exact same checks
  // (and typed errors) `buildRefundReceiptV4Payload()`'s own defense-in-
  // depth re-asserts later.
  try {
    assertOriginalRefundable(original, originalLocalReceiptId);
  } catch (refusalError) {
    const key =
      refusalError instanceof Error && refusalError.name === 'TrainingOriginalRefundRefusedError'
        ? 'refundFlow.trainingOriginalRefused'
        : 'refundFlow.wholeDiscountReceiptRefused';
    set({
      step: 'idle',
      error: { key, serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }

  const lineSnapshot = buildV4RefundLines(input.refundItems, original);
  if (lineSnapshot === null) {
    set({
      step: 'idle',
      error: { key: 'refundFlow.checkout.errorLineMapping', serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }

  const { intent } = await createOrReuseActiveRefundIntent(input.db, {
    id: crypto.randomUUID(),
    terminalId: terminal.id,
    operatorId: operator.id,
    originalLocalReceiptId,
    originalFiscalEventId: original.fiscalEventId,
    lineSnapshot,
    approvalSourceEventId: crypto.randomUUID(),
    overrideSourceEventId: crypto.randomUUID(),
  });
  if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

  // v4 is cash-only at launch (§3.4) — the destination step is skipped
  // entirely; 'cash' is stamped programmatically, never chosen in a picker.
  set({
    step: 'confirm',
    destination: 'cash',
    refundIntent: intent,
    v4Original: original,
    error: null,
  });
}

/**
 * v3-refund-chain-integration spec §4.2/§4.3 — the v4 `approveAndSubmit`
 * branch. Same two-phase-approval-then-submit shape as the legacy branch
 * (author once and cache immediately; the fiscal append is re-runnable),
 * but authors DEVICE-SIGNED events (`authorRefundReturnApprovalV3`) and
 * settles via the atomic append-first write (`createRefundReceipt`)
 * instead of an HTTP POST.
 */
async function approveAndSubmitV4(
  set: SetFn,
  get: GetFn,
  epoch: number,
  input: ApproveAndSubmitInput,
): Promise<void> {
  const state = get();
  const { refundIntent, v4Original, receiptNumber, refundItemsSnapshot } = state;
  if (refundIntent === null || v4Original === null || receiptNumber === null || refundItemsSnapshot === null) {
    console.error('[refundCheckout] v4 corrupted approval state — aborting to idle', {
      hasIntent: refundIntent !== null,
      hasOriginal: v4Original !== null,
      hasReceiptNumber: receiptNumber !== null,
      hasSnapshot: refundItemsSnapshot !== null,
    });
    set({
      ...initialState,
      epoch: epoch + 1,
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
    });
    return;
  }

  // Same fail-closed stale-cart guard as the legacy path — shared logic,
  // identical fingerprint comparison.
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

  // Resolve the same context paymentStore.ts's own SALE-side
  // createReceiptLocalFirst() reads for the exact same reason (stores
  // compose global state + call into the service layer; the service layer
  // itself stays store-free for testability, receiptService.ts's own
  // documented convention).
  const authState = useAuthStore.getState();
  const terminalState = useTerminalStore.getState();
  const operatorState = useOperatorStore.getState();
  const { paymentMethods, paymentRepositories } = usePaymentStore.getState();

  const companyId = authState.companyId;
  const tenantId = authState.user?.tenantId;
  const terminal = terminalState.terminal;
  const shift = terminalState.shift;
  const operator = operatorState.operator;
  const company = companyId !== null ? authState.companies.find((c) => c.id === companyId) : undefined;
  const cashMethod = paymentMethods.find((m) => m.is_cash_tender && m.is_active);
  const cashRepository = paymentRepositories.find((r) => r.type === 'cash_register' && r.is_active);

  if (
    companyId === null
    || tenantId === undefined
    || terminal === null
    || shift === null
    || operator === null
    || company === undefined
    || cashMethod === undefined
    || cashRepository === undefined
  ) {
    if (get().epoch !== epoch) return;
    set({
      step: 'approval',
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
    });
    return;
  }

  const db = await getDatabase(companyId);

  // 1. Approval evidence — authored AT MOST ONCE per settlement attempt,
  //    cached IMMEDIATELY (same discipline as the legacy path — the
  //    fiscal approval+override events, once signed, must survive ANY
  //    later failure, including a failure in the local bookkeeping write
  //    right below). Threads the intent's own PRE-GENERATED sourceEventIds
  //    through so the append is recoverable by a stable id after a crash.
  let approval = state.approval as PosOverrideEvidence | null;
  if (approval === null) {
    try {
      approval = await authorRefundReturnApprovalV3({
        context: input.approvalContext,
        managerPin: input.managerPin,
        reason: input.reason,
        originalLocalReceiptId: refundIntent.original_local_receipt_id,
        originalFiscalEventId: refundIntent.original_fiscal_event_id,
        lineSnapshot: JSON.parse(refundIntent.line_snapshot_json) as unknown,
        approvalSourceEventId: refundIntent.approval_source_event_id,
        overrideSourceEventId: refundIntent.override_source_event_id,
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
    // Cache IMMEDIATELY — the fiscal events are already signed at this
    // point regardless of what happens next.
    set({ approval });
    try {
      await markApprovalAuthored(db, refundIntent.id);
    } catch (markError) {
      // Best-effort local bookkeeping ONLY — the fiscal approval+override
      // events are ALREADY signed and cached above; this write merely
      // advances refund_intents.state for crash-recovery/UI purposes. A
      // failure here must NEVER cause a retry to re-author (which would
      // double-append fiscal events for the same target), so it is
      // logged, never surfaced as a checkout error.
      console.error(
        '[refundCheckout] markApprovalAuthored failed (non-fatal — approval already signed and cached)',
        markError,
      );
    }
  }

  // Receipt-number cosmetics only (matches receiptService.ts's own
  // fallback contract) — never load-bearing for the fiscal append itself.
  let terminalCode = terminal.id;
  let locationCode = 'MAIN';
  try {
    const hashState = await getTerminalState(db, terminal.id);
    if (hashState !== null) {
      terminalCode = hashState.terminal_code;
      locationCode = hashState.location_code;
    }
  } catch {
    // Fall back to the defaults above.
  }

  // 2. Settle — the append-first atomic write (fiscal event + refund_intents
  //    update + offline_receipts insert, ALL in one write-gate transaction,
  //    §4.3/§7.2). No separate "sync" phase on this path: the fiscal
  //    event's own sync queue (syncService.ts) carries it to the server
  //    afterward, tracked by refund_intents.state, not a store flag.
  try {
    const lines = buildV4RefundLines(refundItemsSnapshot, v4Original);
    if (lines === null) {
      throw new Error('refund line snapshot could no longer be mapped to the original');
    }

    const receiptResult = await createRefundReceipt({
      tenantId,
      companyId,
      terminalId: terminal.id,
      terminalCode,
      locationCode,
      operatorId: operator.id,
      operatorName: operator.name,
      shiftId: fiscalShiftIdForReceipt(shift),
      businessDate: input.approvalContext.businessDate,
      currency: company.currency ?? 'EUR',
      seller: resolveSellerIdentity(company, terminal.location ?? null),
      refundReason: input.reason.trim(),
      lines,
      original: v4Original,
      originalReceiptUuid: refundIntent.original_local_receipt_id,
      originalBusinessDate: input.approvalContext.businessDate,
      approvalReferences: [approval],
      refundIntentId: refundIntent.id,
      paymentMethodId: cashMethod.id,
      paymentRepositoryId: cashRepository.id,
    });

    if (get().epoch !== epoch) return; // Torn down mid-submit — no UI side effects.

    // Same M2 defense-in-depth as the legacy path: re-check the live
    // return lines AFTER the epoch guard, before any cart mutation.
    const liveAfterSettle = useCartStore.getState().returnItems();
    const driftedAfterSettle =
      returnLinesFingerprint(liveAfterSettle) !== returnLinesFingerprint(refundItemsSnapshot);
    if (!driftedAfterSettle) {
      useCartStore.getState().clearReturnItems();
    }

    const v4Result: V4RefundSettledResult = {
      fiscalEventId: receiptResult.fiscalEvent.id,
      offlineReceiptId: receiptResult.offlineReceiptId,
      receiptNumber: receiptResult.receiptNumber,
      refundIntentId: refundIntent.id,
    };

    set({
      step: 'settled',
      error: driftedAfterSettle
        ? { key: 'refundFlow.checkout.errorCartChangedAfterSettle', serverMessage: null }
        : null,
      v4SettledResult: v4Result,
      settledZAccountingRecorded: true,
    });
    // §4.5 — the payout-confirmation prompt shows immediately, not only on
    // the next app restart.
    useRefundReconciliationStore.getState().refresh();
    input.onV4Settled?.(v4Result);
  } catch (settleError) {
    if (get().epoch !== epoch) return;
    console.error('[refundCheckout] v4 createRefundReceipt failed', settleError);
    // errorInternal, not errorServer -- this is a purely local failure
    // (append-first write-gate transaction), never a server round trip.
    set({
      step: 'approval',
      error: { key: 'refundFlow.checkout.errorInternal', serverMessage: null },
    });
  }
}

/**
 * True while the refund checkout flow owns the cart's return lines (any
 * non-idle step, including the settled-acknowledge window). Codex r1 M2 —
 * the receipt-scan path (dispatcher, confirmation sheet, hydration) consults
 * this gate so a scan cannot replace the return lines while a settlement is
 * being prepared/approved/submitted.
 */
export function isRefundCheckoutActive(): boolean {
  return useRefundCheckoutStore.getState().step !== 'idle';
}
