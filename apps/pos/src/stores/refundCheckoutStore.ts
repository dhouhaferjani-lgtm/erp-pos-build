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
import {
  authorRefundReturnApprovalV3,
  recoverRefundApprovalEvidenceLocally,
} from '@/lib/refundFlow/refundApprovalV3';
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
  getFiscalEventById,
  resolveOriginalFiscalEventLocally,
  type OriginalFiscalEventLocalView,
} from '@/lib/db/repositories/fiscalEventRepository';
import {
  computeLineSnapshotFingerprint,
  createOrReuseActiveRefundIntent,
  ensureApprovalAuthored,
  findActiveRefundIntent,
  getCumulativeRefundedQuantityByOriginalLine,
  type RefundIntentRow,
} from '@/lib/db/repositories/refundIntentRepository';
import {
  assertOriginalRefundable,
  REFUND_QUANTITY_SCALE,
  type ReturnLineDisposition,
  type RefundLineInput,
} from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import { createRefundReceipt } from '@/lib/offline/refundReceiptService';
import {
  getOfflineReceiptById,
  getOfflineReceiptByIdempotencyKey,
} from '@/lib/db/repositories/offlineReceiptRepository';
import { sumLegacyRefundedValueForOriginalReceipt } from '@/lib/db/repositories/localRefundRecordRepository';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';

/**
 * The refund path's ONE quantity scale — the canonical payload's own
 * frozen scale, re-exported from the module that owns the contract
 * (`REFUND_QUANTITY_SCALE`). Round-2 fix for finding 3: normalizing here
 * at a DIFFERENT scale from the one the chain signs meant the cap and the
 * local row could carry a 4th decimal the payload truncates away, i.e. a
 * quantity that was never signed.
 */
const QUANTITY_SCALE = REFUND_QUANTITY_SCALE;

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
  | 'refundFlow.wholeDiscountReceiptRefused'
  /**
   * v3-refund-chain-integration spec §9.6 — wave-2 fix-wave finding 8:
   * the resolved original was not tendered as a single CASH leg. This
   * launch pays refunds out in cash only and can therefore only refund
   * cash-tendered originals; a card/mixed/voucher original is refused at
   * the SAME lookup-level point as §3.5/§3.7, before any approval
   * authoring.
   */
  | 'refundFlow.nonCashOriginalRefused'
  /**
   * v3-refund-chain-integration wave-2 review fix, orchestrator-ruled
   * (required) — v4-only: the device-local cumulative-quantity backstop
   * (§12 stays the server-side cross-terminal authority; this is a
   * device-side belt) found the new refund selection would exceed the
   * original line's own quantity, summed against every ALREADY-appended
   * refund_intents row for the same original.
   */
  | 'refundFlow.refundQuantityExceeded'
  /**
   * v3-refund-chain-integration wave-2 fix-wave finding 10 (fiscal I-1) —
   * v4-only: a PARTIAL refund of a line that carried a per-line discount.
   * Refused at the LOOKUP level, before the manager PIN is spent, rather
   * than throwing `LineArithmeticInvariantError` from the payload builder
   * AFTER the approval + override events are already on the chain.
   */
  | 'refundFlow.discountedPartialRefundRefused'
  /**
   * v3-refund-chain-integration wave-2 fix-wave finding 11 (fiscal I-2 +
   * codex M-3) — v4-only: the reused intent has ALREADY appended its
   * refund fiscal event. The refund is done; what remains is payout
   * confirmation / AVOIR reprint, which the reconciliation modal owns.
   */
  | 'refundFlow.refundAlreadyAppended'
  /**
   * v3-refund-chain-integration wave-2 fix-wave finding 19 (⚖️
   * orchestrator-adopted from the wave-3 payout-cash-bound analysis §M1)
   * — v4-only: the RECEIPT-LEVEL, VALUE-BASED legacy bound. Refunds
   * settled through the LEGACY `/return` path leave no `refund_intents`
   * row, so the per-line cumulative cap is blind to them; this bound sums
   * `local_refund_records` for the ORIGINAL's receipt number and refuses
   * when the total refunded value would exceed the original's own total.
   */
  | 'refundFlow.legacyRefundValueExceeded';

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
/**
 * Maps the three typed lookup-level refusals `assertOriginalRefundable()`
 * can throw (§3.5, §3.7 and — wave-2 fix-wave finding 8 — §9.6) onto their
 * i18n keys. Kept exhaustive-by-name rather than a two-way ternary so a
 * fourth refusal cannot silently inherit another one's message: an
 * unrecognized throwable degrades to `errorInternal`, never to a wrong but
 * plausible refusal string.
 */
