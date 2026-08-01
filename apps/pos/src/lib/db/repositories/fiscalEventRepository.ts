import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '@/lib/db';
import { getOfflineReceiptById } from '@/lib/db/repositories/offlineReceiptRepository';
import type { LineItemInput, PaymentInput } from '@/lib/fiscal/FiscalEventEngine';

export type FiscalEventSyncStatus = 'pending' | 'syncing' | 'synced' | 'failed';

export interface LocalFiscalEvent {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: string;
  event_version: number;
  signature_version: string;
  sequence_number: number;
  event_time_device: string;
  business_date: string;
  chain_context: string;
  last_server_time_seen: string | null;
  reference_event_id: string | null;
  reference_document_id: string | null;
  source_event_class: string | null;
  source_event_id: string | null;
  canonical_bytes: string;
  previous_hash: string;
  current_hash: string;
  sync_status: FiscalEventSyncStatus;
  sync_error: string | null;
  created_at: string;
  synced_at: string | null;
}

export interface FiscalEventWireEnvelope {
  envelope_id: string;
  type: 'FISCAL_EVENT';
  payload_version: number;
  idempotency_key: string;
  payload: {
    id: string;
    tenant_id: string;
    company_id: string;
    terminal_id: string;
    operator_id: string;
    event_type: string;
    event_version: number;
    signature_version: string;
    sequence_number: number;
    event_time_device: string;
    business_date: string;
    chain_context: string;
    last_server_time_seen: string | null;
    reference_event_id: string | null;
    reference_document_id: string | null;
    source_event_class: string | null;
    source_event_id: string | null;
    previous_hash: string;
    current_hash: string;
    canonical_bytes: string;
  };
}

export interface FiscalEventSyncResultItem {
  stored: boolean;
  fiscal_event_id: string | null;
  sequence_conflict: boolean;
  exception_class: string | null;
}

export interface FiscalEventSyncBatchResponse {
  results: FiscalEventSyncResultItem[];
}

export const MAX_FISCAL_EVENT_SYNC_RETRIES = 5;

export async function getPendingFiscalEventsForSync(
  db: Database,
): Promise<LocalFiscalEvent[]> {
  return queryAll<LocalFiscalEvent>(
    db,
    `SELECT id, tenant_id, company_id, terminal_id, operator_id,
            event_type, event_version, signature_version, sequence_number,
            event_time_device, business_date, chain_context, last_server_time_seen,
            reference_event_id, reference_document_id, source_event_class,
            source_event_id, canonical_bytes, previous_hash, current_hash,
            sync_status, sync_error, created_at, synced_at
       FROM fiscal_events
      WHERE sync_status IN ('pending', 'failed')
      ORDER BY chain_context ASC, sequence_number ASC`,
  );
}

/**
 * Demote fiscal events stranded at `syncing` by a crash / process kill between
 * the local status update and the HTTP response handler.
 *
 * Safe re-delivery relies on the fiscal-event idempotency contract: if the
 * server already stored the event, the retry returns the existing event id and
 * the client advances the local row to `synced`.
 */
export async function recoverStrandedSyncingFiscalEvents(
  db: Database,
): Promise<number> {
  const result = await execute(
    db,
    "UPDATE fiscal_events SET sync_status = 'pending', sync_error = NULL WHERE sync_status = 'syncing'",
  );
  return result.rowsAffected;
}

export async function updateFiscalEventSyncStatus(
  db: Database,
  id: string,
  status: FiscalEventSyncStatus,
  syncError?: string,
): Promise<void> {
  if (status === 'synced') {
    await execute(
      db,
      "UPDATE fiscal_events SET sync_status = $1, synced_at = datetime('now'), sync_error = NULL WHERE id = $2",
      [status, id],
    );
    return;
  }

  await execute(
    db,
    'UPDATE fiscal_events SET sync_status = $1, sync_error = $2 WHERE id = $3',
    [status, syncError ?? null, id],
  );
}

/**
 * v3-refund-chain-integration spec §3.5/§3.7/§4.1 — the ORIGINAL sale's own
 * signed fields the device-side refund flow refuses against BEFORE any
 * approval authoring or payload construction: `line_items[]`/`payments[]`
 * (for `RefundReceiptV4Payload.ts`'s §3.2 normalization + §3.3 parallel-
 * array construction), `training_flag` (§3.7's training-original refusal),
 * `transaction_discount_amount` (§3.5's whole-discount-receipt refusal).
 */
