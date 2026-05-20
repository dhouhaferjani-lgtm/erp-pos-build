import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll } from '@/lib/db';

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
            event_time_device, business_date, last_server_time_seen,
            reference_event_id, reference_document_id, source_event_class,
            source_event_id, canonical_bytes, previous_hash, current_hash,
            sync_status, sync_error, created_at, synced_at
       FROM fiscal_events
      WHERE sync_status IN ('pending', 'failed')
      ORDER BY sequence_number ASC`,
  );
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

export function fiscalEventToWireEnvelope(
  event: LocalFiscalEvent,
): FiscalEventWireEnvelope {
  return {
    envelope_id: event.id,
    type: 'FISCAL_EVENT',
    payload_version: 1,
    idempotency_key: `${event.terminal_id}:${String(event.sequence_number)}`,
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