function refusalErrorToKey(error: unknown): RefundCheckoutErrorKey {
  const name = error instanceof Error ? error.name : '';
  switch (name) {
    case 'TrainingOriginalRefundRefusedError':
      return 'refundFlow.trainingOriginalRefused';
    case 'WholeReceiptDiscountRefundRefusedError':
      return 'refundFlow.wholeDiscountReceiptRefused';
    case 'NonCashOriginalRefundRefusedError':
      return 'refundFlow.nonCashOriginalRefused';
    default:
      return 'refundFlow.checkout.errorInternal';
  }
}

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
    // ── Wave-2 fix-wave finding 3 (codex C-2) — THE single normalization
    //    point for refund quantity. `bcabs` at the quantity scale turns
    //    the negative-signed return `CartItem.quantity` into the
    //    canonical POSITIVE decimal string §3.2 defines, and that one
    //    string is then carried verbatim into the intent's line
    //    snapshot, the cumulative-quantity cap, the `offline_receipts`
    //    line mirror, and `original_line_references[i].quantity`.
    //    Previously each of those four consumers re-derived its own
    //    string from the number with a different formatter.
    lines.push({
      cartItem: item,
      originalLineIndex,
      disposition,
      quantity: bcabs(String(item.quantity), QUANTITY_SCALE),
    });
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
    set({
      step: 'idle',
      error: { key: refusalErrorToKey(refusalError), serverMessage: null },
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

  // ── Round-2 fix (finding 11 residual, both re-review arms converged) —
  //    resolve and BRANCH ON the reused intent's state BEFORE computing
  //    any cap refusal.
  //
  //    The cumulative-quantity cap counts every intent that has reached a
  //    state proving an append, INCLUDING the one about to be reused. So
  //    for a FULL-quantity refund that was already appended, the cap saw
  //    `alreadyRefunded (full) + thisAttempt (full) > originalQuantity`
  //    and refused with `refundQuantityExceeded` — a message naming a
  //    cause that does not exist — before reuse was ever discovered, so
  //    the intended `refundAlreadyAppended` route and its
  //    `useRefundReconciliationStore.refresh()` never ran. The operator
  //    was told they were over-refunding when in fact the refund was
  //    already done and merely needed payout/print reconciliation.
  //
  //    The lookup here is READ-ONLY and uses the SAME key
  //    `createOrReuseActiveRefundIntent()` scopes below, so the row found
  //    now is the row reused later.
  const lineSnapshotFingerprint = await computeLineSnapshotFingerprint(lineSnapshot);
  const existingIntent = await findActiveRefundIntent(
    input.db,
    originalLocalReceiptId,
    lineSnapshotFingerprint,
  );
  if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

  let recoveredApproval: PosOverrideEvidence | null = null;
  if (existingIntent !== null) {
    const resumed = await resumeReusedIntent(input.db, companyIdForResume(), existingIntent);
    if (get().epoch !== epoch) return;
    if (resumed.outcome === 'refuse') {
      set({
        step: 'idle',
        error: { key: resumed.errorKey, serverMessage: null },
        refundItemsSnapshot: null,
      });
      if (resumed.showReconciliation) {
        // The refund IS done — surface the payout/reprint prompt the
        // reconciliation modal owns instead of pretending the flow can
        // start over.
        useRefundReconciliationStore.getState().refresh();
      }
      return;
    }
    recoveredApproval = resumed.approval;
  }

  // Orchestrator-ruled (required) — device-local cumulative-quantity
  // BACKSTOP (§12's server-side FOR UPDATE lock stays the sole
  // cross-terminal authority; this is a single-device belt). Sums the
  // ALREADY-refunded quantity per original line across every
  // refund_intents row for this SAME original that has reached a state
  // proving a fiscal event was actually appended, then refuses THIS
  // attempt if the new selection would push the cumulative total past
  // the original line's own quantity.
  //
  // ⚖️ Q-1 ruling (wave-2 fix-wave finding 18): an UNREADABLE prior
  // appended snapshot now THROWS rather than being skipped. Skipping
  // undercounted the already-refunded quantity, making the cap more
  // permissive exactly when its data was untrustworthy. Caught here and
  // surfaced as the same refusal the cap itself produces — fail closed.
  let cumulativeRefunded: Map<number, string>;
  try {
    cumulativeRefunded = await getCumulativeRefundedQuantityByOriginalLine(
      input.db,
      originalLocalReceiptId,
    );
  } catch (backstopError) {
    console.error(
      '[refundCheckout] cumulative-quantity backstop could not be computed — refusing (fail closed)',
      backstopError,
    );
    if (get().epoch !== epoch) return;
    set({
      step: 'idle',
      error: { key: 'refundFlow.refundQuantityExceeded', serverMessage: null },
      refundItemsSnapshot: null,
    });
    return;
  }
  if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

  for (const line of lineSnapshot) {
    const alreadyRefunded = cumulativeRefunded.get(line.originalLineIndex) ?? '0';
    // Finding 3 — the SAME canonical string the snapshot/cap/payload use;
    // never a second `Math.abs(...).toFixed(...)` derivation.
    const projectedTotal = bcadd(alreadyRefunded, line.quantity, QUANTITY_SCALE);
    const originalLine = original.lineItems[line.originalLineIndex];
    const originalQuantity = originalLine ? bcabs(originalLine.quantity, QUANTITY_SCALE) : '0';
    if (bccomp(projectedTotal, originalQuantity) > 0) {
      set({
        step: 'idle',
        error: { key: 'refundFlow.refundQuantityExceeded', serverMessage: null },
        refundItemsSnapshot: null,
      });
      return;
    }
  }

  // ── Wave-2 fix-wave finding 19 (⚖️ orchestrator-adopted from the wave-3
  //    payout-cash-bound analysis §M1) — the LEGACY half of the bound.
  //
  //    The cumulative-quantity backstop above sums `refund_intents` rows
  //    ONLY. Refunds settled through the LEGACY `/return` path — which
  //    stays live on non-acknowledged terminals BY RULED DESIGN (§9.2's
  //    dual-path store) — leave no `refund_intents` row at all; their only
  //    device-local trace is `local_refund_records`. So an original
  //    already refunded IN FULL through the legacy path could be refunded
  //    AGAIN, in full, offline, via v4: the device saw zero prior refunds.
  //
  //    `local_refund_records` has no per-line quantities and keys the
  //    original by receipt NUMBER, so this is a RECEIPT-LEVEL, VALUE-BASED
  //    bound — coarser than the per-line cap above, and accepted as such
  //    by the ruling. §12's server-side cap remains the sole
  //    cross-terminal authority; this converts the hole from "invisible"
  //    to "bounded by the original's own value".
  //
  //    Fails closed exactly like finding 18: an unreadable legacy row
  //    UNDERCOUNTS what has already been refunded, which makes the bound
  //    more permissive precisely when its data is untrustworthy.
  try {
    await evaluateLegacyRefundValueBound(
      input.db,
      originalLocalReceiptId,
      original,
      currencyForBound(),
      lineSnapshot,
    );
  } catch (boundError) {
    if (get().epoch !== epoch) return;
    // Round-2 item E3 / minor N-9: only a genuine over-value gets the
    // "already refunded for its full value" copy. A bound that could not
    // be COMPUTED (missing local row, unreadable legacy record) is still
    // fail-closed, but must not assert a fact the device does not know.
    const exceeded = boundError instanceof RefundValueBoundExceededError;
    if (!exceeded) {
      console.error(
        '[refundCheckout] legacy refund-value bound could not be computed — refusing (fail closed)',
        boundError,
      );
    }
    set({
      step: 'idle',
      error: {
        key: exceeded ? 'refundFlow.legacyRefundValueExceeded' : 'refundFlow.checkout.errorInternal',
        serverMessage: null,
      },
      refundItemsSnapshot: null,
    });
    return;
  }
  if (get().epoch !== epoch) return; // Torn down mid-flight — stay dead.

  // ── Wave-2 fix-wave finding 10 (fiscal I-1) — a PARTIAL refund of a
  //    per-line-discounted line is refused HERE, before the manager PIN.
  //
  //    `hydrateFromReceipt()` carries the original line's
  //    `discount_amount` onto the return CartItem but NOT its
  //    `discount_type`, and `cartStore.recalcLineTotal()` only subtracts a
  //    discount when `discount_type === 'fixed'`. So the moment the
  //    cashier edits the return quantity, `line_total` silently becomes
  //    `unit_price × qty` with the discount dropped while
  //    `discount_amount` stays on the item — and `buildLineItems()`
  //    enforces `line_total == unit_price × quantity − discount` exactly,
  //    throwing `LineArithmeticInvariantError`. That throw happened AFTER
  //    the approval + override fiscal events were signed and appended:
  //    fail-closed on the money, but an orphan approval pair permanently
  //    on the chain, the intent stuck at `approval_authored`, and a
  //    generic `errorInternal` for the operator.
  //
  //    Launch refuses instead (consistent with §3.5's whole-receipt-
  //    discount refusal — this launch does not do discount proration).
  //    A FULL-line refund of a discounted line is unaffected and still
  //    works: its quantity is unedited, so `line_total` still carries the
  //    original discount and the invariant holds.
  for (const line of lineSnapshot) {
    const discountAmount = line.cartItem.discount_amount ?? '0';
    // Round-2 minor N-2: `discount_amount` is MONEY — compare it at the
    // currency scale, not at the quantity scale (rule 19 hygiene). The
    // zero-test was harmless either way; the precedent was not.
    if (bccomp(bcabs(discountAmount, getCurrencyDecimals(currencyForBound())), '0') === 0) continue;

    const originalLine = original.lineItems[line.originalLineIndex];
    const originalQuantity = originalLine
      ? bcabs(originalLine.quantity, QUANTITY_SCALE)
      : null;
    if (originalQuantity === null || bccomp(line.quantity, originalQuantity) !== 0) {
      set({
        step: 'idle',
        error: { key: 'refundFlow.discountedPartialRefundRefused', serverMessage: null },
        refundItemsSnapshot: null,
      });
      return;
    }
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
    // Finding 11 — a recovered approval means `approveAndSubmitV4()`
    // resumes the APPEND only: no second manager PIN for a refund whose
    // approval is already signed and on the chain.
    approval: recoveredApproval,
    error: null,
  });
}

