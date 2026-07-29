import { useCartStore } from '@/stores/cartStore';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { usePaymentPolicyStore } from '@/stores/paymentPolicyStore';
import { useProductStore } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';

/**
 * Clear all POS session stores that a full device sign-out must reset.
 * Mirrors the cleanup formerly inline in Header.handleLogout (Sub-Spec B).
 * Kept out of authStore to avoid an operatorStore<->authStore import cycle.
 */
export function teardownPosSessionStores(): void {
  useCartStore.getState().clearCart('operator_switch');
  useRefundFlowStore.getState().clearAll();
  useRefundDraftStore.getState().clearDraftState();
  usePaymentStore.getState().clearVoucherTenders();
  usePaymentStore.getState().reset();
  // The policy slice is a global singleton while the SQLite cache is
  // per-company. Signing out is the entry point to a company switch
  // (LoginPage -> setCompany), so the previous company's cash-rounding
  // denomination and tolerance caps must not survive it — offline, terminal
  // activation's `hydratePaymentPolicyFromCache` is the only thing that would
  // reload them, and it can only clear what it finds.
  usePaymentPolicyStore.getState().reset();
  useProductStore.getState().reset();
  useOperatorStore.getState().clearOperator();
}