export interface OriginalFiscalEventLocalView {
  /** The original's own local `fiscal_events.id` — becomes
   *  `original_receipt_reference.fiscal_event_id` on the refund payload. */
  readonly fiscalEventId: string;
  readonly lineItems: readonly LineItemInput[];
  readonly payments: readonly PaymentInput[];
  /** Read from the original's OWN signed `payload.training_flag` — never
   *  the CURRENT session's training-mode context (an unrelated concept,
   *  §3.7). Named `original.training_flag` at every call site to keep the
   *  source unambiguous. */
  readonly trainingFlag: boolean;
  readonly transactionDiscountAmount: string;
}

/**
 * Resolve the ORIGINAL sale's own signed fiscal-event fields, purely from
 * local SQLite — no server round trip. `offline_receipts.id` is the SAME
 * UUID as the fiscal payload's own `receipt_uuid` (both are `receiptId`,
 * generated once at sale-authoring time and threaded to both the
 * `fiscal_events` append call and the `offline_receipts` insert,
 * `receiptService.ts`), so `originalReceiptUuid` is exactly
 * `offline_receipts.id` — the same key `getOfflineReceiptById()` already
 * uses for the existing `hydrateFromReceipt()` call site
 * (`HomePage.tsx`'s `getOfflineReceiptById(db, event.receiptUuid)`).
 *
 * Returns `null` when the original cannot be resolved locally (already
 * synced-and-pruned from this device, or genuinely never existed here) —
 * the caller refuses the refund attempt rather than guessing.
 */
export async function resolveOriginalFiscalEventLocally(
  db: Database,
  originalReceiptUuid: string,
): Promise<OriginalFiscalEventLocalView | null> {
  const receipt = await getOfflineReceiptById(db, originalReceiptUuid);
  if (receipt === null || receipt.canonical_bytes == null || receipt.canonical_bytes === '') {
    return null;
  }

  // The original's OWN local fiscal_events row -- linked via the SAME
  // source_event_class/source_event_id pair `receiptService.ts` writes at
  // sale-authoring time (`source_event_class: 'offline_receipts',
  // source_event_id: receiptId`).
  const fiscalEventRow = await queryOne<{ id: string }>(
    db,
    `SELECT id FROM fiscal_events
      WHERE source_event_class = 'offline_receipts' AND source_event_id = $1
      LIMIT 1`,
    [originalReceiptUuid],
  );
  if (fiscalEventRow === null) {
    return null;
  }

  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(receipt.canonical_bytes) as Record<string, unknown>;
  } catch {
    return null;
  }

  const lineItems = Array.isArray(payload['line_items'])
    ? (payload['line_items'] as LineItemInput[])
    : [];
  const payments = Array.isArray(payload['payments'])
    ? (payload['payments'] as PaymentInput[])
    : [];
  const transactionDiscountAmount =
    typeof payload['transaction_discount_amount'] === 'string'
      ? payload['transaction_discount_amount']
      : '0';

  return {
    fiscalEventId: fiscalEventRow.id,
    lineItems,
    payments,
    trainingFlag: payload['training_flag'] === true,
    transactionDiscountAmount,
  };
}

export function fiscalEventToWireEnvelope(
  event: LocalFiscalEvent,
): FiscalEventWireEnvelope {
  return {
    envelope_id: event.id,
    type: 'FISCAL_EVENT',
    payload_version: 1,
    idempotency_key: `${event.terminal_id}:${event.chain_context}:${String(event.sequence_number)}`,
    payload: {
      id: event.id,
      tenant_id: event.tenant_id,
      company_id: event.company_id,
      terminal_id: event.terminal_id,
      operator_id: event.operator_id,
      event_type: event.event_type,
      event_version: event.event_version,
      signature_version: event.signature_version,
      sequence_number: event.sequence_number,
      event_time_device: event.event_time_device,
      business_date: event.business_date,
      chain_context: event.chain_context,
      last_server_time_seen: event.last_server_time_seen,
      reference_event_id: event.reference_event_id,
      reference_document_id: event.reference_document_id,
      source_event_class: event.source_event_class,
      source_event_id: event.source_event_id,
      previous_hash: event.previous_hash,
      current_hash: event.current_hash,
      canonical_bytes: event.canonical_bytes,
    },
  };
}