/**
 * Wave-2 fix-wave finding 19 / §M1 — the receipt-level, VALUE-based legacy
 * refund bound.
 *
 * Reads the ORIGINAL's own `offline_receipts` row for its `receipt_number`
 * and `total` (the value ceiling), sums the magnitude of every LEGACY
 * refund already recorded against that receipt number, adds THIS refund's
 * own total, and refuses if the result exceeds the original's total.
 *
 * Fail-closed on every unknown: a missing original row, a non-canonical
 * original total, or an unreadable legacy record all refuse. The bound
 * only ever ADDS refusals — it can never authorize a refund the
 * per-line cap already rejected.
 */
async function evaluateLegacyRefundValueBound(
  db: Database,
  originalLocalReceiptId: string,
  original: OriginalFiscalEventLocalView,
  currency: string,
  lines: readonly RefundLineInput[],
): Promise<void> {
  const scale = getCurrencyDecimals(currency);

  // ── Round-2 (finding 19 residual) — the ceiling comes from the SIGNED,
  //    byte-bound, canonical-validated original (the finding-2 path), not
  //    from the mutable `offline_receipts.total` scalar. The mirror is a
  //    convenience row; the chain is the authority, and a value bound that
  //    trusts a mutable scalar is not a bound.
  //
  // ── Round-2 (fiscal N-1) — and it is the EXACT total, not the ROUNDED
  //    one. `receiptService.ts` writes `policySnapshot.roundedTotal` into
  //    the signed `total`, with the signed `cash_rounding_adjustment`
  //    alongside; the v3 aggregate invariant is
  //    `rounded − adjustment == exact`. A refund pays out `Σ|line_total|`
  //    — the EXACT gross line amounts — so comparing that against the
  //    ROUNDED total refused every legitimate FIRST full refund of a
  //    rounded-DOWN receipt (exact 12.34 → rounded 12.30 ⇒ 12.34 > 12.30),
  //    with copy naming a cause that does not exist.
  const originalExactTotal = bcabs(
    bcsub(original.total, original.cashRoundingAdjustment, scale),
    scale,
  );

  // The receipt NUMBER is how `local_refund_records` keys the original —
  // it is not in the signed payload, so it comes from the local mirror,
  // used ONLY as a lookup key (never as a value).
  const originalReceipt = await getOfflineReceiptById(db, originalLocalReceiptId);
  if (originalReceipt === null) {
    throw new RefundValueBoundUnreadableError(originalLocalReceiptId);
  }

  const legacyRefunded = await sumLegacyRefundedValueForOriginalReceipt(
    db,
    originalReceipt.receipt_number,
    scale,
  );

  // This attempt's own value: the same |line_total| sum
  // `createRefundReceipt()` uses for the cash payout leg.
  const thisRefundTotal = lines.reduce(
    (sum, line) => bcadd(sum, bcabs(line.cartItem.line_total, scale), scale),
    bcformat('0', scale),
  );

  const projected = bcadd(legacyRefunded, thisRefundTotal, scale);
  if (bccomp(projected, originalExactTotal) > 0) {
    throw new RefundValueBoundExceededError(
      originalReceipt.receipt_number,
      legacyRefunded,
      thisRefundTotal,
      originalExactTotal,
    );
  }
}

