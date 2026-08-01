import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '@/lib/db';
import { getOfflineReceiptById } from '@/lib/db/repositories/offlineReceiptRepository';
import { validateSaleReceiptPayload } from '@/lib/fiscal/FiscalEventEngine';
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
  /**
   * Wave-2 fix-wave finding 7 (fiscal C-4 / codex M-1) — the ORIGINAL's
   * OWN signed `payload.business_date`, cross-checked against the
   * `fiscal_events` row's own column. Becomes
   * `original_receipt_reference.original_business_date`. Before this
   * field existed the store stamped the CURRENT business date there, so
   * a 1-July sale refunded on 1 August signed a payload asserting the
   * original was sold on 1 August — a fabricated provenance fact, sealed
   * forever (rule 8) and accepted by the server, which only shape-checks
   * the field.
   */
  readonly businessDate: string;
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
  //
  // Wave-2 fix-wave finding 2 (codex C-1): this used to select ONLY `id`.
  // Proving that a row EXISTS is not the same as reading THAT ROW's signed
  // bytes — every refusal-relevant value was then taken from the
  // `offline_receipts` mirror with nothing binding the two, so a stale,
  // mismatched or tampered mirror could authorize a refund whose
  // `training_flag` / `transaction_discount_amount` were never proved to
  // belong to the referenced signed event. The signed columns are now
  // pulled and every one of them is checked below; the mirror is used
  // ONLY after byte-equality with this row is established.
  const fiscalEventRow = await queryOne<{
    id: string;
    event_type: string;
    event_version: number;
    business_date: string;
    terminal_id: string;
    canonical_bytes: string;
  }>(
    db,
    `SELECT id, event_type, event_version, business_date, terminal_id, canonical_bytes
       FROM fiscal_events
      WHERE source_event_class = 'offline_receipts' AND source_event_id = $1
      LIMIT 1`,
    [originalReceiptUuid],
  );
  if (fiscalEventRow === null) {
    return null;
  }

  // -- Envelope identity. A refund's original can only ever be a
  //    SALE_RECEIPT; anything else (an approval, an override, a Z leg)
  //    is a resolution bug, not a refundable original.
  if (fiscalEventRow.event_type !== 'SALE_RECEIPT') {
    return null;
  }
  if (
    typeof fiscalEventRow.canonical_bytes !== 'string'
    || fiscalEventRow.canonical_bytes === ''
  ) {
    return null;
  }

  // -- Byte equality: the mirror this function reads from MUST be the very
  //    same string the chain signed. `receiptService.ts` writes the append
  //    result's `canonical_bytes` into BOTH tables, so equality is the
  //    normal case; inequality means the mirror drifted (partial write,
  //    restore, tampering) and nothing read out of it can be trusted.
  if (receipt.canonical_bytes !== fiscalEventRow.canonical_bytes) {
    return null;
  }

  // Wave-2 review fix (FISCAL CRITICAL) — `offline_receipts.canonical_bytes`
  // (the SAME string `fiscal_events.canonical_bytes` holds) is the full
  // chain ENVELOPE `FiscalEventEngine.ts` signs (`canonicalPayload`,
  // :620-637) — tenant_id/terminal_id/sequence_number/etc alongside the
  // ACTUAL signed fiscal payload nested one level down at
  // `envelope.payload`. A previous version of this function read
  // `training_flag`/`transaction_discount_amount`/`line_items` directly
  // off the ENVELOPE's top level, where none of those keys exist — every
  // read silently missed and fell back to a PERMISSIVE default
  // (`trainingFlag=false`, `transactionDiscountAmount='0'`, `lineItems=[]`),
  // meaning the §3.5/§3.7 device refusals could NEVER fire. Every failure
  // mode below is FAIL-CLOSED (returns `null`, the caller refuses the
  // refund) rather than permissive-default: a refusal check that cannot
  // prove its negative must never assume the safe case.
  let envelope: unknown;
  try {
    envelope = JSON.parse(receipt.canonical_bytes);
  } catch {
    return null;
  }
  if (!isPlainRecord(envelope)) {
    return null;
  }
  const payload = envelope['payload'];
  if (!isPlainRecord(payload)) {
    return null;
  }

  // -- Wave-2 fix-wave finding 2 — validate the signed payload with the
  //    CANONICAL validator (the SAME one `FiscalEventEngine.append()` ran
  //    when this original was authored), not a locally re-invented set of
  //    shape checks. This is what makes the array members below genuinely
  //    validated (`line_items[i]`/`payments[i]` key sets, money regexes,
  //    enums) instead of `Array.isArray()`-and-hope. Any structural
  //    violation is a fail-closed refusal, exactly like malformed JSON.
  try {
    validateSaleReceiptPayload(payload, fiscalEventRow.event_version);
  } catch {
    return null;
  }

  // -- Receipt identity: the signed payload must be about the receipt the
  //    caller asked to refund. Byte equality alone proves the mirror and
  //    the chain agree; it does not prove they are about THIS receipt.
  if (payload['receipt_uuid'] !== originalReceiptUuid) {
    return null;
  }

  // -- Discriminator (§2): only a SALE (or the ruled TRAINING
  //    representation, which §3.7 then refuses on its own signed
  //    `training_flag`) can be the ORIGINAL of a refund. A REFUND or VOID
  //    original is structurally meaningless — fail closed rather than
  //    guessing a supported shape.
  const invoiceTypeCode = payload['invoice_type_code'];
  if (invoiceTypeCode !== 'SALE' && invoiceTypeCode !== 'TRAINING') {
    return null;
  }

  // -- Business date: read from the signed payload (finding 7) and
  //    cross-checked against the envelope row's own column so the two
  //    cannot disagree.
  const businessDate = payload['business_date'];
  if (typeof businessDate !== 'string' || businessDate === '') {
    return null;
  }
  if (businessDate !== fiscalEventRow.business_date) {
    return null;
  }

  const lineItems = payload['line_items'] as LineItemInput[];
  const payments = payload['payments'] as PaymentInput[];
  const trainingFlag = payload['training_flag'] as boolean;
  const transactionDiscountAmount = payload['transaction_discount_amount'] as string;

  return {
    fiscalEventId: fiscalEventRow.id,
    businessDate,
    lineItems,
    payments,
    trainingFlag,
    transactionDiscountAmount,
  };
}

function isPlainRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
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