/**
 * Round-2 (finding 19 residual, item E3) — the VALUE bound was genuinely
 * exceeded. Typed and distinct from the data-unreadable failures below, so
 * the operator copy can assert only what the device actually knows.
 */
export class RefundValueBoundExceededError extends Error {
  constructor(
    public readonly originalReceiptNumber: string,
    public readonly alreadyRefunded: string,
    public readonly thisAttempt: string,
    public readonly originalExactTotal: string,
  ) {
    super(
      `Refund refused: ${alreadyRefunded} already refunded on this device for original ${originalReceiptNumber} plus ${thisAttempt} now exceeds the original's exact total ${originalExactTotal} (finding 19 / §M1 receipt-level value bound).`,
    );
    this.name = 'RefundValueBoundExceededError';
  }
}

/** Round-2 — the bound could not be COMPUTED (the original's local row is
 *  missing). Fail-closed, but a different fact from "already refunded". */
export class RefundValueBoundUnreadableError extends Error {
  constructor(public readonly originalLocalReceiptId: string) {
    super(
      `Refund refused: the original receipt ${originalLocalReceiptId} has no local row, so the receipt-level value bound cannot be computed.`,
    );
    this.name = 'RefundValueBoundUnreadableError';
  }
}

/** The active company's currency — the scale every money comparison in the
 *  v4 bound runs at. */
function currencyForBound(): string {
  const authState = useAuthStore.getState();
  return authState.companies.find((c) => c.id === authState.companyId)?.currency ?? 'EUR';
}

/** The company the v4 flow's local reads/authoring run against. */
function companyIdForResume(): string {
  return useAuthStore.getState().companyId ?? '';
}

type ReusedIntentResume =
  | { outcome: 'proceed'; approval: PosOverrideEvidence | null }
  | {
      outcome: 'refuse';
      errorKey: RefundCheckoutErrorKey;
      showReconciliation: boolean;
    };

/**
 * Wave-2 fix-wave finding 11 — branch on the REUSED intent's actual state.
 *
 * `refund_event_appended` is deliberately an ACTIVE state (§4.4 keeps it in
 * the uniqueness set as an in-flight duplicate guard), so
 * `createOrReuseActiveRefundIntent()` can and does return one. The store
 * used to ignore the reused row's state entirely and reopen confirmation,
 * after which `createRefundReceipt()` would get an idempotent-append hit
 * from the engine and then unconditionally attempt
 * `markRefundEventAppended()` from `approval_authored` — a transition that
 * updates zero rows, throws, and ROLLS BACK the whole write-gate
 * transaction. The intent could sit in that error loop forever instead of
 * entering payout/print recovery.
 *
 * Every inconsistent linkage is fail-closed: a state that claims an
 * appended event with no `refund_fiscal_event_id`, or an approval that
 * cannot be recovered from the local chain mirror, refuses rather than
 * guessing.
 */
async function resumeReusedIntent(
  db: Database,
  companyId: string,
  intent: RefundIntentRow,
): Promise<ReusedIntentResume> {
  if (intent.state === 'refund_event_appended') {
    if (intent.refund_fiscal_event_id === null || intent.refund_fiscal_event_id === '') {
      // Structurally impossible (the transition writes both together in
      // one statement) — but a row claiming an appended event without one
      // is corrupt, and corrupt never means "retry the append".
      console.error('[refundCheckout] reused intent claims refund_event_appended with no fiscal event id', {
        intentId: intent.id,
      });
      return { outcome: 'refuse', errorKey: 'refundFlow.checkout.errorInternal', showReconciliation: false };
    }
    // Round-2 (finding 11 residual): verify the linkage for real, not just
    // that an id column is non-empty. The fiscal event must EXIST and must
    // be sourced from THIS intent (§4.3: `source_event_class =
    // 'refund_intents'`, `source_event_id = refund_intents.id`), and the
    // local receipt must exist (§7.2: keyed by
    // `offline_receipts.idempotency_key = refund_intents.id`) AND carry
    // the SAME canonical bytes the writer took from that append result.
    // Anything less can route a corrupt row into payout/print recovery,
    // where the AVOIR reprint would read a receipt that does not
    // correspond to the signed event.
    const linkedEvent = await getFiscalEventById(db, intent.refund_fiscal_event_id);
    if (
      linkedEvent === null
      || linkedEvent.source_event_class !== 'refund_intents'
      || linkedEvent.source_event_id !== intent.id
    ) {
      console.error('[refundCheckout] reused intent references a fiscal event that is missing or not sourced from it', {
        intentId: intent.id,
        refundFiscalEventId: intent.refund_fiscal_event_id,
        found: linkedEvent !== null,
      });
      return { outcome: 'refuse', errorKey: 'refundFlow.checkout.errorInternal', showReconciliation: false };
    }

    const linkedReceipt = await getOfflineReceiptByIdempotencyKey(db, intent.id);
    if (
      linkedReceipt === null
      || linkedReceipt.idempotency_key !== intent.id
      || linkedReceipt.canonical_bytes !== linkedEvent.canonical_bytes
    ) {
      console.error('[refundCheckout] reused intent has an appended fiscal event but no matching offline_receipts row', {
        intentId: intent.id,
        found: linkedReceipt !== null,
        bytesMatch: linkedReceipt?.canonical_bytes === linkedEvent.canonical_bytes,
      });
      return { outcome: 'refuse', errorKey: 'refundFlow.checkout.errorInternal', showReconciliation: false };
    }
    return { outcome: 'refuse', errorKey: 'refundFlow.refundAlreadyAppended', showReconciliation: true };
  }

  if (intent.state === 'approval_authored') {
    if (companyId === '') {
      return { outcome: 'refuse', errorKey: 'refundFlow.checkout.errorInternal', showReconciliation: false };
    }
    const approval = await recoverRefundApprovalEvidenceLocally(
      companyId,
      intent.approval_source_event_id,
      intent.override_source_event_id,
      intent.original_local_receipt_id,
    );
    if (approval === null) {
      // The row says the approval was authored but the local chain mirror
      // cannot prove it (missing/unreadable/disagreeing events). Resuming
      // would either re-author (a second PIN, a second signed pair) or
      // sign a refund the projector will dead-letter — refuse instead.
      console.error('[refundCheckout] reused intent at approval_authored: approval evidence is not locally recoverable', {
        intentId: intent.id,
      });
      return { outcome: 'refuse', errorKey: 'refundFlow.checkout.errorApproval', showReconciliation: false };
    }
    return { outcome: 'proceed', approval };
  }

  // 'drafted' — nothing was signed; the ordinary path applies unchanged.
  return { outcome: 'proceed', approval: null };
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
  }

  // ── Wave-2 fix-wave, finding-11 verification (wave-3 analysis, "Minor,
  //    found while reading") — the intent MUST be at `approval_authored`
  //    before the settle, and this runs UNCONDITIONALLY.
  //
  //    This write used to sit inside the `approval === null` branch above
  //    with its failure swallowed as "best-effort local bookkeeping". But
  //    `markRefundEventAppended()` only transitions FROM
  //    `approval_authored`, so a failed write left the intent at `drafted`
  //    and every settle attempt threw INSIDE the write-gate transaction →
  //    full rollback. And because the call was inside that branch, once
  //    the approval was cached a retry never re-ran it: a permanent,
  //    silent rollback loop for the rest of the session, with no
  //    operator-visible cause.
  //
  //    `ensureApprovalAuthored()` is idempotent (already-`approval_authored`
  //    is a re-read-confirmed no-op), so re-running it can never
  //    re-author fiscal events — the original reason for swallowing. A
  //    genuine failure now surfaces as a RETRYABLE approval error instead
  //    of falling through into a settle guaranteed to roll back.
  try {
    await ensureApprovalAuthored(db, refundIntent.id);
  } catch (markError) {
    if (get().epoch !== epoch) return;
    console.error(
      '[refundCheckout] could not advance refund_intents to approval_authored — refusing to attempt a settle that would roll back',
      markError,
    );
    set({
      step: 'approval',
      error: { key: 'refundFlow.checkout.errorApproval', serverMessage: null },
    });
    return;
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
      // Wave-2 fix-wave finding 7 (fiscal C-4 / codex M-1) — the ORIGINAL's
      // OWN signed business date, resolved from its envelope by
      // `resolveOriginalFiscalEventLocally()` and cross-checked against the
      // `fiscal_events` row. This slot previously carried
      // `input.approvalContext.businessDate` — TODAY — so a 1-July sale
      // refunded on 1 August signed `original_business_date: '2026-08-01'`
      // into the immutable chain. `approvalContext.businessDate` remains
      // correct for the NEW refund event's own `businessDate` above.
      originalBusinessDate: v4Original.businessDate,
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
